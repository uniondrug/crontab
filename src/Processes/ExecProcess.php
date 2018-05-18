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

        console()->debug('[Crontab] CrontabExecProcess started');

        // 待办任务分发
        swoole()->tick(0.5 * 1000, [$this, 'dispatch']);

        swoole()->tick(1.0 * 1000, [$this, 'checkParent']);
    }

    /**
     * @return bool|void
     */
    public function checkParent()
    {
        $res = parent::checkParent();
        if (!$res) {
            console()->debug("[Crontab] Parent has gone away, quit");

            $this->process->exit(0);
        }
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
                console()->debug('[Crontab] Handler=%s, dispatching', $runtimeTaskStruct->handler);

                $this->taskDispatcher->dispatchByProcess($runtimeTaskStruct->handler, []);
                $this->crontabService->finishTask($key);

                console()->debug('[Crontab] Handler=%s, dispatched', $runtimeTaskStruct->handler);
            } catch (\Exception $e) {
                console()->debug('[Crontab] Handler=%s, dispatch failed: ' . $e->getMessage());
            }
        }
    }
}
