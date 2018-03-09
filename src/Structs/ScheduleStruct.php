<?php

namespace Uniondrug\Crontab\Structs;

use Uniondrug\Structs\Struct;

/**
 * Class ScheduleStruct
 *
 * @package Uniondrug\Crontab\Structs
 */
class ScheduleStruct extends Struct
{
    /**
     * @var string
     */
    public $handler;

    /**
     * Crontab表达式
     *
     * @var string
     *
     *  1    2    3    4    5
     *  *    *    *    *    *
     *  -    -    -    -    -
     *  |    |    |    |    |
     *  |    |    |    |    +----- day of week (0 - 6) (Sunday=0)
     *  |    |    |    +----- month (1 - 12)
     *  |    |    +------- day of month (1 - 31)
     *  |    +--------- hour (0 - 23)
     *  +----------- min (0 - 59)
     */
    public $cron;

    /**
     * 秒。Linux的Crontab具体到分钟。加上这个参数，支持到秒。默认是0秒。可以用"*"表示每秒。
     *
     * @var string
     */
    public $second = '0';

    /**
     * 限定总共执行的次数，0表示不限制。
     *
     * @var int
     */
    public $times = 0;

    /**
     * @var string
     */
    public $start = null;

    /**
     * @var string
     */
    public $until = null;

    /**
     * @var string
     */
    public $addTime = null;

    /**
     * @var int
     */
    public $runTimes = 0;
}
