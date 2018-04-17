<?php
/**
 * AbstractProcess.php
 *
 */

namespace Uniondrug\Crontab\Processes;

use swoole_process;
use Uniondrug\Server\Process;

class AbstractProcess extends Process
{
    public function handle(swoole_process $swoole_process)
    {
        parent::handle($swoole_process);

        $this->resetConnections();
    }

    /**
     * 丢弃已经创建的ConnectionInstance。让DI自动重新连接。
     */
    public function resetConnections()
    {
        // Fork一个子进程的时候，父子进程全部重置连接，包括mysql和redis
        foreach (['db', 'dbSlave'] as $serviceName) {
            if (app()->hasSharedInstance($serviceName)) {
                try {
                    app()->getShared($serviceName)->close();
                } catch (\Exception $e) {
                }
            }
            app()->removeSharedInstance($serviceName);
        }
    }

    /**
     * 通过魔术方法调用服务
     *
     * @param $name
     *
     * @return mixed
     */
    public function __get($name)
    {
        if (app()->has($name)) {
            $service = app()->getShared($name);
            $this->$name = $service;
            return $service;
        }

        if ($name == 'di') {
            return app();
        }

        throw new \RuntimeException('Access to undefined property ' . $name);
    }
}
