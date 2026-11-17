<?php
/*
    This file is part of the K2 package <http://github.com/yesbabylon/k2>
    Some Rights Reserved, Yesbabylon, 2026
    Licensed under MIT License <https://opensource.org/licenses/MIT>
*/

/**
 * Returns host status statistics.
 *
 * Required system binaries:
 *
 * standard: ps, awk, grep, head, tail, cut, wc, free, vmstat, df, w, hostname, xargs, cat
 * extra: ip, vnstat, iptables, hostnamectl
 *
 * Notes:
 * - hostnamectl requires systemd
 * - vnstat is required for network statistics
 * - iptables is required for firewall inspection
 *
 * @param array{scope: ?string, scopes: ?string[]} $data
 *
 * @return array{
 *     code: int,
 *     body: array{
 *         type: string,                    // Host type ("k2").
 *         instant: array{
 *             total_proc: int,             // Total number of running processes.
 *             ram_use: string,             // RAM usage as a percentage.
 *             cpu_use: string,             // CPU usage as a percentage.
 *             dsk_use: string,             // Disk usage as a percentage.
 *             usr_active: int,             // Number of logged-in users.
 *             usr_total: int,              // Total number of system users.
 *             backup_tokens_qty: string    // Qantity of backup tokens.
 *         },
 *         state: array{
 *             uptime: string,              // Uptime in days (e.g., "3days").
 *             net: string,                 // Network usage metrics.
 *             cpu: string,                 // Average CPU load since the last reboot.
 *             backups_disk: string         // Backup disk name.
 *         },
 *         config: array{
 *             type: string,                // Host type ("k2").
 *             host: string,                // Hostname of the server.
 *             platform_ver: string,        // Operating system version.
 *             kernel_ver: string,          // Kernel version.
 *             mem: string,                 // Total memory available.
 *             cpu_qty: int,                // Number of CPU cores.
 *             cpu_freq: string,            // CPU frequency (e.g., "3.2GHz").
 *             disk: string,                // Total disk space available.
 *             ip_private: string           // Private VLAN IP address.
 *         }
 *     }
 * }
 * @throws Exception
 */
