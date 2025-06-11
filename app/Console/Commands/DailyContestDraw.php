<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Services\TelegramService;

class DailyContestDraw extends Command
{
    protected $signature = 'contest:draw {--date= : 指定开奖日期 (格式: Y-m-d)}';
    protected $description = '每日竞猜开奖';

    public function handle()
    {
        // 如果指定了日期参数，使用指定日期；否则使用昨天
        $dateOption = $this->option('date');
        if ($dateOption) {
            $drawDate = $dateOption;
            $this->info("开始处理指定日期 {$drawDate} 的竞猜开奖...");
        } else {
            $drawDate = date('Y-m-d', strtotime('-1 day'));
            $this->info("开始处理 {$drawDate} 的竞猜开奖...");
        }

        $allResults = [];
        
        // 处理流量竞猜
        $trafficResult = $this->drawContest('traffic', $drawDate);
        if ($trafficResult) {
            $allResults['traffic'] = $trafficResult;
        }
        
        // 处理时间竞猜
        $timeResult = $this->drawContest('time', $drawDate);
        if ($timeResult) {
            $allResults['time'] = $timeResult;
        }

        // 检查并触发随机惊喜事件
        $surpriseEvent = $this->checkSurpriseEvent($drawDate, $allResults);

        // 发送合并的开奖通知
        if (!empty($allResults)) {
            $this->sendCombinedDrawNotification($drawDate, $allResults, $surpriseEvent);
        }

        $this->info('竞猜开奖完成！');
    }

    private function drawContest($type, $date)
    {
        $participantsCacheKey = "contest_{$type}_participants_{$date}";
        $poolCacheKey = "contest_{$type}_pool_{$date}";
        
        $participants = Cache::get($participantsCacheKey, []);
        $totalPool = Cache::get($poolCacheKey, 0);
        
        // 确保 $totalPool 是数字类型
        if (is_array($totalPool)) {
            $this->error("警告：奖池数据格式错误，重置为0");
            $totalPool = 0;
        } else {
            $totalPool = (float)$totalPool; // 强制转换为数字
        }
        
        if (empty($participants) || $totalPool <= 0) {
            $this->info("{$type} 竞猜无参与者，跳过开奖");
            return;
        }

        $unit = ($type === 'traffic' ? 'GB' : '天');
        $participantCount = count($participants);
        $this->info("{$type} 竞猜：{$totalPool} {$unit}，{$participantCount} 人参与");

        // 随机选出前三名（如果参与者不足3人，则按实际人数）
        $winners = $this->selectWinners($participants, min(3, count($participants)));
        
        // 分配奖励
        $prizes = $this->distributePrizes($totalPool, count($winners));
        
        // 发放奖励
        foreach ($winners as $index => $winnerId) {
            $winner = $participants[$winnerId];
            $prize = $prizes[$index];
            
            $this->awardPrize($winnerId, $type, $prize);
            $this->info("第" . ($index + 1) . "名：{$winner['email']} 获得 {$prize} " . ($type === 'traffic' ? 'GB' : '天'));
        }

        // 保存开奖记录
        $this->saveContestHistory($type, $date, $participants, $winners, $prizes, $totalPool);
        
        // 清理缓存数据，防止重复开奖
        Cache::forget($participantsCacheKey);
        Cache::forget($poolCacheKey);
        $this->info("{$type} 竞猜缓存已清理，防止重复开奖");
        
        return [
            'date' => $date,
            'type' => $type,
            'totalPool' => $totalPool,
            'participants' => $participants,
            'participantCount' => count($participants),
            'winners' => $winners,
            'prizes' => $prizes
        ];
    }

    private function selectWinners($participants, $count)
    {
        $userIds = array_keys($participants);
        shuffle($userIds); // 随机打乱
        return array_slice($userIds, 0, $count);
    }

    private function distributePrizes($totalPool, $winnerCount)
    {
        if ($winnerCount === 1) {
            return [$totalPool]; // 只有一人获得全部
        } elseif ($winnerCount === 2) {
            return [
                round($totalPool * 0.7, 2), // 第一名70%
                round($totalPool * 0.3, 2)  // 第二名30%
            ];
        } else { // 3人或以上
            return [
                round($totalPool * 0.5, 2), // 第一名50%
                round($totalPool * 0.3, 2), // 第二名30%
                round($totalPool * 0.2, 2)  // 第三名20%
            ];
        }
    }

