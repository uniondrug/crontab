<?php
/**
 * WorkerProcess.php
 *
 */

namespace Uniondrug\Crontab\Processes;

use swoole_process;
use Uniondrug\Crontab\Structs\RuntimeTaskStruct;
use Uniondrug\Server\Process;
use Uniondrug\Server\Utils\Connections;

class WorkerProcess extends Process
{
    /**
     * @var int
     */
    protected $maxTask = 1024;

    /**
     * @var \Swoole\Channel
     */
    protected $channel;

    /**
     * @var \Swoole\Lock
     */
    protected $locker;

    /**
     * @var bool
     */
    protected $stop = false;

    /**
     * @param \swoole_process $swoole_process
     *
     * @return callable|void
     */
    public function handle(swoole_process $swoole_process)
    {
        parent::handle($swoole_process);

        console()->info('[CrontabWorkerProcess] process started');
        app()->getLogger('crontab')->info('[CrontabWorkerProcess] process started');

        $this->channel = $this->getOption('channel');
        $this->locker = $this->getOption('locker');
        $this->maxTask = app()->getConfig()->path('crontab.worker_max_process', 1024);

        $this->process();

        $this->process->exit(0);
    }

    /**
     * 处理循环
     */
    public function process()
    {
        while (!$this->stop) {
            try {
                // 锁定，争抢
                $this->locker->lock();
                $task = $this->channel->pop();
                if (!$task) {
                    continue;
                }

                // 争抢到一个任务后，释放锁，让其他工作进程可以并发
                $this->locker->unlock();

                // 设置当前工作进程为工作中
                $this->crontabService->getWorkersTable()->setBusy($this->process->pid);

                // 准备数据库连接
                Connections::testConnections();

                // 处理任务
                $data = RuntimeTaskStruct::factory(json_decode($task));
                if ($data->handler) {
                    app()->getShared($data->handler)->handle([]);
                }

                // 处理任务计数器增加
                $this->crontabService->getWorkersTable()->addCount($this->process->pid);

                // 设置当前工作进程为闲置中
                $this->crontabService->getWorkersTable()->setIdle($this->process->pid);

            } catch (\Exception $e) {
                app()->getLogger('crontab')->error(sprintf("[CrontabWorkerProcess] Run task failed: %s", $e->getMessage()));
            }

            // 处理超过一定数量，或者闲置进程过多，就自动退出
            if ($this->crontabService->getWorkersTable()->getCount($this->process->pid) >= $this->maxTask) {
                $this->stop = true;
            }
        }

        app()->getLogger('crontab')->info("[CrontabWorkerProcess] process stopped");
    }
}
