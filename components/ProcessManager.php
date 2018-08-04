<?php

namespace app\components;

use swoole_process as SwooleProcess;

/**
 * a simple Process Manager
 *
 * Class ProcessUtil
 * @package app\components
 */
class ProcessManager
{

    /**
     * process list maps
     *
     * KEY => [pid => swoole_process]
     * @var array
     */
    protected static $processMap = [];

    /**
     * build success data
     *
     * @param array $data
     * @param string $message
     * @param int $code
     * @return string
     */
    protected static function success($data = [], $message = "success", $code = 0)
    {
        return json_encode(['code' => $code, 'message' => $message, 'data' => $data]);
    }

    /**
     * build error data
     *
     * @param int $code
     * @param string $message
     * @param array $data
     * @return string
     */
    protected static function error($code = 1, $message = "error", $data = [])
    {
        return self::success($data, $message, $code);
    }

    /**
     * submit process task job
     *
     * @param $KEY
     * @param $task func_callback or [obj, method]
     * @throws \Exception
     */
    public static function submitTask($KEY, $task)
    {
        if (!(is_callable($task) || (is_array($task) && count($task) == 2 && is_object($task[0])))) {
            throw new \Exception('parameter task incorrect!');
        }
        $process = new SwooleProcess(function (SwooleProcess $worker) use ($task) {
            try {
                $respData = [];
                if (is_callable($task)) {
                    $respData = $task($worker);
                }
                if (is_array($task)) {
                    $respData = $task[0]->$task[1]();
                }
                $worker->push(static::success($respData));
                $worker->exit(0);
            } catch (\Exception $e) {
                $worker->push(static::error($e->getCode(), $e->getMessage()));
                $worker->exit($e->getMessage());
            }
        });
        $process->useQueue(crc32($KEY));
        $pid = $process->start();
        static::$processMap[$KEY] = array_merge(self::$processMap[$KEY], [
            $pid => $process
        ]);
    }

    /**
     * wait $KEY process list response
     *
     * @param $KEY
     * @param bool $interrupt
     * @return array
     * @throws \Exception
     */
    public static function waitResp($KEY, $interrupt = false)
    {
        $respData = [];
        if (!isset(static::$processMap[$KEY])) {
            throw new \Exception(sprintf('对应进程组: %s, 不存在!', $KEY));
        }
        $processList = self::$processMap[$KEY];
        foreach ($processList as $pid => $process) {
            SwooleProcess::wait();
            $result = json_decode($process->pop(), true);
            if ($result['code'] && $interrupt) {
                $process->freeQueue();
                throw new \Exception($result['message'], $result['code']);
            }
            $respData[] = $result;
        }
        unset(self::$processMap[$KEY]); // 清空 processList
        return $respData;
    }

}

