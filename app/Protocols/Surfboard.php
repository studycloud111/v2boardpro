<?php

namespace App\Protocols;

use App\Utils\Helper;

class Surfboard
{
    public $flag = 'surfboard';
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

        $appName = config('v2board.app_name', 'V2Board');
        header("content-disposition:attachment;filename*=UTF-8''".rawurlencode($appName).".conf");

        $proxies = '';
        $proxyGroup = '';

        foreach ($servers as $item) {
            if ($item['type'] === 'shadowsocks'
                && in_array($item['cipher'], [
                    'aes-128-gcm',
                    'aes-192-gcm',
                    'aes-256-gcm',
                    'chacha20-ietf-poly1305'
                ])
            ) {
                // [Proxy]
                $proxies .= self::buildShadowsocks($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === 'vmess') {
                // [Proxy]
                $proxies .= self::buildVmess($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === 'trojan') {
                // [Proxy]
                $proxies .= self::buildTrojan($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
        }

        $defaultConfig = base_path() . '/resources/rules/default.surfboard.conf';
        $customConfig = base_path() . '/resources/rules/custom.surfboard.conf';
        if (\File::exists($customConfig)) {
            $config = file_get_contents("$customConfig");
        } else {
            $config = file_get_contents("$defaultConfig");
        }

        // Subscription link
        $subsURL = Helper::getSubscribeUrl($user['token']);
        $subsDomain = $_SERVER['HTTP_HOST'];

        $config = str_replace('$subs_link', $subsURL, $config);
        $config = str_replace('$subs_domain', $subsDomain, $config);
        $config = str_replace('$proxies', $proxies, $config);
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);

        $upload = round($user['u'] / (1024*1024*1024), 2);
        $download = round($user['d'] / (1024*1024*1024), 2);
        $useTraffic = $upload + $download;
        $totalTraffic = round($user['transfer_enable'] / (1024*1024*1024), 2);
        $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
        $subscribeInfo = "title={$appName}订阅信息, content=上传流量：{$upload}GB\\n下载流量：{$download}GB\\n剩余流量：{$useTraffic}GB\\n套餐流量：{$totalTraffic}GB\\n到期时间：{$expireDate}";
        $config = str_replace('$subscribe_info', $subscribeInfo, $config);

        return $config;
    }


    public static function buildShadowsocks($password, $server)
    {
        $config = [
            "{$server['name']}=ss",
            "{$server['host']}",
            "{$server['port']}",
            "encrypt-method={$server['cipher']}",
            "password={$password}",
            'tfo=true',
            'udp-relay=true'
        ];
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildVmess($uuid, $server)
    {
        $config = [
            "{$server['name']}=vmess",
            "{$server['host']}",
            "{$server['port']}",
            "username={$uuid}",
            "vmess-aead=true",
            'tfo=true',
            'udp-relay=true'
        ];

        if ($server['tls']) {
            array_push($config, 'tls=true');
            if ($server['tlsSettings']) {
                $tlsSettings = $server['tlsSettings'];
                if (isset($tlsSettings['allowInsecure']) && !empty($tlsSettings['allowInsecure']))
                    array_push($config, 'skip-cert-verify=' . ($tlsSettings['allowInsecure'] ? 'true' : 'false'));
                if (isset($tlsSettings['serverName']) && !empty($tlsSettings['serverName']))
                    array_push($config, "sni={$tlsSettings['serverName']}");
            }
        }
        if ($server['network'] === 'ws') {
            array_push($config, 'ws=true');
            if ($server['networkSettings']) {
                $wsSettings = $server['networkSettings'];
                if (isset($wsSettings['path']) && !empty($wsSettings['path']))
                    array_push($config, "ws-path={$wsSettings['path']}");
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host']))
                    array_push($config, "ws-headers=Host:{$wsSettings['headers']['Host']}");
            }
        }

        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildTrojan($password, $server)
{
    // 处理SNI
    $sniValue = isset($server['server_name']) ? $server['server_name'] : '';
    if (isset($server['network']) && $server['network'] === 'ws' && strpos($sniValue, 'null.') === 0) {
        $randomPart = self::generateRandomString(8, 20);
        $sniValue = $randomPart . substr($sniValue, 4); // 替换null.为随机部分
    }
    $sniConfig = $sniValue ? "sni={$sniValue}" : "";

    $config = [
        "{$server['name']}=trojan",
        "{$server['host']}",
        "{$server['port']}",
        "password={$password}",
        $sniConfig,
        'tfo=true',
        'udp-relay=true'
    ];

    if (!empty($server['allow_insecure'])) {
        array_push($config, $server['allow_insecure'] ? 'skip-cert-verify=true' : 'skip-cert-verify=false');
    }

    if (isset($server['network']) && $server['network'] === "ws") {
        array_push($config, "ws=true");
        if (isset($server['network_settings']['path'])) {
            array_push($config, "ws-path={$server['network_settings']['path']}");
        }
        // 处理Host头
        if (isset($server['network_settings']['headers']['Host'])) {
            $hostValue = $server['network_settings']['headers']['Host'];
            if (strpos($hostValue, 'null.') === 0) {
                $randomPart = self::generateRandomString(8, 20);
                $hostValue = $randomPart . substr($hostValue, 4);
            }
            array_push($config, "ws-headers=Host:{$hostValue}");
        }
    }

    $config = array_filter($config);
    $uri = implode(',', $config);
    $uri .= "\r\n";
    return $uri;
}

private static function generateRandomString($minLength = 4, $maxLength = 20)
{
    $length = random_int($minLength, $maxLength);
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz-';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}
}
