<?php
include_once './boot.lib.php';

$request = [
    'method'        => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'uri'           => $_SERVER['REQUEST_URI'] ?? '/',
    'content_type'  => $_SERVER['CONTENT_TYPE'] ?? 'application/json',
    'data'          => file_get_contents("php://input"),
];

$routes = [
    'GET' => [
        '/status',                  /* @link status() */
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

trigger_error('result: '.serialize($body), E_USER_NOTICE);

send_http_response($body, $code);
