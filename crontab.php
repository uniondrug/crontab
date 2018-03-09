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