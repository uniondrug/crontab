<?php
/**
 * ExecProcess.php
 *
 * 执行进程，负责将定时任务派发给工作进程
 */

namespace Uniondrug\Crontab\Processes;

use swoole_process;
use Uniondrug\Server\Process;

class ExecProcess extends Process
{
    /**
     * @param \swoole_process $swoole_process
     *
     * @return callable|void
     */
    public function handle(swoole_process $swoole_process)
    {
        parent::handle($swoole_process);

        process_rename(app()->getName() . ' [CrontabExecProcess]');

        console()->debug('[CrontabExecProcess] process started');
        app()->getLogger('crontab')->debug('[CrontabExecProcess] process started');

        // 待办任务分发
        swoole()->tick(0.5 * 1000, [$this, 'dispatch']);
    }

    /**
     * 从列表中获取任务，分发给工作进程池处理
     */
    public function dispatch()
    {
        // 取出每秒需要运行的任务
        $tasks = $this->crontabService->getExecTasks();
        foreach ($tasks as $key => $runtimeTaskStruct) {
            $this->crontabService->startTask($key);
            try {
                $this->taskDispatcher->dispatchByProcess($runtimeTaskStruct->handler, []);
                $this->crontabService->finishTask($key);
            } catch (\Exception $e) {
                console()->debug('[CrontabExecProcess] Dispatch schedule task failed: ' . $e->getMessage());
                app()->getLogger('crontab')->debug('[CrontabExecProcess] Dispatch schedule task failed: ' . $e->getMessage());
            }
        }
    }
}
