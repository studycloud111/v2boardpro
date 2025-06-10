<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    protected $msg;
    protected $commands = [];
    protected $telegramService;

    public function __construct(Request $request)
    {
        if ($request->input('access_token') !== md5(config('v2board.telegram_bot_token'))) {
            abort(401);
        }

        $this->telegramService = new TelegramService();
    }

    public function webhook(Request $request)
    {
        $this->formatMessage($request->input());
        $this->formatChatJoinRequest($request->input());
        $this->handle();
    }

    public function handle()
    {
        if (!$this->msg) return;
        $msg = $this->msg;

        try {
            if ($msg->message_type === 'callback_query') {
                // 将 callback_query 分发给 Start 命令处理
                (new \App\Plugins\Telegram\Commands\Start())->callback($msg);
                return;
            }
            
            $commandName = explode('@', $msg->command);

            // To reduce request, only commands contains @ will get the bot name
            if (count($commandName) == 2) {
                $botName = $this->getBotName();
                if ($commandName[1] === $botName){
                    $msg->command = $commandName[0];
                }
            }

            foreach (glob(base_path('app//Plugins//Telegram//Commands') . '/*.php') as $file) {
                $command = basename($file, '.php');
                $class = '\\App\\Plugins\\Telegram\\Commands\\' . $command;
                if (!class_exists($class)) continue;
                $instance = new $class();
                if ($msg->message_type === 'message') {
                    if (!isset($instance->command)) continue;
                    if ($msg->command !== $instance->command) continue;
                    $instance->handle($msg);
                    return;
                }
                if ($msg->message_type === 'reply_message') {
                    if (!isset($instance->regex)) continue;
                    if (!preg_match($instance->regex, $msg->reply_text, $match)) continue;
                    $instance->handle($msg, $match);
                    return;
                }
            }
        } catch (\Exception $e) {
            if (isset($msg->message_type) && $msg->message_type === 'callback_query') {
                $this->telegramService->answerCallbackQuery($msg->id, '处理失败: ' . $e->getMessage(), true);
            } else {
                $this->telegramService->sendMessage($msg->chat_id, '处理失败: ' . $e->getMessage());
            }
        }
    }

    public function getBotName()
    {
        $response = $this->telegramService->getMe();
        return $response->result->username;
    }

    private function formatMessage(array $data)
    {
        if (isset($data['callback_query'])) {
            $this->msg = new \StdClass();
            $this->msg->message_type = 'callback_query';
            $this->msg->id = $data['callback_query']['id'];
            $this->msg->chat_id = $data['callback_query']['message']['chat']['id'];
            $this->msg->from_id = $data['callback_query']['from']['id'];
            $this->msg->from_first_name = $data['callback_query']['from']['first_name'];
            $this->msg->message_id = $data['callback_query']['message']['message_id'];
            $this->msg->data = $data['callback_query']['data'];
            $this->msg->is_private = $data['callback_query']['message']['chat']['type'] === 'private';
            if (isset($data['callback_query']['message']['text'])) {
                $this->msg->text = $data['callback_query']['message']['text'];
            }
            return;
        }

        if (!isset($data['message'])) return;
        if (!isset($data['message']['text'])) return;
        $obj = new \StdClass();
        $text = explode(' ', $data['message']['text']);
        $obj->command = $text[0];
        $obj->args = array_slice($text, 1);
        $obj->chat_id = $data['message']['chat']['id'];
        $obj->from_id = $data['message']['from']['id'];
        $obj->from_first_name = $data['message']['from']['first_name'];
        $obj->message_id = $data['message']['message_id'];
        $obj->message_type = 'message';
        $obj->text = $data['message']['text'];
        $obj->is_private = $data['message']['chat']['type'] === 'private';
        if (isset($data['message']['reply_to_message']['text'])) {
            $obj->message_type = 'reply_message';
            $obj->reply_text = $data['message']['reply_to_message']['text'];
        }
        $this->msg = $obj;
    }

    private function formatChatJoinRequest(array $data)
    {
        if (!isset($data['chat_join_request'])) return;
        if (!isset($data['chat_join_request']['from']['id'])) return;
        if (!isset($data['chat_join_request']['chat']['id'])) return;
        $user = \App\Models\User::where('telegram_id', $data['chat_join_request']['from']['id'])
            ->first();
        if (!$user) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        if (!$userService->isAvailable($user)) {
            $this->telegramService->declineChatJoinRequest(
                $data['chat_join_request']['chat']['id'],
                $data['chat_join_request']['from']['id']
            );
            return;
        }
        $userService = new \App\Services\UserService();
        $this->telegramService->approveChatJoinRequest(
            $data['chat_join_request']['chat']['id'],
            $data['chat_join_request']['from']['id']
        );
    }
}
