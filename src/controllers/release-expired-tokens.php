<?php

/**
 * Releases all expired tokens
 *
 * @return array{code: string, body: string}
 */
function release_expired_tokens(): array {
    // Retrieve the tokens
    $token_files = glob(BASE_DIR . '/tokens/*.json');

    if(empty($token_files)) {
        return [
            'code' => 200,
            'body' => "no_tokens_to_release"
        ];
    }

    // Find the expired tokens
    $map_expired_tokens = [];
    foreach($token_files as $token_file) {
        $token = json_decode(file_get_contents($token_file), true);

        $one_hour = 3600;
        $token_validity_in_seconds = (int) (getenv('TOKEN_VALIDITY') ?: $one_hour);
        if(strtotime($token['created_at']) + $token_validity_in_seconds <= time()) {
            $map_expired_tokens[$token_file] = $token;
        }
    }

    if(empty($map_expired_tokens)) {
        return [
            'code' => 200,
            'body' => "no_tokens_to_release"
        ];
    }

    foreach($map_expired_tokens as $token_file => $token) {
        // Remove temporary user for FTP access
        exec("userdel {$token['instance']}");

        // Remove token
        unlink($token_file);
    }

    return [
        'code' => 200,
        'body' => [ 'result' => 'expired_tokens_released' ]
    ];
}