    private function saveContestHistory($type, $date, $participants, $winners, $prizes, $totalPool)
    {
        $historyKey = "contest_history_{$date}";
        $history = Cache::get($historyKey, [
            'date' => $date,
            'traffic' => null,
            'time' => null
        ]);

        $winnersData = [];
        foreach ($winners as $index => $winnerId) {
            $winnersData[] = [
                'email' => $participants[$winnerId]['email'],
                'prize' => $prizes[$index]
            ];
        }

        $history[$type] = [
            'pool' => $totalPool,
            'participants' => count($participants),
            'winners' => $winnersData
        ];

        // 保存30天
        Cache::put($historyKey, $history, 30 * 86400);
    }

    private function hideEmail($email)
    {
        $parts = explode('@', $email);
        if (count($parts) != 2) {
            return substr($email, 0, 3) . '***';
        }
        
        $username = $parts[0];
        $domain = $parts[1];
        
        if (strlen($username) <= 3) {
            $hiddenUsername = $username[0] . str_repeat('*', strlen($username) - 1);
        } else {
            $hiddenUsername = substr($username, 0, 2) . str_repeat('*', strlen($username) - 2);
        }
        
        return $hiddenUsername . '@' . $domain;
    }

    private function sendCombinedDrawNotification($date, $results, $surpriseEvent = null)
    {
        $groupId = Cache::get('contest_group_id');
        if (!$groupId) {
            $this->info("未找到群组ID，跳过合并开奖通知");
            return;
        }

        $telegramService = new TelegramService();
        
        // 构建合并开奖消息
        $message = "🎉 **每日竞猜开奖公告** 🎉\n";
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "📅 开奖日期：`{$date}`\n\n";
        
        foreach ($results as $type => $result) {
            $typeIcon = $type === 'traffic' ? '💎' : '⏰';
            $unit = $type === 'traffic' ? 'GB' : '天';
            $typeName = $type === 'traffic' ? '流量竞猜池' : '时间竞猜池';
            
            $message .= "{$typeIcon} **{$typeName}**\n";
            $message .= "🏆 奖池总额：`{$result['totalPool']} {$unit}`\n";
            $message .= "👥 参与人数：`{$result['participantCount']}` 人\n\n";
            
            $message .= "🎊 **中奖名单**\n";
            $rankIcons = ['🥇', '🥈', '🥉'];
            foreach ($result['winners'] as $index => $winnerId) {
                $winner = $result['participants'][$winnerId];
                $prize = $result['prizes'][$index];
                $rankIcon = $rankIcons[$index] ?? '#' . ($index + 1);
                $hiddenEmail = $this->hideEmail($winner['email']);
                
                $message .= "{$rankIcon} `{$hiddenEmail}` - `{$prize} {$unit}`\n";
            }
            $message .= "\n";
        }
        
        $message .= "━━━━━━━━━━━━━━━━━\n";
        $message .= "🎮 恭喜以上获奖用户！奖励已自动发放到账户！\n";
        
        // 如果有惊喜事件，添加特殊显示
        if ($surpriseEvent) {
            $message .= "\n🌟 **今日惊喜事件** 🌟\n";
            $message .= "━━━━━━━━━━━━━━━━━\n";
            
            $eventIcons = [
                'meteor_shower' => '🌠',
                'time_capsule' => '⏰', 
                'traffic_rain' => '🌧️',
                'lucky_wheel' => '🎰'
            ];
            
            $eventIcon = $eventIcons[$surpriseEvent['type']] ?? '✨';
            $message .= "{$eventIcon} **{$surpriseEvent['name']}** 事件触发！\n";
            $message .= "🎁 {$surpriseEvent['description']}\n";
            
            if (isset($surpriseEvent['beneficiaries'])) {
                $message .= "👥 受益用户：`{$surpriseEvent['beneficiaries']}` 人\n";
            }
            
            $message .= "\n💫 超级幸运降临，天选之子就是你！\n";
        } else {
            $message .= "💫 每日竞猜，天天有奖，快来参与吧！\n";
        }

        try {
            // 发送消息
            $response = $telegramService->sendMessage($groupId, $message, 'markdown');
            
            // 置顶消息
            if (isset($response->result->message_id)) {
                $messageId = $response->result->message_id;
                $telegramService->pinChatMessage($groupId, $messageId, false);
                $this->info("开奖通知已发送并置顶到群组" . ($surpriseEvent ? "（包含惊喜事件）" : ""));
            } else {
                $this->info("开奖通知已发送，但无法获取消息ID进行置顶");
            }
        } catch (\Exception $e) {
            $this->error("发送开奖通知失败：" . $e->getMessage());
        }
    }

