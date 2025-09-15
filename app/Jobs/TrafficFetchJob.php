<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // 🚀 性能优化：使用Redis管道批量操作，显著减少网络往返次数
        $pipe = Redis::pipeline();
        
        foreach(array_keys($this->data) as $userId){
            $uploadTraffic = $this->data[$userId][0] * $this->server['rate'];
            $downloadTraffic = $this->data[$userId][1] * $this->server['rate'];
            
            $pipe->hincrby('v2board_upload_traffic', $userId, $uploadTraffic);
            $pipe->hincrby('v2board_download_traffic', $userId, $downloadTraffic);
        }
        
        // 一次性执行所有Redis操作，大幅提升性能
        try {
            $pipe->execute();
        } catch (\Exception $e) {
            // 📊 记录管道执行失败的详细信息，便于问题排查
            \Log::error('Redis管道执行失败', [
                'job' => 'TrafficFetchJob',
                'user_count' => count($this->data),
                'server_id' => $this->server['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            throw $e; // 重新抛出异常，确保任务失败处理正常
        }
    }
}
