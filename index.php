<html>
<head>
<title>Slack channel</title>
<link href="style.css" rel="stylesheet" />
</head>
<body>

<?php

$channelCacheFilename = '.channel-cache.tmp.json';
$userlistCacheFilename = '.users-cache.tmp.json';
$emojiCacheFilename = '.emoji-cache.tmp.json';
$channelCacheTimeout = 1;
$userlistCacheTimeout = 300;
$emojiCacheTimeout = 3600;

include_once('config.php');

function slack_api_request ($apiPath, $postFields) {
    global $slackApiToken;

    $postFields['token'] = $slackApiToken;
    
    $ch = curl_init('https://slack.com/api/' . $apiPath);
    $data = http_build_query($postFields);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($result, true);

    if ($result['ok'] == '1') {
        return $result;
    }
    
    print_r($result);
    die('Could not execute request ' . $apiPath);
}

function read_from_cache($cacheFilename, $cacheTimeout) {
    $lastModified = @filemtime($cacheFilename);
    if (!$lastModified) {
        return null;
    }

    if (time() - $lastModified > $cacheTimeout) {
        return null;
    }

    return json_decode(file_get_contents($cacheFilename), true);
}

function write_to_cache($cacheFilename, $cacheThisObject) {
    $f =  fopen($cacheFilename, 'w');
    fwrite($f, json_encode($cacheThisObject, JSON_PRETTY_PRINT));
    fclose($f);
}

function get_channel_history($channelId)
{
    global $channelCacheFilename;
    global $channelCacheTimeout;


    $channel_history = read_from_cache($channelCacheFilename, $channelCacheTimeout);
    if ($channel_history) {
        return $channel_history;
    }

    $has_more = true;
    $channel_history = [];
    $fetch_from_ts = time();

    while ($has_more && count($channel_history) < 300) {
        $h = slack_api_request('channels.history', [
            'channel' => $channelId,
            'limit' => 100,
            'latest' => $fetch_from_ts,
        ]);

        $channel_history = array_merge($channel_history, $h['messages']);
        
        $has_more = $h['has_more'];
        $fetch_from_ts = array_slice($h['messages'], -1)[0]['ts'];
    }

    write_to_cache($channelCacheFilename, $channel_history);

    return $channel_history;
}

function get_all_emojis()
{
    global $emojiCacheFilename;
    global $emojiCacheTimeout;

    $all_emojis = read_from_cache($emojiCacheFilename, $emojiCacheTimeout);
    if ($all_emojis) {
        return $all_emojis;
    }

    $all_emojis = slack_api_request('emoji.list', [
        "channel" => $channelId,
    ]);

    $all_emojis = $all_emojis['emoji'];

    $standard_emojis = json_decode(file_get_contents("emojis.json"), true);
    foreach($standard_emojis as $e) {
        foreach($e['short_names'] as $short_name) {
            $us = explode('-', $e['unified']);
            $full_unicode = '';
            foreach ($us as $u) {
                $full_unicode .= '&#x' . $u . ';';
            }

            $all_emojis[$short_name] = $full_unicode;
        }
    }

    $all['slightly_smiling_face'] = 'alias:wink';
    // $all['emojis']['upside_down_face'] = 'alias:upside_down_face';

    write_to_cache($emojiCacheFilename, $all_emojis);

    return $all_emojis;
}

function get_all_users()
{
    global $userlistCacheFilename;
    global $userlistCacheTimeout;

    $userlist = read_from_cache($userlistCacheFilename, $userlistCacheTimeout);
    if ($userlist) {
        return $userlist;
    }

    $userlist = slack_api_request('users.list', [
        'limit' => 800,
        'presence' => false,
    ]);

    // Format in more sane way
    $userlistIndexed = [];
    foreach ($userlist['members'] as $user) {
        $userlistIndexed[$user['id']] = $user;
    }

    write_to_cache($userlistCacheFilename, $userlistIndexed);
    
    return $userlistIndexed;
}

$channel_history = get_channel_history($slackChannelId);
$user_list = get_all_users();
$all_emojis = get_all_emojis();

function user_id_to_name($userId) {
    global $user_list;
    $user = $user_list[$userId];
    if ($user) {
        return $user['real_name'] ? $user['real_name'] : $user['name'];
    }
    else {
        return 'Unknown';
    }
}

function coloncode_to_emoji($coloncode) {
    global $all_emojis;

    $emoji = $all_emojis[$coloncode];
    if ($emoji) {
        if (substr($emoji, 0, 8) == 'https://') {
            return '<img class="emoji" src="' . $emoji . '" title="' . $coloncode . '">';
        }

        if (substr($emoji, 0, 6) == 'alias:') {
            return coloncode_to_emoji(substr($coloncode, 6));
        }
        
        return $emoji;

    }

    return ':' . $coloncode . ':'; 
}

function render_user_message($message, $user) {
    $html .= '<div class="slack-message">';

    if ($message['parent_user_id']) {
        return '';
    }

    $html .= '<img class="avatar" src="' . $user['profile']['image_48'] . '" aria-hidden="true" title="">';

    $html .= '<div class="content">';

    $html .= '<strong class="username">' . user_id_to_name($user['id']) . '</strong> ';
    
    $html .= '<small class="timestamp"><time datetime="' . date('Y-m-d H:i', $message['ts']) . '">' . date('H:i d.m', $message['ts']) . '</time></small>';

    $text = $message['text'];
    
    $text = preg_replace_callback(
        '/<@([a-zA-Z0-9]+)>/',
        function ($matches) {
            return user_id_to_name($matches[1]);
        },
        $text
    );
    
    $text = preg_replace_callback(
        '/:([a-zA-Z0-9_]+):/',
        function ($matches) {
            return coloncode_to_emoji($matches[1]);
        },
        $text
    );
    
    $text = preg_replace_callback(
        '/<(https?:\/\/.+)>/',
        function ($matches) {
            return ' <a href="' . $matches['1'] . '" target="_blank">' . $matches[1] . '</a> ';
        },
        $text
    );

    $text = preg_replace(
        '/<#[a-zA-Z0-9]+\|([a-z0-9\-_]+)>/',
        '#$1',
        $text
    );
    
    $html .= '<div class="message">' . $text . '</div>';
    
    if (isset($message['reactions'])) {
        foreach ($message['reactions'] as $r) {
            $html .= '<span class="reaction"><i>' . coloncode_to_emoji($r['name']) . '</i> <small>' . $r['count'] . '</small>' . '</span>';
        }
    }

    $html .= '<!-- ' . json_encode($message, JSON_PRETTY_PRINT) . ' -->';

    // $html .= '<pre class="raw">' . json_encode($message, JSON_PRETTY_PRINT) . '</pre>';

    $html .= '</div>'; // .message
    $html .= '</div>'; // .slack-message

    return $html;

}

function render_message($message, $user_list) {
    $html = '';

    switch ($message['type']) {
        case 'message':
            if (empty($message['subtype'])) {
                return render_user_message($message, $user_list[$message['user']]);                
            }

            switch($message['subtype']) {

                case 'channel_join':
                default:
                    return;
            }
            
        default:
            return;
    }
}


foreach ($channel_history as $message) {
    echo render_message($message, $user_list);
}
?>
</body>
</html>