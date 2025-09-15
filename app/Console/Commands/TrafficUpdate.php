<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'traffic:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '流量更新任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        $uploads = Redis::hgetall('v2board_upload_traffic');
        Redis::del('v2board_upload_traffic');
        $downloads = Redis::hgetall('v2board_download_traffic');
        Redis::del('v2board_download_traffic');
        if (empty($uploads) && empty($downloads)) {
            return;
        }

        // 🛡️ 修复数据丢失问题：获取所有有流量的用户(上传+下载)
        $allUserIds = array_unique(array_merge(array_keys($uploads), array_keys($downloads)));
        if (empty($allUserIds)) {
            return; // 没有用户数据，直接返回
        }
        $users = User::whereIn('id', $allUserIds)->get(['id', 'u', 'd']);
        if ($users->isEmpty()) {
            \Log::warning('流量更新：查询到的用户为空', ['user_ids' => $allUserIds]);
            return; // 查询到的用户为空，直接返回
        }
        
        $time = time();
        $casesU = [];
        $casesD = [];
        $idList = [];

        foreach ($users as $user) {
            $upload = $uploads[$user->id] ?? 0;
            $download = $downloads[$user->id] ?? 0;

            $casesU[] = "WHEN {$user->id} THEN " . ($user->u + $upload);
            $casesD[] = "WHEN {$user->id} THEN " . ($user->d + $download);
            $idList[] = $user->id;
        }
        
        // 🛡️ 额外安全检查：确保有数据要更新
        if (empty($idList)) {
            \Log::warning('流量更新：没有用户需要更新');
            return;
        }
        
        $idListStr = implode(',', $idList);
        $casesUStr = implode(' ', $casesU);
        $casesDStr = implode(' ', $casesD);
        $sql = "UPDATE v2_user SET u = CASE id {$casesUStr} END, d = CASE id {$casesDStr} END, t = {$time}, updated_at = {$time} WHERE id IN ({$idListStr})";
        try {
            DB::beginTransaction();
            DB::statement($sql);
            DB::commit();
            
            // 📊 成功日志：记录更新统计
            \Log::info('流量更新成功', [
                'updated_users' => count($idList),
                'total_upload_users' => count($uploads),
                'total_download_users' => count($downloads),
                'execution_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('流量更新失败', [
                'error' => $e->getMessage(),
                'user_count' => count($idList),
                'sql_preview' => substr($sql, 0, 200) . '...'
            ]);
            return;
        }
    }
}
