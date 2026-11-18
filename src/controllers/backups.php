<?php
/*
    This file is part of the K2 package <http://github.com/yesbabylon/k2>
    Some Rights Reserved, Yesbabylon, 2026
    Licensed under MIT License <https://opensource.org/licenses/MIT>
*/

/**
 * Returns all backups, grouped by instance.
 *
 * @return array{code: int, body: array<string, string[]>}
 * @throws Exception
 */
function backups(): array {
    $backups_path = getenv('BACKUPS_PATH') ?: false;
    if(!$backups_path) {
        throw new Exception("BACKUPS_PATH_not_configured", 500);
    }

    // Remove ending / if present
    if($backups_path[strlen($backups_path) - 1] === '/') {
        $backups_path = substr($backups_path, 0, -1);
    }

    if(!is_dir($backups_path)) {
        throw new Exception("BACKUPS_PATH_invalid", 500);
    }

    $instances = scandir($backups_path);
    if($instances === false) {
        throw new Exception("backups_not_found", 404);
    }

    $backups = [];
    foreach(array_diff($instances, ['.', '..']) as $instance) {
        $instance_backups_path = $backups_path.'/'.$instance;
        if(!is_dir($instance_backups_path)) {
            continue;
        }

        $instance_backups = scandir($instance_backups_path);
        if($instance_backups === false) {
            continue;
        }

        $backups[$instance] = array_values(array_diff($instance_backups, ['.', '..']));
    }

    return [
        'code' => 200,
        'body' => $backups
    ];
}