    private function checkSurpriseEvent($date, $results)
    {
        // 随机惊喜事件概率配置（可调整）
        $eventChance = 5; // 5% 概率触发惊喜事件（降低概率）
        
        if (rand(1, 100) > $eventChance) {
            return null; // 今天没有惊喜事件
        }

        // 随机选择惊喜事件类型
        $events = [
            'meteor_shower' => ['name' => '流星雨', 'chance' => 30],
            'time_capsule' => ['name' => '时间胶囊', 'chance' => 25], 
            'traffic_rain' => ['name' => '流量雨', 'chance' => 25],
            'lucky_wheel' => ['name' => '幸运轮盘', 'chance' => 20]
        ];

        $selectedEvent = $this->selectRandomEvent($events);
        
        if (!$selectedEvent) {
            return null;
        }

        $this->info("🌟 触发随机惊喜事件：{$events[$selectedEvent]['name']}");
        
        // 执行惊喜事件
        $eventResult = $this->executeSurpriseEvent($selectedEvent, $date, $results);
        
        return $eventResult;
    }

    private function selectRandomEvent($events)
    {
        $totalChance = array_sum(array_column($events, 'chance'));
        $random = rand(1, $totalChance);
        $currentChance = 0;
        
        foreach ($events as $eventKey => $event) {
            $currentChance += $event['chance'];
            if ($random <= $currentChance) {
                return $eventKey;
            }
        }
        
        return null;
    }

    private function executeSurpriseEvent($eventType, $date, $results)
    {
        switch ($eventType) {
            case 'meteor_shower':
                return $this->meteorShowerEvent($date, $results);
            case 'time_capsule':
                return $this->timeCapsuleEvent($date, $results);
            case 'traffic_rain':
                return $this->trafficRainEvent($date, $results);
            case 'lucky_wheel':
                return $this->luckyWheelEvent($date, $results);
            default:
                return null;
        }
    }

    private function meteorShowerEvent($date, $results)
    {
        // 流星雨：所有参与竞猜的用户额外获得小奖励
        $allParticipants = [];
        $bonusTraffic = rand(5, 15); // 5-15GB随机奖励
        
        foreach ($results as $type => $result) {
            foreach ($result['participants'] as $userId => $participant) {
                if (!isset($allParticipants[$userId])) {
                    $allParticipants[$userId] = $participant;
                    
                    // 发放流星雨奖励
                    $user = User::find($userId);
                    if ($user) {
                        $user->transfer_enable += $bonusTraffic * 1024 * 1024 * 1024;
                        $user->save();
                    }
                }
            }
        }
        
        $this->info("🌠 流星雨事件：{$bonusTraffic}GB奖励已发放给 " . count($allParticipants) . " 位参与者");
        
        return [
            'type' => 'meteor_shower',
            'name' => '流星雨',
            'description' => "所有参与者额外获得 {$bonusTraffic}GB 流量奖励！",
            'beneficiaries' => count($allParticipants),
            'bonus' => $bonusTraffic
        ];
    }

