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

        swoole()->tick(1.0 * 1000, [$this, 'checkParent']);

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
}
