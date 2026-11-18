<?php
/*
    This file is part of the K2 package <http://github.com/yesbabylon/k2>
    Some Rights Reserved, Yesbabylon, 2026
    Licensed under MIT License <https://opensource.org/licenses/MIT>
*/

include_once './boot.lib.php';

const HOST_SECRET_FILE = '/root/k2/secrets/host.secrets';

enforce_security();

$request = [
    'method'        => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'uri'           => $_SERVER['REQUEST_URI'] ?? '/',
    'content_type'  => $_SERVER['CONTENT_TYPE'] ?? 'application/json',
    'data'          => file_get_contents("php://input"),
];

$routes = [
    'GET' => [
        '/status',                  /* @link status() */
        '/backups',                 /* @link backups() */
        '/instance/backups',        /* @link instance_backups() */
    ],
    'POST' => [
        '/release-expired-tokens',  /* @link release_expired_tokens() */
        '/remove-expired-backups',  /* @link remove_expired_backups() */
        '/instance/create-token',   /* @link instance_create_token() */
        '/instance/release-token'   /* @link instance_release_token() */
    ]
];

['body' => $body, 'code' => $code] = handle_request($request, $routes);

trigger_error('result: '.serialize((array) $body), E_USER_NOTICE);

send_http_response($body, $code);

function enforce_security() {
    is_readable(HOST_SECRET_FILE) && check_basic_auth();
}

function check_basic_auth() {
    [$user, $pass] = parse_basic_auth();

    if($user !== 'root' || !check_password($pass)) {
        send_http_response(
            ['error' => 'Unauthorized'],
            401,
            ['WWW-Authenticate' => 'Basic realm="host-api"']
        );
        exit;
    }
}

function check_password($password) {
    static $hash = null;

    if($hash === null) {
        if(!is_readable(HOST_SECRET_FILE)) {
            return false;
        }
        $hash = trim(file_get_contents(HOST_SECRET_FILE));
    }

    return $hash !== '' && password_verify($password, $hash);
}

function parse_basic_auth(): array {
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);

    if(!isset($headers['authorization'])) {
        return [null, null];
    }

    if(!preg_match('/^Basic\s+(.*)$/i', $headers['authorization'], $matches)) {
        return [null, null];
    }

    $decoded = base64_decode($matches[1], true);

    if($decoded === false || strpos($decoded, ':') === false) {
        return [null, null];
    }

    return explode(':', $decoded, 2);
}
