<?php

namespace App\Services;

use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserDeletionService
{
    /**
     * 删除单个用户及其所有关联数据
     * 
     * @param int $userId
     * @return bool
     * @throws \Exception
     */
    public function deleteUser(int $userId): bool
    {
        DB::beginTransaction();
        try {
            $user = User::find($userId);
            if (!$user) {
                throw new \Exception('用户不存在');
            }

            // 清理用户会话
            $authService = new AuthService($user);
            $authService->removeAllSession();

            // 删除关联数据
            $this->deleteUserRelatedData([$userId]);

            // 删除用户本身
            $user->delete();

            DB::commit();
            Log::info("用户删除成功", ['user_id' => $userId]);
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("用户删除失败", ['user_id' => $userId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * 批量删除用户及其所有关联数据
     * 
     * @param array $userIds
     * @return bool
     * @throws \Exception
     */
    public function batchDeleteUsers(array $userIds): bool
    {
        if (empty($userIds)) {
            return true;
        }

        DB::beginTransaction();
        try {
            // 获取所有要删除的用户
            $users = User::whereIn('id', $userIds)->get();
            
            // 清理所有用户的会话
            foreach ($users as $user) {
                $authService = new AuthService($user);
                $authService->removeAllSession();
            }

            // 批量删除关联数据
            $this->deleteUserRelatedData($userIds);

            // 批量删除用户
            User::whereIn('id', $userIds)->delete();

            DB::commit();
            Log::info("批量删除用户成功", ['user_count' => count($userIds), 'user_ids' => $userIds]);
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("批量删除用户失败", ['user_ids' => $userIds, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * 删除用户关联的所有数据
     * 
     * @param array $userIds
     * @return void
     */
    private function deleteUserRelatedData(array $userIds): void
    {
        // 1. 删除工单消息（解决N+1查询问题）
        $ticketIds = Ticket::whereIn('user_id', $userIds)->pluck('id')->toArray();
        if (!empty($ticketIds)) {
            TicketMessage::whereIn('ticket_id', $ticketIds)->delete();
            Log::debug("删除工单消息", ['ticket_count' => count($ticketIds)]);
        }

        // 2. 删除工单
        Ticket::whereIn('user_id', $userIds)->delete();

        // 3. 删除订单
        Order::whereIn('user_id', $userIds)->delete();

        // 4. 删除邀请码
        InviteCode::whereIn('user_id', $userIds)->delete();

        // 5. 删除佣金记录（之前被遗漏的）
        CommissionLog::whereIn('user_id', $userIds)->delete();
        CommissionLog::whereIn('invite_user_id', $userIds)->delete();

        // 6. 删除用户统计数据（之前被遗漏的）
        StatUser::whereIn('user_id', $userIds)->delete();

        // 7. 更新其他用户的邀请关系
        User::whereIn('invite_user_id', $userIds)->update(['invite_user_id' => null]);

        Log::debug("用户关联数据删除完成", ['user_ids' => $userIds]);
    }

    /**
     * 分批次删除大量用户（避免长事务和内存问题）
     * 
     * @param \Illuminate\Database\Eloquent\Builder $userQuery
     * @param int $chunkSize
     * @return int 删除的用户数量
     */
    public function batchDeleteUsersInChunks($userQuery, int $chunkSize = 50): int
    {
        $totalDeleted = 0;
        $userIds = $userQuery->pluck('id');
        
        Log::info("开始分批删除用户", ['total_users' => $userIds->count(), 'chunk_size' => $chunkSize]);

        $userIds->chunk($chunkSize)->each(function ($chunk) use (&$totalDeleted) {
            try {
                $this->batchDeleteUsers($chunk->toArray());
                $totalDeleted += $chunk->count();
                Log::debug("分批删除进度", ['deleted' => $totalDeleted]);
            } catch (\Exception $e) {
                Log::error("分批删除失败", ['chunk' => $chunk->toArray(), 'error' => $e->getMessage()]);
                throw $e;
            }
        });

        Log::info("分批删除用户完成", ['total_deleted' => $totalDeleted]);
        return $totalDeleted;
    }

    /**
     * 获取用户关联数据统计（用于删除前预览）
     * 
     * @param int $userId
     * @return array
     */
    public function getUserDeletionStats(int $userId): array
    {
        $ticketCount = Ticket::where('user_id', $userId)->count();
        $orderCount = Order::where('user_id', $userId)->count();
        $inviteCodeCount = InviteCode::where('user_id', $userId)->count();
        $commissionLogCount = CommissionLog::where('user_id', $userId)
            ->orWhere('invite_user_id', $userId)->count();
        $statCount = StatUser::where('user_id', $userId)->count();
        $invitedUserCount = User::where('invite_user_id', $userId)->count();

        return [
            'tickets' => $ticketCount,
            'orders' => $orderCount,
            'invite_codes' => $inviteCodeCount,
            'commission_logs' => $commissionLogCount,
            'stat_records' => $statCount,
            'invited_users' => $invitedUserCount,
        ];
    }
}
