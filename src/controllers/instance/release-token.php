<?php

/**
 * Releases the backup token of a given instance.
 *
 * @param array{instance: string, token: string} $data
 * @return array{code: int, body: string}
 * @throws Exception
 */
function instance_release_token(array $data): array {
    if(!isset($data['instance'])) {
        throw new InvalidArgumentException("missing_instance", 400);
    }

    $domain_name_pattern = '/^(?!\-)(?:[a-zA-Z0-9\-]{1,63}\.)+[a-zA-Z]{2,}$/';
    
    if(
        !is_string($data['instance']) || empty($data['instance']) || strlen($data['instance']) > 32
        || preg_match($domain_name_pattern, $data['instance']) === 0
        || $data['instance'] !== basename($data['instance'])
    ) {
        throw new InvalidArgumentException("invalid_instance", 400);
    }

    if(!isset($data['token'])) {
        throw new InvalidArgumentException("missing_token", 400);
    }

    $token_file = BASE_DIR . '/tokens/' . $data['instance'] . '.json';

    if(
        !is_string($data['token']) || strlen($data['token']) !== 32
        || !file_exists($token_file)
    ) {
        throw new InvalidArgumentException("invalid_token", 400);
    }

    $token_data_json = file_get_contents($token_file);
    $token_data = json_decode($token_data_json, true);

    if($token_data['token'] !== $data['token']) {
        throw new InvalidArgumentException("invalid_token", 400);
    }

    // Remove system user with no shell access (for FTP use) (keep home directory)
    $username = $data['instance'];
    exec("userdel $username");

    // Remove token file
    unlink($token_file);

    return [
        'code' => 200,
        'body' => "token_released"
    ];
}
