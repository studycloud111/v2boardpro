<?php

namespace App\Plugins\Telegram\Commands;

use App\Services\TelegramService;
use App\Models\User;

class Bind
{
    public $command = '/bind';
    protected $telegramService;

    public function __construct()
    {
        $this->telegramService = new TelegramService();
    }

    public function handle($message)
    {
        if (!$message->is_private) {
            $this->telegramService->sendMessage($message->chat_id, '为了您的安全，请在与我私聊时进行绑定操作。');
            return;
        }

        if (count($message->args) === 0) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                "使用方法: `/bind <订阅链接>` 或 `/bind <您的邮箱>`\n例如：`/bind https://fscloud.vip/api/v1/client/subscribe?token=...`",
                'markdown'
            );
            return;
        }

        $user = User::where('telegram_id', $message->from_id)->first();
        if ($user) {
            $this->telegramService->sendMessage($message->chat_id, '您已绑定过账号，无需重复绑定。如需换绑，请先使用 /unbind 命令解绑。');
            return;
        }

        $input = $message->args[0];
        $user = null;

        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $input)->first();
        } else {
            $token = '';
            if (filter_var($input, FILTER_VALIDATE_URL)) {
                $query = parse_url($input, PHP_URL_QUERY);
                parse_str($query, $params);
                if (isset($params['token'])) {
                    $token = $params['token'];
                }
            } else {
                $token = $input;
            }

            if ($token) {
                 $user = User::where('token', $token)->first();
            }
        }
        
        if (!$user) {
            $this->telegramService->sendMessage($message->chat_id, '未找到您的账户信息，请检查您输入的订阅链接或邮箱是否正确。');
            return;
        }

        if ($user->telegram_id) {
            $this->telegramService->sendMessage($message->chat_id, '该账户已经绑定了另一个Telegram账号，如需换绑，请先登录后台解绑。');
            return;
        }
        
        $user->telegram_id = $message->from_id;
        if (!$user->save()) {
            $this->telegramService->sendMessage($message->chat_id, '绑定失败，请稍后再试。');
            return;
        }

        $this->telegramService->sendMessage($message->chat_id, "🎉 绑定成功！\n您的账号 `{$user->email}` 已与当前Telegram账号关联。", 'markdown');
    }
} 