function status(): array {
    // Retrieve interface (usually either eth0 or ens3)
    $interface = exec_status_cmd('ip link show | head -3 | tail -1 | awk \'{print $2}\'');
    if(!$interface) {
        throw new Exception("unable_to_retrieve_main_interface", 500);
    }

    $backups_path = getenv('BACKUPS_PATH') ?: false;
    if(!$backups_path) {
        throw new Exception("BACKUPS_PATH_not_configured", 500);
    }

    $interface = trim($interface, ':');

    $commands = [
        'instant' => [
            'total_proc' => [
                'description' => "total number of running processes",
                'command'     => 'ps aux | head -n -1 | wc -l',
                'adapt'       => function ($res) {
                    return $res;
                }
            ],
            'ram_use' => [
                'description' => "used RAM (Bytes)",
                'command'     => 'free -mh |awk \'/Mem/{print $3}\'',
                'adapt'       => function ($res) {
                    return adapt_unit($res);
                }
            ],
            'cpu_use' => [
                'description' => "used CPU (%)",
                'command'     => 'top -bn2 -d 0.1 | grep "Cpu" | tail -1 | awk \'{print $2}\'',
                'adapt'       => function ($res) {
                    return $res.'%';
                }
            ],
            'dsk_use' => [
                'description' => "consumed disk space",
                'command'     => 'df . -h | tail -1 | awk \'{print $3}\'',
                'adapt'       => function ($res) {
                    return adapt_unit($res);
                }
            ],
            'usr_active' => [
                'description' => "total number of logged in users",
                'command'     => 'w -h  | wc -l',
                'adapt'       => function ($res) {
                    return $res;
                }
            ],
            'usr_total' => [
                'description' => "total number of logged in users",
                'command'     => 'awk -F\':\' \'$3 >= 1000 && $3 < 60000 {print $1}\' /etc/passwd | wc -l',
                'adapt'       => function ($res) {
                    return $res;
                }
            ],
            'backup_tokens_qty' => [
                'description' => "total number of currently issued backup tokens",
                'command'     => 'find '.BASE_DIR.'/tokens -type f ! -name ".gitignore" | wc -l',
                'adapt'       => function ($res) {
                    return $res;
                }
            ]
        ],
        'state' => [
            'uptime' => [
                'description' => "quantity of days passed since the server is up",
                'command'     => 'cat /proc/uptime | awk \'{print $1}\'',
                'adapt'       => function ($res) {
                    return (intval($res / 86400) + 1).'days';
                }
            ],
            'net' => [
                'description' => "monthly network volume",
                'command'     => 'vnstat -i '.$interface.' -m | tail -3 | head -1',
                'adapt'       => function ($res) {
                    if(strpos($res, '|') === false) {
                        return [
                            'rx'        => 'No data yet',
                            'tx'        => 'No data yet',
                            'total'     => 'No data yet',
                            'avg_rate'  => 'No data yet',
                        ];
                    }

                    $parts = preg_split('/\s{2,10}/', $res, 3);
                    $b = array_map('adapt_unit', array_map('trim', explode('|', $parts[2])));
                    return array_combine(['rx', 'tx', 'total', 'avg_rate'], $b);
                }
            ],
            'cpu' => [
                'description' => "average CPU load (%) since last reboot",
                'command'     => 'vmstat | tail -1| awk \'{print $15}\'',
                'adapt'       => function ($res) {
                    return (100 - intval($res)).'%';
                }
            ],
            'backups_disk' => [
                'description' => "percentage of usage backups disk",
                'command'     => 'df -h ' . $backups_path . ' | awk \'NR==2 {print $5}\'',
                'adapt'       => function ($res) {
                    return $res;
                }
            ]
        ],
        'config' => [
            'host' => [
                'description' => "host name",
                'command'     => 'hostname',
                'adapt'       => function ($res) {
                    return adapt_unit($res);
                }
            ],
            'platform_ver' => [
                'description' => "OS version",
                'command'     => 'hostnamectl | awk -F: \'/Operating System/ {print $2}\' | xargs',
                'adapt'       => function ($res) {
                    return $res;
                }
            ],
            'kernel_ver' => [
                'description' => "Linux kernel version",
                'command'     => 'hostnamectl | awk -F: \'/Kernel/ {print $2}\' | xargs',
                'adapt'       => function ($res) {
                    return $res;
                }
            ],
            'mem' => [
                'description' => "total RAM",
                'command'     => 'free -mh | awk \'/Mem/{print $2}\'',
                'adapt'       => function ($res) {
                    return adapt_unit($res);
                }
            ],
            'cpu_qty' => [
                'description' => "number of CPU (#)",
                'command'     => 'cat /proc/cpuinfo | grep processor | wc -l',
                'adapt'       => function ($res) {
                    return $res;
                }
            ],
            'cpu_freq' => [
                'description' => "CPU frequency (MHz)",
                'command'     => 'cat /proc/cpuinfo | grep -m1 MHz | awk \'{ print $4 }\'',
                'adapt'       => function ($res) {
                    $res = floatval($res);
                    if($res > 1000) {
                        $res /= 1000;
                    }
                    return round($res, 1).'GHz';
                }
            ],
            'disk' => [
                'description' => "total disk space",
                'command'     => 'df . -h | tail -1 | awk \'{print $2}\'',
                'adapt'       => function ($res) {
                    return adapt_unit($res);
                }
            ],
            'ip_private' => [
                'description' => "main IP address",
                'command'     => 'ip -4 addr show '.$interface.' | grep \'inet \' | awk \'{print $2}\'',
                'adapt'       => function ($res) {
                    return $res;
                }
            ]
        ]
    ];

    $scopes = array_keys($commands);

    if(isset($data['scope']) && isset($data['scopes'])) {
        throw new InvalidArgumentException("invalid_only_one_of_scope_or_scopes_are_allowed", 400);
    }

    if(isset($data['scope']) && (!is_string($data['scope']) || !in_array($data['scope'], $scopes, true))) {
        throw new InvalidArgumentException("invalid_scope", 400);
    }

    if(isset($data['scopes'])) {
        if(!is_array($data['scopes'])) {
            throw new InvalidArgumentException("invalid_scopes", 400);
        }
        foreach($data['scopes'] as $scope) {
            if(!is_string($scope) || !in_array($scope, $scopes, true)) {
                throw new InvalidArgumentException("invalid_scopes", 400);
            }
        }
    }

    if(!empty($data['scopes'] ?? [])) {
        $scopes = $data['scopes'];
    }
    elseif(!empty($data['scope'] ?? '')) {
        $scopes = [$data['scope']];
    }

    $result = [];
    foreach($scopes as $scope) {
        foreach($commands[$scope] as $cmd => $command) {
            try {
                $res = exec_status_cmd($command['command']);
                if($res === null) {
                    $result[$scope][$cmd] = null;
                    continue;
                }
                $result[$scope][$cmd] = $command['adapt']($res);
            }
            catch(Throwable $e) {
                $result[$scope][$cmd] = null;
            }
        }
    }

    if(in_array('config', $scopes, true)) {
        $result['config']['type'] = 'k2';
    }

    // Keep the legacy top-level field, except when only instant data is requested.
    if(in_array('state', $scopes, true) || in_array('config', $scopes, true)) {
        $result['type'] = 'k2';
    }

    // #memo - this adds up too much info and could reveal sensitive data
    // $result['config']['env'] = getenv();

    return [
        'code' => 200,
        'body' => $result
    ];
}
