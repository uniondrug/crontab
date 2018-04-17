# Crontab component for uniondrug/framework

定时任务工具

## 安装

```shell
$ cd project-home
$ composer require uniondrug/crontab
```

修改 `app.php` 配置文件，注入服务。服务名称 `crontabService`。

```php
<?php
return [
    'default' => [
        ......
        'providers'           => [
            ......
            \Uniondrug\Crontab\CrontabServiceProvider::class,
        ],
    ],
];
```

修改`server.php`配置文件，启动两个进程：

```php
<?php
return [
    'default' => [
        'host'       => 'http://0.0.0.0:8000',
        'class'      => \Uniondrug\Server\Servitization\Server\HTTPServer::class,
        'options'    => [
            'pid_file'        => __DIR__ . '/../tmp/pid/server.pid',
            'worker_num'      => 8,
            'task_worker_num' => 2,
        ],
        'autoreload' => true,
        'processes'  => [
            \Uniondrug\Crontab\Processes\ManagerProcess::class,     // 增加Crontab管理进程
            \Uniondrug\Crontab\Processes\ExecProcess::class,        // 增加Crontab运行进程
        ],
        ......
    ],
```

## 配置

Crontab的配置文件是 `crontab.php`, 主要有如下参数：

```php
<?php
/**
 * crontab.php  Crontab的运行时配置
 *
 * workerCount   启动的任务工作进程数量
 * workerMaxTask 每个工作进程处理的任务上限，处理到这个数量后，自动重启
 *
 */
return [
    'default' => [
        'workerCount'   => 4,
        'workerMaxTask' => 10,
    ],
];
```

## 使用

Crontab 通过注解定义定时任务。需要定时运行的任务，需要在`app/Tasks`中创建，并且继承 `Uniondrug\Server\Task\TaskHandler`，举例如下：


```php
<?php
/**
 * TestTask.php
 *
 */

namespace App\Tasks;

use Uniondrug\Server\Task\TaskHandler;

/**
 * Class TestTask
 *
 * @package App\Tasks
 * @Schedule(cron="* * * * *", second="*", times=60, start="2018-03-08 21:01:00")
 */
class TestTask extends TaskHandler
{
    private static $i = 0;
    public function handle($data = [])
    {
        self::$i ++;
        console()->debug(__CLASS__ . " called " . self::$i);
        //sleep(40);
    }
}
```

> 注解说明：

* 注解名称 `@Schedule`
* 参数设置：

    - cron   必填。Linux的Crontab格式定义。支持到分钟。
    - second 可选。指定运行的秒。通过这个参数，可以精确到秒来运行任务。默认是 0。支持的格式包括：
        - "*" 每秒
        - "1,4,6" 指定的秒
        - "1-10" 指定的连续秒
    - times  int 可选。重复运行的次数，执行过这个次数之后，就不再运行。默认不限制，无限循环。
    - start  datetime 可选。指定从具体的时间之后开始定时执行。默认从当前开始。如果指定日期，则从当日 00:00:00 开始
    - until  datetime 可选。指定到具体的时间之后停止循环。默认不停止。如果指定到日期，则到当日 23:59:59 结束

* cron 格式说明：

```
      1    2    3    4    5
      *    *    *    *    *
      -    -    -    -    -
      |    |    |    |    |
      |    |    |    |    +----- day of week (0 - 6) (Sunday=0)
      |    |    |    +----- month (1 - 12)
      |    |    +------- day of month (1 - 31)
      |    +--------- hour (0 - 23)
      +----------- min (0 - 59)
```


> 特别说明：

由于在PHP代码中，`*/` 是注释的结束标记，所以 `crontab` 表达式的 `*/5` 无法使用，模块可以在表达式中使用 `#` 代替 `*`。比如：`#/5 # # # #` 这样也是可以的。

## 日志

Crontab的运行日志在`log/corntab`目录中。