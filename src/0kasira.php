<?php

// Consumer key and secret
const CK = '3nVuSoBZnx6U4vzUxf5w';
const CS = 'Bcs59EFbbsdF6Sl9Ng71smgStWEGwXXKSjYvPVt7qys';

/**
 * prompt()
 */
function prompt($message = 'prompt: ', $hidden = false) {
    if (PHP_SAPI !== 'cli') {
        return false;
    }
    echo $message;
    $ret = 
        $hidden
        ? exec(
            PHP_OS === 'WINNT' || PHP_OS === 'WIN32'
            ? __DIR__ . '\prompt_win.bat'
            : 'read -s PW; echo $PW'
        )
        : rtrim(fgets(STDIN), PHP_EOL)
    ;
    if ($hidden) {
        echo PHP_EOL;
    }
    return $ret;
}

/**
 * println()
 */
function println($message = '') {
    echo $message . PHP_EOL;
}

// Allow only CLI
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    println('This script is only for CLI.');
    exit;
}

// Quickly install and load TwistOAuth
try {
    if (!is_file(__DIR__ . '/TwistOAuth.phar')) {
        call_user_func(function () {
            switch (true) {
                case !$local = @fopen(__DIR__ . '/TwistOAuth.phar', 'wb'):
                case !$remote = @fopen('https://raw.githubusercontent.com/mpyw/TwistOAuth/master/build/TwistOAuth.phar', 'rb'):
                case !@stream_copy_to_stream($remote, $local):
                    $error = error_get_last();
                    throw new Exception($error['message']);
            }
        });
    }
    require __DIR__ . '/TwistOAuth.phar';
} catch (Exception $e) {
    println('Failed to install TwistOAuth.');
    exit;
}

// Disable time limit
set_time_limit(0);

// Disable buffering
while (ob_get_level()) {
    ob_end_clean();
}

// Login
while (true) {
    try {
        $to = (new TwistOAuth(CK, CS))->renewWithAccessTokenX(
            prompt('What is your screen_name?: '),
            prompt('What is your password?: ', true)
        );
        break;
    } catch (Exception $e) {
        println($e->getMessage());
        println('Please retry...');
    }
}

// Target
while (true) {
    try {
        println('Whose pictures do you want?');
        println('Input comma-separated screen_name.');
        println('Default value is "0kasira".');
        $sns = array_keys(array_change_key_case(array_flip(preg_split(
            '/[^\w]++/',
            prompt('Target screen_name: '),
            -1,
            PREG_SPLIT_NO_EMPTY
        ))));
        if (!$sns) {
            $sns[] = '0kasira';
        }
        $ids = array_map(
            function ($user) { return $user->id_str; },
            $to->get('users/lookup', ['screen_name' => implode(',', $sns)])
        );
        if (!$ids) {
            throw new Exception('There are no valid ids.');
        }
        break;
    } catch (Exception $e) {
        println($e->getMessage());
        println('Please retry...');
    }
}

// Location
while (true) {
    try {
        $dir = prompt('Where do you want to save?: ');
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new Exception('Invalid path.');
        }
        break;
    } catch (Exception $e) {
        println($e->getMessage());
        println('Please retry...');
    }
}

// Streaming
println('Streaming started.');
while (true) {
    try {
        $endpoint = 'statuses/filter';
        $callback = function ($status) use ($to, $dir) {
            switch (true) {
                case empty($status->extended_entities->media):
                case isset($status->retweeted_status):
                    return;
            }
            foreach ($status->extended_entities->media as $media) {
                try {
                    if ($media->type !== 'photo') {
                        continue;
                    }
                    $data     = $to->get($media->media_url)->data;
                    $filename = sha1($data) . '.' . pathinfo($media->media_url, PATHINFO_EXTENSION);
                    if (!@file_put_contents($dir . DIRECTORY_SEPARATOR . $filename, $data)) {
                        throw new Exception(error_get_last()['message']);
                    };
                    println('Saved: ' . $media->media_url);
                } catch (Exception $e) {
                    println($e->getMessage());
                    println('Failed to save: ' . $media->media_url);
                }
            }
        };
        $params = ['follow' => implode(',', $ids)];
        $to->streaming($endpoint, $callback, $params);
    } catch (Exception $e) {
        println('Disconnected!!');
        println('Waiting 20 seconds...');
        sleep(20);
        println('Retrying...');
    }
}