    private function timeCapsuleEvent($date, $results)
    {
        // 时间胶囊：所有参与者账户时长延长
        $allParticipants = [];
        $bonusDays = rand(1, 3); // 1-3天随机奖励
        
        foreach ($results as $type => $result) {
            foreach ($result['participants'] as $userId => $participant) {
                if (!isset($allParticipants[$userId])) {
                    $allParticipants[$userId] = $participant;
                    
                    // 发放时间胶囊奖励
                    $user = User::find($userId);
                    if ($user) {
                        $user->expired_at += $bonusDays * 86400;
                        $user->save();
                    }
                }
            }
        }
        
        $this->info("⏰ 时间胶囊事件：{$bonusDays}天时长已发放给 " . count($allParticipants) . " 位参与者");
        
        return [
            'type' => 'time_capsule',
            'name' => '时间胶囊',
            'description' => "所有参与者账户时长延长 {$bonusDays} 天！",
            'beneficiaries' => count($allParticipants),
            'bonus' => $bonusDays
        ];
    }

    private function trafficRainEvent($date, $results)
    {
        // 流量雨：随机选择部分参与者获得大奖励
        $allParticipants = [];
        foreach ($results as $type => $result) {
            foreach ($result['participants'] as $userId => $participant) {
                if (!isset($allParticipants[$userId])) {
                    $allParticipants[$userId] = $participant;
                }
            }
        }
        
        // 随机选择30%-60%的参与者
        $luckyCount = max(1, floor(count($allParticipants) * (rand(30, 60) / 100)));
        $luckyUsers = array_rand($allParticipants, min($luckyCount, count($allParticipants)));
        
        if (!is_array($luckyUsers)) {
            $luckyUsers = [$luckyUsers];
        }
        
        $bonusTraffic = rand(20, 50); // 20-50GB大奖励
        
        foreach ($luckyUsers as $userIndex) {
            $userId = array_keys($allParticipants)[$userIndex];
            $user = User::find($userId);
            if ($user) {
                $user->transfer_enable += $bonusTraffic * 1024 * 1024 * 1024;
                $user->save();
            }
        }
        
        $this->info("🌧️ 流量雨事件：{$bonusTraffic}GB大奖励已发放给 " . count($luckyUsers) . " 位幸运儿");
        
        return [
            'type' => 'traffic_rain',
            'name' => '流量雨',
            'description' => "{$luckyCount} 位幸运用户获得了 {$bonusTraffic}GB 超级奖励！",
            'beneficiaries' => count($luckyUsers),
            'bonus' => $bonusTraffic
        ];
    }

    private function luckyWheelEvent($date, $results)
    {
        // 幸运轮盘：随机一位参与者获得超级大奖
        $allParticipants = [];
        foreach ($results as $type => $result) {
            foreach ($result['participants'] as $userId => $participant) {
                if (!isset($allParticipants[$userId])) {
                    $allParticipants[$userId] = $participant;
                }
            }
        }
        
        if (empty($allParticipants)) {
            return null;
        }
        
        // 随机选择一位超级幸运儿
        $luckyUserId = array_rand($allParticipants);
        $luckyUser = User::find($luckyUserId);
        
        // 随机奖励类型
        $prizeType = rand(1, 2);
        if ($prizeType === 1) {
            // 超级流量奖励
            $superBonus = rand(100, 200); // 100-200GB
            $luckyUser->transfer_enable += $superBonus * 1024 * 1024 * 1024;
            $prizeDesc = "{$superBonus}GB 超级流量";
        } else {
            // 超级时间奖励
            $superBonus = rand(7, 15); // 7-15天
            $luckyUser->expired_at += $superBonus * 86400;
            $prizeDesc = "{$superBonus} 天超级时长";
        }
        
        $luckyUser->save();
        
        $winnerEmail = $this->hideEmail($allParticipants[$luckyUserId]['email']);
        
        $this->info("🎰 幸运轮盘事件：{$winnerEmail} 获得 {$prizeDesc}");
        
        return [
            'type' => 'lucky_wheel',
            'name' => '幸运轮盘',
            'description' => "超级幸运儿 `{$winnerEmail}` 获得了 {$prizeDesc}！",
            'beneficiaries' => 1,
            'winner' => $winnerEmail,
            'prize' => $prizeDesc
        ];
    }

    private function awardPrize($userId, $type, $prize)
    {
        $user = User::find($userId);
        if (!$user) return;

        if ($type === 'traffic') {
            $user->transfer_enable += $prize * 1024 * 1024 * 1024;
        } else { // time
            $user->expired_at += $prize * 86400;
        }
        
        $user->save();
    }
} 