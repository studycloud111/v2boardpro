<?php

namespace App\Protocols;


class QuantumultX
{
    public $flag = 'quantumult%20x';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $uri = '';
        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        foreach ($servers as $item) {
            if ($item['type'] === 'shadowsocks') {
                $uri .= self::buildShadowsocks($user['uuid'], $item);
            }
            if ($item['type'] === 'vmess') {
                $uri .= self::buildVmess($user['uuid'], $item);
            }
            if ($item['type'] === 'trojan') {
                $uri .= self::buildTrojan($user['uuid'], $item);
            }
        }
        return base64_encode($uri);
    }

    public static function buildShadowsocks($password, $server)
    {
        $config = [
            "shadowsocks={$server['host']}:{$server['port']}",
            "method={$server['cipher']}",
            "password={$password}",
            'fast-open=true',
            'udp-relay=true',
            "tag={$server['name']}"
        ];
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildVmess($uuid, $server)
    {
        $config = [
            "vmess={$server['host']}:{$server['port']}",
            'method=chacha20-poly1305',
            "password={$uuid}",
            'fast-open=true',
            'udp-relay=true',
            "tag={$server['name']}"
        ];

        if ($server['tls']) {
            if ($server['network'] === 'tcp')
                array_push($config, 'obfs=over-tls');
            if ($server['tlsSettings']) {
                $tlsSettings = $server['tlsSettings'];
                if (isset($tlsSettings['allowInsecure']) && !empty($tlsSettings['allowInsecure']))
                    array_push($config, 'tls-verification=' . ($tlsSettings['allowInsecure'] ? 'false' : 'true'));
                if (isset($tlsSettings['serverName']) && !empty($tlsSettings['serverName']))
                    $host = $tlsSettings['serverName'];
            }
        }

        if ($server['network'] === 'ws') {
            if ($server['tls']) {
                array_push($config, 'obfs=wss');
            } else {                
                array_push($config, 'obfs=ws');
            }
            if ($server['networkSettings']) {
                $wsSettings = $server['networkSettings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    array_push($config, "obfs-uri={$wsSettings['path']}");
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']) && !isset($host))
                    $host = $wsSettings['headers']['Host'];
            }
        }

        if (isset($host)) {
            array_push($config, "obfs-host={$host}");
        }

        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildTrojan($password, $server)
{
    $config = [
        "trojan={$server['host']}:{$server['port']}",
        "password={$password}",
        // Tips: allowInsecure=false = tls-verification=true
        $server['allow_insecure'] ? 'tls-verification=false' : 'tls-verification=true',
        'fast-open=true',
        'udp-relay=true',
        "tag={$server['name']}"
    ];
    $host = $server['server_name'] ?? $server['host'];
    
    if ($server['network'] === 'ws') {
        array_push($config, 'obfs=wss');
        if ($server['network_settings']) {
            $wsSettings = $server['network_settings'];
            if (isset($wsSettings['path']) && !empty($wsSettings['path'])) {
                array_push($config, "obfs-uri={$wsSettings['path']}");
            }
            if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) {
                $host = $wsSettings['headers']['Host'];
                // 处理以null开头的Host
                $domains = explode('.', $host);
                if (!empty($domains) && $domains[0] === 'null') {
                    $domains[0] = self::generateRandomSubdomain();
                    $host = implode('.', $domains);
                }
            }
            array_push($config, "obfs-host={$host}");
        }
    } else {
        array_push($config, "over-tls=true");
        if (isset($server['server_name']) && !empty($server['server_name'])) {
            $serverName = $server['server_name'];
            // 处理以null开头的server_name
            $domains = explode('.', $serverName);
            if (!empty($domains) && $domains[0] === 'null') {
                $domains[0] = self::generateRandomSubdomain();
                $serverName = implode('.', $domains);
            }
            array_push($config, "tls-host={$serverName}");
        }
    }
    
    $config = array_filter($config);
    $uri = implode(',', $config);
    $uri .= "\r\n";
    return $uri;
}

private static function generateRandomSubdomain()
{
    $length = mt_rand(8, 20);
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    $max = strlen($characters) - 1;
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[mt_rand(0, $max)];
    }
    return $randomString;
}
}
