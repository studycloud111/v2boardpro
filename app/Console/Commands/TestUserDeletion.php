<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestUserDeletion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:user-deletion {--user-id= : 测试删除指定用户ID} {--count=10 : 测试删除用户数量} {--dry-run : 仅测试不实际删除}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试用户删除功能的性能';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $userId = $this->option('user-id');
        $count = $this->option('count');
        $dryRun = $this->option('dry-run');

        if ($userId) {
            $this->testSingleUserDeletion($userId, $dryRun);
        } else {
            $this->testBatchUserDeletion($count, $dryRun);
        }
    }

    private function testSingleUserDeletion($userId, $dryRun = false)
    {
        $this->info("测试单个用户删除性能 (用户ID: {$userId})");
        
        $user = User::find($userId);
        if (!$user) {
            $this->error("用户不存在");
            return;
        }

        $userDeletionService = new UserDeletionService();
        
        // 获取删除统计
        $stats = $userDeletionService->getUserDeletionStats($userId);
        $this->table(
            ['数据类型', '数量'],
            [
                ['工单', $stats['tickets']],
                ['订单', $stats['orders']],
                ['邀请码', $stats['invite_codes']],
                ['佣金记录', $stats['commission_logs']],
                ['统计记录', $stats['stat_records']],
                ['被邀请用户', $stats['invited_users']],
                ['总计', array_sum($stats)]
            ]
        );

        if ($dryRun) {
            $this->info("这是干运行模式，不会实际删除用户");
            return;
        }

        // 测试删除性能
        $startTime = microtime(true);
        $startQueries = DB::getQueryLog();
        DB::enableQueryLog();

        try {
            $userDeletionService->deleteUser($userId);
            $endTime = microtime(true);
            $queries = DB::getQueryLog();
            
            $this->info("✅ 用户删除成功");
            $this->info("⏱️  删除耗时: " . round(($endTime - $startTime) * 1000, 2) . " ms");
            $this->info("🗃️  执行查询数: " . count($queries));
            
            if ($this->option('verbose')) {
                $this->info("SQL查询详情:");
                foreach ($queries as $query) {
                    $this->line("  " . $query['query']);
                }
            }
        } catch (\Exception $e) {
            $this->error("❌ 删除失败: " . $e->getMessage());
        }
    }

    private function testBatchUserDeletion($count, $dryRun = false)
    {
        $this->info("测试批量用户删除性能 (数量: {$count})");
        
        // 查找测试用户（没有重要数据的用户）
        $testUsers = User::where('plan_id', null)
            ->where('transfer_enable', 0)
            ->where('expired_at', 0)
            ->limit($count)
            ->get();

        if ($testUsers->count() < $count) {
            $this->warn("只找到 {$testUsers->count()} 个符合条件的测试用户");
        }

        if ($testUsers->isEmpty()) {
            $this->error("没有找到适合删除的测试用户");
            return;
        }

        $userIds = $testUsers->pluck('id')->toArray();
        $this->info("将删除用户ID: " . implode(', ', $userIds));

        if ($dryRun) {
            $this->info("这是干运行模式，不会实际删除用户");
            return;
        }

        // 测试批量删除性能
        $startTime = microtime(true);
        DB::enableQueryLog();

        try {
            $userDeletionService = new UserDeletionService();
            $deletedCount = $userDeletionService->batchDeleteUsers($userIds);
            
            $endTime = microtime(true);
            $queries = DB::getQueryLog();
            
            $this->info("✅ 批量删除成功");
            $this->info("👥 删除用户数: {$deletedCount}");
            $this->info("⏱️  删除耗时: " . round(($endTime - $startTime) * 1000, 2) . " ms");
            $this->info("🗃️  执行查询数: " . count($queries));
            $this->info("📊 平均每用户耗时: " . round(($endTime - $startTime) * 1000 / $deletedCount, 2) . " ms");
            
        } catch (\Exception $e) {
            $this->error("❌ 批量删除失败: " . $e->getMessage());
        }
    }
}
