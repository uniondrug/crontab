<?php
/**
 * ExecProcess.php
 *
 */

namespace Uniondrug\Crontab\Processes;

use Swoole\Channel;
use Swoole\Lock;
use swoole_process;
use Uniondrug\Server\Process;
use Uniondrug\Server\Utils\Connections;

class ExecProcess extends Process
{
    /**
     * @var \Swoole\Channel
     */
    protected $channel;

    /**
     * @var \Swoole\Lock
     */
    protected $locker;

    /**
     * 启动时启动的工作进程数量
     *
     * @var int
     */
    protected $initWorkerCount = 2;

    /**
     * 作为备用的进程数量
     *
     * @var int
     */
    protected $idleWorkerCount = 2;

    /**
     * 最大进程数
     *
     * @var int
     */
    protected $maxWorkersCount = 32;

    /**
     * @var int
     */
    protected $channelSize = 1024;

    /**
     * @var \SplQueue
     */
    protected $taskQueue;

    /**
     * @var array
     */
    protected $lastWorkerId = 0;

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

        $this->initWorkerCount = app()->getConfig()->path('crontab.init_worker_count', 2);
        $this->idleWorkerCount = app()->getConfig()->path('crontab.idle_worker_count', 2);

        $this->initLocker();
        $this->initChannel();
        $this->initWorkers();
        $this->taskQueue = new \SplQueue();

        // 工作进程管理器
        swoole()->tick(0.5 * 1000, [$this, 'workersManager']);

        // 待办任务检索器
        swoole()->tick(1 * 1000, [$this, 'loadExecTask']);

        // 待办任务分发器
        swoole()->tick(0.5 * 1000, [$this, 'dispatch']);
    }

    /**
     * 从列表中获取任务，入队
     */
    public function loadExecTask()
    {
        // 取出每秒需要运行的任务
        $tasks = $this->crontabService->getExecTasks();
        foreach ($tasks as $key => $runtimeTaskStruct) {
            $this->taskQueue->enqueue(['key' => $key, 'val' => $runtimeTaskStruct]);
            $this->crontabService->startTask($key);
        }
    }

    /**
     * 从队列中获取任务，出队，投递
     */
    public function dispatch()
    {
        while (!$this->taskQueue->isEmpty()) {
            if ($this->getChannelSize() > $this->channelSize) {
                $this->locker->unlock();
                continue;
            }

            $task = $this->taskQueue->dequeue();
            $this->channel->push($task['val']->toJson());
            $this->locker->unlock();
            app()->getShared('crontabService')->finishTask($task['key']);
        }
    }

    /**
     * 初始化通道。用于与子进程通信。
     */
    protected function initChannel()
    {
        $maxMessageSize = 64 * 1024; // 64K
        try {
            $this->channel = new Channel(2 * $maxMessageSize * $this->channelSize);
        } catch (\Exception $e) {
            console()->error("[CrontabExecProcess] Create channel failed: " . $e->getMessage());
            app()->getLogger('crontab')->error("[CrontabExecProcess] Create channel failed: " . $e->getMessage());
            $this->process->exit(2); // 异常退出
        }
    }

    /**
     * 初始化锁。用于子进程之间争抢消息。
     */
    protected function initLocker()
    {
        try {
            $this->locker = new Lock(SWOOLE_MUTEX);
            $this->locker->lock();
        } catch (\Exception $e) {
            console()->error("[CrontabExecProcess] Create locker failed: " . $e->getMessage());
            app()->getLogger('crontab')->error("[CrontabExecProcess] Create locker failed: " . $e->getMessage());
            $this->process->exit(2); // 异常退出
        }
    }

    /**
     * 根据配置的并发数量，启动相应的数量的工作进程。
     */
    protected function initWorkers()
    {
        for ($i = 0; $i < $this->initWorkerCount; $i++) {
            $this->initWorker();
        }
    }

    /**
     * @param int $id
     */
    protected function initWorker()
    {
        Connections::dropConnections();

        $id = $this->lastWorkerId;
        $pid = (new WorkerProcess(app()->getName() . " [CrontabWorkerProcess #$id]"))->configure([
            'channel' => $this->channel,
            'locker'  => $this->locker,
        ])->start();

        if ($pid) {
            // 关联记录工作进程的PID
            $this->crontabService->getWorkersTable()->add($pid, $id);

            console()->debug("[CrontabExecProcess] Worker[$pid] #$id started");
            app()->getLogger('crontab')->debug("[CrontabExecProcess] Worker[$pid] #$id started");
        } else {
            console()->debug("[CrontabExecProcess] Start worker #$id failed");
            app()->getLogger('crontab')->error("[CrontabExecProcess] Start worker #$id failed");
        }

        $this->lastWorkerId ++;
    }

    /**
     * 获取当前通道的消息数量
     *
     * @return int
     */
    protected function getChannelSize()
    {
        $stat = $this->channel->stats();

        return $stat['queue_num'];
    }

    /**
     * 子进程退出回收定时器
     */
    public function workersManager()
    {
        // 回收退出的子进程
        while ($ret = swoole_process::wait(false)) {
            $processId = $ret['pid'];
            $code = $ret['code'];

            // 从进程列表清除出去
            $this->crontabService->getWorkersTable()->del($processId);

            app()->getLogger('crontab')->info("[CrontabExecProcess] Worker[$processId] exited with code: $code");
            console()->info("[CrontabExecProcess] Worker[$processId] exited with code: $code");
        }

        // 扫描和控制进程数量(多了的进程，会自动退出，少的，这里补上)
        $absent = $this->idleWorkerCount - $this->crontabService->getWorkersTable()->getIdleCount();
        while ($absent > 0 && count($this->crontabService->getWorkersTable()) < $this->maxWorkersCount) {
            $this->initWorker();
            $absent --;
        }
    }
}
