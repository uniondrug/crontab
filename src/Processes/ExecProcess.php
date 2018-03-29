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
     * @var int
     */
    protected $workerCount = 2;

    /**
     * @var int
     */
    protected $channelSize = 1024;

    /**
     * @var \SplQueue
     */
    protected $tasks;

    /**
     * @var array
     */
    protected $workers = [];

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

        $this->workerCount = app()->getConfig()->path('crontab.workerCount', 2);

        $this->initLocker();
        $this->initChannel();
        $this->initWorkers();
        $this->tasks = new \SplQueue();

        // 定时器回收子进程
        swoole()->tick(1 * 1000, [$this, 'workersManager']);

        // Run
        if (app()->has('crontabService')) {
            swoole()->tick(0.5 * 1000, [$this, 'loadExecTask']);
            swoole()->tick(0.5 * 1000, [$this, 'dispatch']);
        }
    }

    /**
     * 从列表中获取任务，入队
     */
    public function loadExecTask()
    {
        // 取出每秒需要运行的任务
        $tasks = app()->getShared('crontabService')->getExecTasks();
        foreach ($tasks as $key => $runtimeTaskStruct) {
            console()->debug("[CrontabExecProcess] Enqueue task: $key");
            $this->tasks->enqueue(['key' => $key, 'val' => $runtimeTaskStruct]);
            app()->getShared('crontabService')->startTask($key);
        }
    }

    /**
     * 从队列中获取任务，出队，投递
     */
    public function dispatch()
    {
        while (!$this->tasks->isEmpty()) {
            if ($this->getChannelSize() > $this->channelSize) {
                $this->locker->unlock();
                continue;
            }

            $task = $this->tasks->dequeue();
            console()->debug("[CrontabExecProcess] Dequeue task: {$task['key']}");

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
        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->initWorker($i);
        }
    }

    /**
     * @param int $id
     */
    protected function initWorker($id = 0)
    {
        $pid = (new WorkerProcess(app()->getName() . " [CrontabWorkerProcess #$id]"))->configure([
            'channel' => $this->channel,
            'locker'  => $this->locker,
        ])->start();

        if ($pid) {
            // 关联记录工作进程的PID
            $this->workers[$id] = $pid;
            console()->debug("[CrontabExecProcess] Worker[$pid] #$id started");
            app()->getLogger()->debug("[CrontabExecProcess] Worker[$pid] #$id started");
        } else {
            console()->debug("[CrontabExecProcess] Start worker #$id failed");
            app()->getLogger()->error("[CrontabExecProcess] Start worker #$id failed");
        }
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
        while ($ret = swoole_process::wait(false)) {
            $processId = $ret['pid'];
            $code = $ret['code'];

            app()->getLogger('crontab')->info("[CrontabExecProcess] Worker[$processId] exited with code: $code");
            console()->info("[CrontabExecProcess] Worker[$processId] exited with code: $code");

            // 重启一个Worker
            foreach ($this->workers as $id => $pid) {
                if ($pid == $processId) {
                    $this->initWorker($id);
                }
            }
        }
    }
}
