<?php

namespace App\Protocols;

use App\Utils\Helper;

class Surge
{
    public $flag = 'surge';
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
            }elseif ($item['type'] === 'vmess') {
                // [Proxy]
                $proxies .= self::buildVmess($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }elseif ($item['type'] === 'trojan') {
                // [Proxy]
                $proxies .= self::buildTrojan($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }elseif ($item['type'] === 'hysteria' && $item['version'] === 2) { //surge只支持hysteria2
                // [Proxy]
                $proxies .= self::buildHysteria($user['uuid'], $item);
                // [Proxy Group]
                $proxyGroup .= $item['name'] . ', ';
            }
        }

        $defaultConfig = base_path() . '/resources/rules/default.surge.conf';
        $customConfig = base_path() . '/resources/rules/custom.surge.conf';
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
    // 生成随机字符串的辅助函数
    $generateRandomString = function ($minLength, $maxLength) {
        $length = mt_rand($minLength, $maxLength);
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz-';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[mt_rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    };

    // 处理 server_name 和 Host 头的 null. 前缀替换
    if (isset($server['network']) && (string)$server['network'] === 'ws') {
        // 处理 sni (server_name)
        if (!empty($server['server_name'])) {
            $parts = explode('.', $server['server_name'], 2);
            if ($parts[0] === 'null' && count($parts) > 1) {
                $randomPart = $generateRandomString(4, 20);
                $server['server_name'] = $randomPart . '.' . $parts[1];
            }
        }

        // 处理 ws Host 头
        if (isset($server['network_settings']['headers']['Host']) && !empty($server['network_settings']['headers']['Host'])) {
            $hostParts = explode('.', $server['network_settings']['headers']['Host'], 2);
            if ($hostParts[0] === 'null' && count($hostParts) > 1) {
                $randomPart = $generateRandomString(8, 20);
                $server['network_settings']['headers']['Host'] = $randomPart . '.' . $hostParts[1];
            }
        }
    }

    // 构建基础配置
    $config = [
        "{$server['name']}=trojan",
        "{$server['host']}",
        "{$server['port']}",
        "password={$password}",
        !empty($server['server_name']) ? "sni={$server['server_name']}" : "",
        'tfo=true',
        'udp-relay=true'
    ];

    // 处理不安全证书选项
    if (!empty($server['allow_insecure'])) {
        array_push($config, $server['allow_insecure'] ? 'skip-cert-verify=true' : 'skip-cert-verify=false');
    }

    // 处理 WebSocket 配置
    if (isset($server['network']) && (string)$server['network'] === 'ws') {
        array_push($config, 'ws=true');
        if (!empty($server['network_settings'])) {
            $wsSettings = $server['network_settings'];
            if (!empty($wsSettings['path'])) {
                array_push($config, "ws-path={$wsSettings['path']}");
            }
            if (!empty($wsSettings['headers']['Host'])) {
                array_push($config, "ws-headers=Host:{$wsSettings['headers']['Host']}");
            }
        }
    }

    // 过滤空值并生成 URI
    $config = array_filter($config);
    $uri = implode(',', $config) . "\r\n";
    return $uri;
}

    //参考文档: https://manual.nssurge.com/policy/proxy.html
    public static function buildHysteria($password, $server)
    {
        $parts = explode(",",$server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }

        $config = [
            "{$server['name']}=hysteria2",
            "{$server['host']}",
            "{$firstPort}",
            "password={$password}",
            "download-bandwidth={$server['up_mbps']}",
            $server['server_name'] ? "sni={$server['server_name']}" : "",
            // 'tfo=true', 
            'udp-relay=true'
        ];
        if (!empty($server['insecure'])) {
            array_push($config, $server['insecure'] ? 'skip-cert-verify=true' : 'skip-cert-verify=false');
        }
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }
}
