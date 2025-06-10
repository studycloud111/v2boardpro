<?php

namespace App\Plugins\Telegram\Commands;

use App\Services\TelegramService;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Start
{
    public $command = '/start';
    protected $telegramService;

    public function __construct()
    {
        $this->telegramService = new TelegramService();
    }

    public function handle($message)
    {
        $user = User::where('telegram_id', $message->from_id)->first();
        if (!$user) {
            $this->telegramService->sendMessage(
                $message->chat_id,
                "您好，您尚未绑定账号。\n请使用 `/bind <订阅链接或邮箱>` 进行绑定。",
                'markdown'
            );
            return;
        }

        $keyboard = $this->getMainMenuKeyboard($user);

        $replyMarkup = [
            'inline_keyboard' => $keyboard
        ];

        $this->telegramService->sendMessage(
            $message->chat_id,
            $this->getOwnerGreeting($message, $user) . "\n\n🌟 **欢迎使用 FSCloud** 🌟\n\n💫 请选择您需要的服务：",
            'markdown',
            $replyMarkup
        );
    }

    public function callback($message)
    {
        $user = User::where('telegram_id', $message->from_id)->first();
        if (!$user) {
            $this->telegramService->answerCallbackQuery($message->id, '请先绑定账号', true);
            return;
        }

        // Only restrict sensitive operations in groups
        if (
            !$message->is_private &&
            in_array($message->data, ['my_account', 'confirm_unbind'])
        ) {
            $this->telegramService->answerCallbackQuery(
                $message->id,
                '出于隐私考虑，请在与我的私聊中使用此功能。',
                true
            );
            return;
        }

        switch ($message->data) {
            case 'my_account':
                $this->myAccount($user, $message);
                break;
            case 'daily_checkin':
                $this->dailyCheckin($user, $message);
                break;
            case 'checked_in_info':
                $cacheKey = 'tg_checkin_traffic_' . $user->id;
                $traffic = Cache::get($cacheKey);
                $this->telegramService->answerCallbackQuery($message->id, "您今天已经签到过了，获得了 {$traffic} MB 流量。", true);
                break;
            case 'entertainment_center':
                $this->showEntertainmentCenter($message);
                break;
            case 'official_website':
                $this->showOfficialWebsite($message);
                break;
            case 'gamble_traffic':
                $this->showGambleTrafficOptions($message);
                break;
            case (preg_match('/^gamble_traffic_(\d+)$/', $message->data, $matches) ? true : false):
                $this->confirmGambleTraffic($message, (int)$matches[1]);
                break;
            case (preg_match('/^start_gamble_traffic_(\d+)$/', $message->data, $matches) ? true : false):
                $this->runGambleTraffic($user, $message, (int)$matches[1]);
                break;
            case 'gamble_time':
                $this->showGambleTimeOptions($user, $message);
                break;
            case (preg_match('/^gamble_time_(\d+)$/', $message->data, $matches) ? true : false):
                $this->confirmGambleTime($user, $message, (int)$matches[1]);
                break;
            case (preg_match('/^start_gamble_time_(\d+)$/', $message->data, $matches) ? true : false):
                $this->runGambleTime($user, $message, (int)$matches[1]);
                break;
            case 'upgrade_commission':
                $this->handleCommissionUpgrade($user, $message);
                break;
            case 'confirm_unbind':
                $this->confirmUnbind($message);
                break;
            case 'do_unbind':
                $this->doUnbind($user, $message);
                break;
            case 'go_back':
                $keyboard = $this->getMainMenuKeyboard($user);

                $replyMarkup = [
                    'inline_keyboard' => $keyboard
                ];

                $this->telegramService->editMessageText(
                    $message->chat_id,
                    $message->message_id,
                    $this->getOwnerGreeting($message, $user) . "\n\n🌟 **欢迎使用 FSCloud** 🌟\n\n💫 请选择您需要的服务：",
                    'markdown',
                    $replyMarkup
                );
                $this->telegramService->answerCallbackQuery($message->id, '', false);
                break;
        }
    }

    private function myAccount($user, $message)
    {
        try {
            // Commission
            $commissionBalance = $user->commission_balance / 100;
            $inviteCode = \App\Models\InviteCode::where('user_id', $user->id)->first();
            if (!$inviteCode) {
                $inviteCode = new \App\Models\InviteCode();
                $inviteCode->user_id = $user->id;
                $inviteCode->code = \App\Utils\Helper::randomChar(8);
                $inviteCode->save();
            }
            $inviteURL = config('v2board.app_url', config('app.url')) . '/#/register?code=' . $inviteCode->code;

            // Plan information
            $plan = null;
            if ($user->plan_id) {
                $plan = \App\Models\Plan::find($user->plan_id);
            }
            $planName = $plan ? $plan->name : '无';
            $expiredAt = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';

            // Traffic information
            $transfer_enable = $user->transfer_enable ?? 0;
            $u = $user->u ?? 0;
            $d = $user->d ?? 0;

            $total = \App\Utils\Helper::trafficConvert((int)$transfer_enable);
            $used = \App\Utils\Helper::trafficConvert((int)($u + $d));
            $remaining = \App\Utils\Helper::trafficConvert((int)($transfer_enable - ($u + $d)));
            $subscribeUrl = Helper::getSubscribeUrl($user->token);

            // Commission promotion info
            $commissionRate = $user->commission_rate ?? config('v2board.invite_commission', 25);
            $paidUserCount = \App\Models\CommissionLog::where('invite_user_id', $user->id)->distinct('user_id')->count();
            
            $tiers = [
                ['threshold' => 50, 'rate' => 40, 'name' => '高级推广员'],
                ['threshold' => 100, 'rate' => 50, 'name' => '推广大师']
            ];

            $nextTier = null;
            foreach ($tiers as $tier) {
                if ($commissionRate < $tier['rate']) {
                    $nextTier = $tier;
                    break;
                }
            }

            $promotionText = "👑 **推广中心** 👑\n" .
                             "━━━━━━━━━━━━━━━━━\n" .
                             "📊 当前返利：`{$commissionRate}%`\n";
            
            if ($nextTier) {
                $promotionText .= "📈 推广进度：`{$paidUserCount}/{$nextTier['threshold']}` 人\n";
            } else {
                $promotionText .= "🏆 推广进度：您已是最高等级的推广大师！\n";
            }

            $text = "👤 **个人信息**\n" .
                    "━━━━━━━━━━━━━━━━━\n" .
                    "📦 套餐名称：`{$planName}`\n" .
                    "⏰ 到期时间：`{$expiredAt}`\n" .
                    "📊 套餐流量：`{$total}`\n" .
                    "📈 已用流量：`{$used}`\n" .
                    "📉 剩余流量：`{$remaining}`\n" .
                    "━━━━━━━━━━━━━━━━━\n" .
                    "📧 邮箱：`{$user->email}`\n" .
                    "💰 余额：`{$user->balance}` 元\n" .
                    "💎 返利余额：`{$commissionBalance}` 元\n" .
                    "━━━━━━━━━━━━━━━━━\n" .
                    "🔗 邀请链接：\n`{$inviteURL}`\n" .
                    "━━━━━━━━━━━━━━━━━\n" .
                    "📱 订阅地址：\n`{$subscribeUrl}`\n" .
                    "━━━━━━━━━━━━━━━━━\n" .
                    $promotionText;
            
            $keyboardRow1 = [];
            if ($nextTier) {
                $keyboardRow1[] = ['text' => "⚡️ 晋升{$nextTier['name']} ({$nextTier['rate']}%)", 'callback_data' => 'upgrade_commission'];
            }
            $keyboardRow1[] = ['text' => '🔙 返回主菜单', 'callback_data' => 'go_back'];

            $keyboard = [$keyboardRow1];

            $replyMarkup = ['inline_keyboard' => $keyboard];

            $this->telegramService->editMessageText($message->chat_id, $message->message_id, $this->getOwnerGreeting($message, $user) . "\n\n" . $text, 'markdown', $replyMarkup);
            $this->telegramService->answerCallbackQuery($message->id, '', false);
        } catch (\Throwable $e) {
            $this->telegramService->answerCallbackQuery($message->id, '查询账户信息时出错，请稍后再试。', true);
        }
    }

    private function dailyCheckin($user, $message)
    {
        $lock = Cache::lock('lock_checkin_' . $user->id, 10);

        if (!$lock->get()) {
            $this->telegramService->answerCallbackQuery($message->id, '服务器繁忙，请稍后再试', true);
            return;
        }

        try {
            $cacheKey = 'tg_checkin_traffic_' . $user->id;
            if (Cache::get($cacheKey)) {
                $this->telegramService->answerCallbackQuery($message->id, '您今日已签到，请明日再来。', true);
                return;
            }

            $traffic = rand(config('v2board.checkin_min', 100), config('v2board.checkin_max', 500));
            $user->transfer_enable += $traffic * 1024 * 1024;
            if (!$user->save()) {
                $this->telegramService->answerCallbackQuery($message->id, '签到失败，数据保存时出错，请稍后再试。', true);
                return;
            }

            $ttl = strtotime(date('Y-m-d', strtotime('+1 day'))) - time();
            Cache::put($cacheKey, $traffic, $ttl);

            $this->telegramService->answerCallbackQuery($message->id, "签到成功！您获得了 {$traffic} MB 流量！", false);

            $keyboard = $this->getMainMenuKeyboard($user);
            $replyMarkup = ['inline_keyboard' => $keyboard];
            
            $announcement = "";
            if (!$message->is_private) {
                $userName = str_replace(['[', ']', '(', ')', '`', '*', '_'], '', $message->from_first_name);
                $announcement = "🎉 签到播报！ **{$userName}** 刚刚获得了 `{$traffic} MB` 流量！\n\n";
            }
            
            $text = $announcement . $this->getOwnerGreeting($message, $user) . "\n\n🌟 **欢迎使用 FSCloud** 🌟\n\n💫 请选择您需要的服务：";

            try {
                $this->telegramService->editMessageText(
                    $message->chat_id,
                    $message->message_id,
                    $text,
                    'markdown',
                    $replyMarkup
                );
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'message is not modified') === false) {
                    $this->telegramService->answerCallbackQuery($message->id, '更新消息时出错', true);
                }
            }
        } finally {
            $lock->release();
        }
    }

    private function getMainMenuKeyboard($user)
    {
        $cacheKey = 'tg_checkin_traffic_' . $user->id;
        $checkedInTraffic = Cache::get($cacheKey);

        $checkinButtonText = '🎁 每日签到';

        if ($checkedInTraffic) {
            $checkinButtonText = "✅ 今日已领 {$checkedInTraffic} MB";
        }

        return [
            [
                ['text' => '👤 我的账户', 'callback_data' => 'my_account'],
                ['text' => $checkinButtonText, 'callback_data' => 'daily_checkin']
            ],
            [
                ['text' => '🌐 官网', 'callback_data' => 'official_website'],
                ['text' => '🔓 解绑账号', 'callback_data' => 'confirm_unbind']
            ],
            [
                ['text' => '🎮 娱乐中心', 'callback_data' => 'entertainment_center']
            ]
        ];
    }

    private function getOwnerGreeting($message, $user = null)
    {
        if ($message->is_private) {
            $name = $user ? $user->email : $message->from_first_name;
            return "您好，{$name}！";
        }

        $userName = str_replace(['[', ']', '(', ')', '`', '*', '_'], '', $message->from_first_name);
        return "您好，[{$userName}](tg://user?id={$message->from_id})！";
    }

    private function confirmUnbind($message)
    {
        $keyboard = [
            [
                ['text' => '⚠️ 确认解绑', 'callback_data' => 'do_unbind'],
                ['text' => '❌ 取消', 'callback_data' => 'go_back']
            ]
        ];
        $replyMarkup = ['inline_keyboard' => $keyboard];

        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            $this->getOwnerGreeting($message) . "\n\n🔓 **解绑确认** 🔓\n\n⚠️ 您确定要将您的账户与此Telegram账号解绑吗？\n\n❗️ 此操作不可逆。",
            'markdown',
            $replyMarkup
        );
        $this->telegramService->answerCallbackQuery($message->id, '', false);
    }

    private function doUnbind($user, $message)
    {
        $email = $user->email;
        $user->telegram_id = NULL;

        if (!$user->save()) {
            $this->telegramService->answerCallbackQuery($message->id, '解绑失败，请稍后再试。', true);
            return;
        }

        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            "✅ **解绑成功！** ✅\n\n👋 您的账号 `{$email}` 已与当前Telegram账号解除关联。\n\n🔄 您随时可以使用 `/bind` 命令重新绑定。",
            'markdown'
        );
        $this->telegramService->answerCallbackQuery($message->id, '✅ 解绑成功！', false);
    }

    private function showEntertainmentCenter($message)
    {
        $keyboard = [
            [
                ['text' => '🎰 幸运大转盘(流量)', 'callback_data' => 'gamble_traffic'],
                ['text' => '⏰ 时光扭蛋机(时间)', 'callback_data' => 'gamble_time']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'go_back']
            ]
        ];
        $replyMarkup = ['inline_keyboard' => $keyboard];

        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            $this->getOwnerGreeting($message) . "\n\n🎮 **娱乐中心** 🎮\n\n🎉 欢迎来到娱乐中心！请选择您想玩的游戏：",
            'markdown',
            $replyMarkup
        );
        $this->telegramService->answerCallbackQuery($message->id, '', false);
    }
    
    private function showOfficialWebsite($message)
    {
        $websiteUrl = config('v2board.app_url', config('app.url'));
        
        $keyboard = [
            [
                ['text' => '🚀 前往官网', 'url' => $websiteUrl]
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'go_back']
            ]
        ];
        $replyMarkup = ['inline_keyboard' => $keyboard];

        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            $this->getOwnerGreeting($message) . "\n\n🌐 **官网** 🌐\n\n📱 请点击下方按钮访问官网：",
            'markdown',
            $replyMarkup
        );
        $this->telegramService->answerCallbackQuery($message->id, '', false);
    }

    private function showGambleTrafficOptions($message)
    {
        $keyboard = [
            [
                ['text' => '💎 5 GB', 'callback_data' => 'gamble_traffic_5'],
                ['text' => '💎 10 GB', 'callback_data' => 'gamble_traffic_10']
            ],
            [
                ['text' => '💎 20 GB', 'callback_data' => 'gamble_traffic_20'],
                ['text' => '💎 50 GB', 'callback_data' => 'gamble_traffic_50']
            ],
            [
                ['text' => '🔙 返回娱乐中心', 'callback_data' => 'entertainment_center']
            ]
        ];
        $replyMarkup = ['inline_keyboard' => $keyboard];

        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            $this->getOwnerGreeting($message) . "\n\n🎰 **幸运大转盘** 🎰\n\n💎 请选择您的幸运筹码（流量）：",
            'markdown',
            $replyMarkup
        );
        $this->telegramService->answerCallbackQuery($message->id, '', false);
    }

    private function confirmGambleTraffic($message, $gb)
    {
        $consolation_min = round($gb * 0.1, 2);
        $consolation_max = round($gb * 0.9, 2);
        $normal_min = round($gb * 1.1, 2);
        $normal_max = round($gb * 1.9, 2);
        $big_min = round($gb * 2.0, 2);
        $big_max = round($gb * 5.0, 2);
        $jackpot = round($gb * 10, 2);

        $prizeText = "🎁 **奖励详情：**\n" .
                     "━━━━━━━━━━━━━━━━━\n" .
                     "💎 **惊喜奖**: `{$big_min} ~ {$big_max} GB`\n" .
                     "🎯 **普通奖**: `{$normal_min} ~ {$normal_max} GB`\n" .
                     "🍀 **安慰奖**: `{$consolation_min} ~ {$consolation_max} GB`";

        $keyboard = [
            [
                ['text' => '🚀 放手一搏！', 'callback_data' => 'start_gamble_traffic_' . $gb]
            ],
            [
                ['text' => '🤔 我再想想...', 'callback_data' => 'gamble_traffic']
            ]
        ];
        $replyMarkup = ['inline_keyboard' => $keyboard];
        $text = "🎰 **幸运大转盘** 🎰\n" .
                "━━━━━━━━━━━━━━━━━\n" .
                "💰 您即将投入 `{$gb} GB` 流量，挑战神秘奖池！\n\n" .
                "🏆 **最高可赢取 {$jackpot} GB 超级大奖！**\n\n" .
                "{$prizeText}\n\n" .
                "✨ **以小博大，逆天改命，就在此刻！** ✨\n\n" .
                "🎲 您准备好接受挑战了吗？";

        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            $this->getOwnerGreeting($message) . "\n\n" . $text,
            'markdown',
            $replyMarkup
        );
        $this->telegramService->answerCallbackQuery($message->id, '', false);
    }

    private function runGambleTraffic($user, $message, $gb)
    {
        $lock = Cache::lock('lock_gamble_' . $user->id, 10);
        if (!$lock->get()) {
            $this->telegramService->answerCallbackQuery($message->id, '您操作太快了，请稍后再试。', true);
            return;
        }

        try {
            $user->refresh();

            $cost = $gb * 1024 * 1024 * 1024;
            if ($user->transfer_enable < $cost) {
                $this->telegramService->answerCallbackQuery($message->id, '您的流量不足，无法参与本次游戏。', true);
                return;
            }

            // New Prize Logic
            $rand = rand(1, 100);
            if ($rand <= 1) { // 1% for Jackpot
                $prizeGb = $gb * 10;
            } elseif ($rand <= 5) { // 4% for Big Win
                $prizeGb = $gb * (rand(20, 50) / 10); // 2.0x to 5.0x
            } elseif ($rand <= 25) { // 20% for Normal Win
                $prizeGb = $gb * (rand(11, 19) / 10); // 1.1x to 1.9x
            } else { // 75% for Consolation
                $prizeGb = $gb * (rand(1, 9) / 10); // 0.1x to 0.9x
            }
            $prizeGb = round($prizeGb, 2);
            
            $prizeBytes = (int)($prizeGb * 1024 * 1024 * 1024);
            $user->transfer_enable = $user->transfer_enable - $cost + $prizeBytes;
            
            if (!$user->save()) {
                $this->telegramService->answerCallbackQuery($message->id, '游戏失败，数据保存时出错，请稍后再试。', true);
                return;
            }

            $userName = str_replace(['[', ']', '(', ')', '`', '*', '_'], '', $message->from_first_name);
            $userMention = $message->is_private ? "您" : "[{$userName}](tg://user?id={$message->from_id})";

            $resultText = $prizeGb >= $gb ? "🎉 **恭喜中奖！** 🎉\n\n{$userMention} 消耗了 `{$gb} GB` 流量，幸运地抽中了 `{$prizeGb} GB` 超级大奖！" : "😅 **阳光普照** 😅\n\n{$userMention} 消耗了 `{$gb} GB` 流量，抽中了 `{$prizeGb} GB` 阳光普照奖。";
            $text = "{$resultText}\n\n🎲 继续游戏，好运连连！";
            
            $keyboard = [
                [
                    ['text' => '🔄 再玩一次', 'callback_data' => 'gamble_traffic'],
                    ['text' => '🔙 返回娱乐中心', 'callback_data' => 'entertainment_center']
                ]
            ];
            $replyMarkup = ['inline_keyboard' => $keyboard];

            $this->telegramService->editMessageText(
                $message->chat_id,
                $message->message_id,
                $this->getOwnerGreeting($message, $user) . "\n\n" . $text,
                'markdown',
                $replyMarkup
            );
            $this->telegramService->answerCallbackQuery($message->id, "恭喜！抽中 {$prizeGb} GB", false);

        } finally {
            $lock->release();
        }
    }

    private function showGambleTimeOptions($user, $message)
    {
        if ($user->expired_at === NULL) {
            $this->telegramService->answerCallbackQuery($message->id, '您好，一次性或永久套餐无法参与此游戏。', true);
            $this->showEntertainmentCenter($message); // Go back to entertainment center
            return;
        }

        $keyboard = [
            [
                ['text' => '⏳ 1 天', 'callback_data' => 'gamble_time_1'],
                ['text' => '⏳ 3 天', 'callback_data' => 'gamble_time_3']
            ],
            [
                ['text' => '⏳ 5 天', 'callback_data' => 'gamble_time_5'],
                ['text' => '⏳ 7 天', 'callback_data' => 'gamble_time_7']
            ],
            [
                ['text' => '🔙 返回娱乐中心', 'callback_data' => 'entertainment_center']
            ]
        ];
        $replyMarkup = ['inline_keyboard' => $keyboard];

        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            $this->getOwnerGreeting($message) . "\n\n⏰ **时光扭蛋机** ⏰\n\n⏳ 请选择您的幸运筹码（时间）：",
            'markdown',
            $replyMarkup
        );
        $this->telegramService->answerCallbackQuery($message->id, '', false);
    }

    private function confirmGambleTime($user, $message, $days)
    {
        if ($user->expired_at === NULL) {
            $this->telegramService->answerCallbackQuery($message->id, '您好，一次性或永久套餐无法参与此游戏。', true);
            return;
        }

        $consolation_min = round($days * 0.1);
        $consolation_max = round($days * 0.9);
        $normal_min = round($days * 1.1);
        $normal_max = round($days * 1.9);
        $big_min = round($days * 2.0);
        $big_max = round($days * 5.0);
        $jackpot = round($days * 10);

        $consolation_range = ($consolation_min == $consolation_max) ? "{$consolation_max} 天" : "{$consolation_min} ~ {$consolation_max} 天";
        if ($consolation_min <= 0 && $consolation_max <= 0) $consolation_range = "0 天";
        
        $normal_range = ($normal_min == $normal_max) ? "{$normal_max} 天" : "{$normal_min} ~ {$normal_max} 天";
        $big_range = ($big_min == $big_max) ? "{$big_max} 天" : "{$big_min} ~ {$big_max} 天";
        
        $prizeText = "🎁 **奖励详情：**\n" .
                     "━━━━━━━━━━━━━━━━━\n" .
                     "💎 **惊喜续命**: `{$big_range}`\n" .
                     "🎯 **小幅延期**: `{$normal_range}`\n" .
                     "🍀 **安慰奖**: `{$consolation_range}`";

        $keyboard = [
            [
                ['text' => '⚡️ 扭转时间！', 'callback_data' => 'start_gamble_time_' . $days]
            ],
            [
                ['text' => '🤔 我再想想...', 'callback_data' => 'gamble_time']
            ]
        ];
        $replyMarkup = ['inline_keyboard' => $keyboard];
        $text = "⏰ **时光扭蛋机** ⏰\n" .
                "━━━━━━━━━━━━━━━━━\n" .
                "💰 您即将投入 `{$days} 天`，抽取您的未来！\n\n" .
                "🏆 **最高可获得 {$jackpot} 天 奇迹时长！**\n\n" .
                "{$prizeText}\n\n" .
                "✨ **投入一瞬，赢得永恒！** ✨\n\n" .
                "🎲 您准备好扭转时间了吗？";

        $this->telegramService->editMessageText(
            $message->chat_id,
            $message->message_id,
            $this->getOwnerGreeting($message) . "\n\n" . $text,
            'markdown',
            $replyMarkup
        );
        $this->telegramService->answerCallbackQuery($message->id, '', false);
    }

    private function runGambleTime($user, $message, $days)
    {
        if ($user->expired_at === NULL) {
            $this->telegramService->answerCallbackQuery($message->id, '您好，一次性或永久套餐无法参与此游戏。', true);
            return;
        }

        $lock = Cache::lock('lock_gamble_' . $user->id, 10);
        if (!$lock->get()) {
            $this->telegramService->answerCallbackQuery($message->id, '您操作太快了，请稍后再试。', true);
            return;
        }

        try {
            $user->refresh();

            $costSeconds = $days * 86400;
            $currentExpiry = $user->expired_at;
            
            if ($currentExpiry < (time() + $costSeconds)) {
                $this->telegramService->answerCallbackQuery($message->id, '您的剩余时长不足，无法参与本次游戏。', true);
                return;
            }

            // New Prize Logic
            $rand = rand(1, 100);
            if ($rand <= 1) { // 1% for Jackpot
                $prizeDays = $days * 10;
            } elseif ($rand <= 5) { // 4% for Big Win
                $prizeDays = $days * (rand(20, 50) / 10);
            } elseif ($rand <= 25) { // 20% for Normal Win
                $prizeDays = $days * (rand(11, 19) / 10);
            } else { // 75% for Consolation
                $prizeDays = $days * (rand(1, 9) / 10);
            }
            $prizeDays = round($prizeDays); // Round to nearest whole day
            
            if ($prizeDays < 0) $prizeDays = 0; // Prize cannot be negative days
            
            $prizeSeconds = (int)($prizeDays * 86400);
            $user->expired_at = $currentExpiry - $costSeconds + $prizeSeconds;

            if (!$user->save()) {
                $this->telegramService->answerCallbackQuery($message->id, '游戏失败，数据保存时出错，请稍后再试。', true);
                return;
            }

            $userName = str_replace(['[', ']', '(', ')', '`', '*', '_'], '', $message->from_first_name);
            $userMention = $message->is_private ? "您" : "[{$userName}](tg://user?id={$message->from_id})";

            $resultText = $prizeDays >= $days ? "🎉 **恭喜中奖！** 🎉\n\n{$userMention}消耗了 `{$days} 天`，幸运地抽中了 `{$prizeDays} 天` 有效期！" : "😅 **阳光普照** 😅\n\n{$userMention}消耗了 `{$days} 天`，抽中了 `{$prizeDays} 天` 安慰奖。";
            $text = "{$resultText}\n\n🎲 继续游戏，好运连连！";
            
            $keyboard = [
                [
                    ['text' => '🔄 再玩一次', 'callback_data' => 'gamble_time'],
                    ['text' => '🔙 返回娱乐中心', 'callback_data' => 'entertainment_center']
                ]
            ];
            $replyMarkup = ['inline_keyboard' => $keyboard];

            $this->telegramService->editMessageText(
                $message->chat_id,
                $message->message_id,
                $this->getOwnerGreeting($message, $user) . "\n\n" . $text,
                'markdown',
                $replyMarkup
            );
            $this->telegramService->answerCallbackQuery($message->id, "恭喜！抽中 {$prizeDays} 天", false);

        } finally {
            $lock->release();
        }
    }

    private function handleCommissionUpgrade($user, $message)
    {
        $tiers = [
            ['threshold' => 50, 'rate' => 40, 'name' => '高级推广员'],
            ['threshold' => 100, 'rate' => 50, 'name' => '推广大师']
        ];
        
        $currentRate = $user->commission_rate ?? config('v2board.invite_commission', 25);

        $nextTier = null;
        foreach ($tiers as $tier) {
            if ($currentRate < $tier['rate']) {
                $nextTier = $tier;
                break;
            }
        }

        if (!$nextTier) {
            $this->telegramService->answerCallbackQuery($message->id, '您已是最高等级的推广大师，无需重复升级！', true);
            return;
        }

        $paidUserCount = \App\Models\CommissionLog::where('invite_user_id', $user->id)->distinct('user_id')->count();

        if ($paidUserCount < $nextTier['threshold']) {
            $remaining = $nextTier['threshold'] - $paidUserCount;
            $this->telegramService->answerCallbackQuery($message->id, "任务还未完成哦！您当前已邀请 {$paidUserCount}/{$nextTier['threshold']} 人，还差 {$remaining} 人。", true);
            return;
        }

        $user->commission_rate = $nextTier['rate'];
        if (!$user->save()) {
            $this->telegramService->answerCallbackQuery($message->id, '升级失败，数据保存时出错，请稍后再试。', true);
            return;
        }
        
        // Refresh the account view
        $this->myAccount($user, $message);
        $this->telegramService->answerCallbackQuery($message->id, "🎉 恭喜！您已成功晋升为{$nextTier['name']}，返利比例已提升至{$nextTier['rate']}%！", true);
    }
} 