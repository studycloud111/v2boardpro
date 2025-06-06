<?php

/**
 * Telegram@smogate_bot
 */
namespace App\Payments;

use \Curl\Curl;

class Smogate {
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'smogate_app_id' => [
                'label' => 'APPID',
                'description' => 'Smogate -> 接入文档和密钥 -> 查看APPID和密钥',
                'type' => 'input'
            ],
            'smogate_app_secret' => [
                'label' => 'APP Secret',
                'description' => 'Smogate -> 接入文档和密钥 -> 查看APPID和密钥',
                'type' => 'input'
            ],
            'smogate_source_currency' => [
                'label' => '源货币',
                'description' => '默认CNY',
                'type' => 'input'
            ],
            'smogate_method' => [
                'label' => '支付方式',
                'description' => '',
                'type' => 'input',
            ],
            'alert1' => [
                'type' => 'alert',
                'content' => '开户请联系：<a href="https://t.me/smogate">@smogate</a>'
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'out_trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'notify_url' => $order['notify_url'],
            'method' => $this->config['smogate_method']
        ];
        if (isset($this->config['smogate_source_currency'])) {
            $params['source_currency'] = strtolower($this->config['smogate_source_currency']);
        }
        $params['app_id'] = $this->config['smogate_app_id'];
        ksort($params);
        $str = http_build_query($params) . $this->config['smogate_app_secret'];
        $params['sign'] = md5($str);
        $curl = new Curl();
        $curl->setUserAgent("Smogate {$this->config['smogate_app_id']}");
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->post("https://{$this->config['smogate_app_id']}.vless.org/v1/gateway/pay", http_build_query($params));
        $result = $curl->response;
        if (!$result) {
            abort(500, '网络异常');
        }
        if ($curl->error) {
            if (isset($result->errors)) {
                $errors = (array)$result->errors;
                abort(500, $errors[array_keys($errors)[0]][0]);
            }
            if (isset($result->message)) {
                abort(500, $result->message);
            }
            abort(500, '未知错误');
        }
        $curl->close();
        if (!isset($result->data)) {
            abort(500, '请求失败');
        }
        return [
            'type' => $this->isMobile() ? 1 : 0, // 0:qrcode 1:url
            'data' => $result->data
        ];
    }

    public function notify($params)
    {
        $sign = $params['sign'];
        unset($params['sign']);
        ksort($params);
        reset($params);
        $str = http_build_query($params) . $this->config['smogate_app_secret'];
        if ($sign !== md5($str)) {
            return false;
        }
        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['trade_no']
        ];
    }

    private function isMobile()
    {
        return strpos(strtolower($_SERVER['HTTP_USER_AGENT']), 'mobile') !== false;
    }
}
