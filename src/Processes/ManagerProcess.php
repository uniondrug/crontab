<?php
/**
 * ManagerProcess.php
 *
 * 管理进程，负责定时检查任务，分派任务
 */

namespace Uniondrug\Crontab\Processes;

use swoole_process;
use Uniondrug\Server\Process;

class ManagerProcess extends Process
{
    /**
     * @param \swoole_process $swoole_process
     *
     * @return callable|void
     */
    public function handle(swoole_process $swoole_process)
    {
        parent::handle($swoole_process);

        process_rename(app()->getName() . ' [CrontabManagerProcess]');

        console()->debug('[Crontab] CrontabManagerProcess started');

        // Run
        if (app()->has('crontabService')) {
            $time = (60 - date('s')) * 1000; // 确保每分钟的0秒
            swoole()->after($time, function () {
                $this->crontabService->checkTask();
                swoole()->tick(60 * 1000, function () {
                    $this->crontabService->checkTask();
                });
            });
        }
    }
}
