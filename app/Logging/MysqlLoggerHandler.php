<?php
namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try{
            $recordArray = $record->toArray();
            
            if(isset($recordArray['context']['exception']) && is_object($recordArray['context']['exception'])){
                $recordArray['context']['exception'] = (array)$recordArray['context']['exception'];
            }
            
            $recordArray['request_data'] = request()->all() ??[];
            $log = [
                'title' => $recordArray['message'],
                'level' => $recordArray['level_name'],
                'host' => $recordArray['request_host'] ?? request()->getSchemeAndHttpHost(),
                'uri' => $recordArray['request_uri'] ?? request()->getRequestUri(),
                'method' => $recordArray['request_method'] ?? request()->getMethod(),
                'ip' => request()->getClientIp(),
                'data' => json_encode($recordArray['request_data']),
                'context' => isset($recordArray['context']) ? json_encode($recordArray['context']) : '',
                'created_at' => strtotime($recordArray['datetime']),
                'updated_at' => strtotime($recordArray['datetime']),
            ];

            LogModel::insert(
                $log
            );
        }catch (\Exception $e){
            Log::channel('daily')->error($e->getMessage().$e->getFile().$e->getTraceAsString());
        }
    }
}
