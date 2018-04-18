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
        $this->maxTask = app()->getConfig()->path('crontab.workerMaxTask', 1024);

        $this->process();
    }

    /**
     * 处理循环
     */
    public function process()
    {
        $counter = 0;
        while (!$this->stop) {
            try {
                // 锁定，争抢
                $this->locker->lock();
                $task = $this->channel->pop();
                if (!$task) {
                    continue;
                }

                // reset connection
                Connections::testConnections();

                // 争抢到一个任务后，释放锁
                $this->locker->unlock();

                // 处理任务
                $data = RuntimeTaskStruct::factory(json_decode($task));
                if ($data->handler) {
                    app()->getShared($data->handler)->handle([]);
                }

                $counter++;
            } catch (\Exception $e) {
                app()->getLogger('crontab')->error(sprintf("[CrontabWorkerProcess] Run task failed: %s", $e->getMessage()));
            }

            // 处理超过一定数量，就退出
            if ($counter >= $this->maxTask) {
                $this->stop = true;
            }
        }

        app()->getLogger('crontab')->info("[CrontabWorkerProcess] Max task processed, restart");
    }
}
