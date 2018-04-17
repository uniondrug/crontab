<?php
/**
 * 计划任务管理中心
 *
 */

namespace Uniondrug\Crontab;

use Cron\CronExpression;
use Uniondrug\Crontab\Structs\RuntimeTaskStruct;
use Uniondrug\Crontab\Structs\ScheduleStruct;
use Uniondrug\Crontab\Tables\RuntimeTable;
use Uniondrug\Crontab\Tables\ScheduleTable;
use Uniondrug\Framework\Injectable;
use Uniondrug\Server\Task\TaskHandler;

class Crontab extends Injectable
{
    /**
     * 注解名称定义
     */
    const ANNOTATION_NAME = 'Schedule';

    /**
     * @const 任务未执行状态
     */
    const NORMAL = 0;

    /**
     * @const 任务已完成状态（已经投递）
     */
    const FINISH = 1;

    /**
     * @const 任务运行中
     */
    const START = 2;

    /**
     * @var int
     */
    private $maxCount = 1024;

    /**
     * @var ScheduleTable
     */
    private $scheduleTable;

    /**
     * @var RuntimeTable
     */
    private $runtimeTable;

    /**
     * @return bool
     */
    public function init()
    {
        if (!app()) {
            return false;
        }

        $this->initTable(); // 初始化内存表
        $this->initTasks(); // 加载定义的定时任务

        return true;
    }

    /**
     * 初始化内存表
     */
    private function initTable()
    {
        $this->runtimeTable = RuntimeTable::setup($this->maxCount);
        $this->scheduleTable = ScheduleTable::setup($this->maxCount);
    }

    /**
     * 初始化任务清单. 从Task注解中载入任务清单
     *
     * @return bool
     */
    private function initTasks()
    {
        // 注解定义任务
        //
        // 类注解(Class Annotation)
        //
        // @Schedule(cron="* * * * *", second="*", times=5, start="2018-01-01 00:00:00", until="2018-10-10 23:59:59")
        //
        // cron: 定时配置，同Linux的Crontab设置，精度到秒，必须
        // second: 秒数配置，指定在哪一秒执行。* 表示每秒都执行，可选，默认 0
        // times: 执行次数，执行过1次之后就不再执行（不管结果），0 表示不限次数，可选，默认 0
        // until: 结束时间，如果配置到日期，则到当日 23:59:59 结束，可选，默认不结束。
        // start: 开始时间，如果配置到日期，则从当日 00:00:00 开始，可选，默认立刻开始。
        //
        $path = $this->di->appPath() . '/Tasks';
        if (!file_exists($path) || !is_dir($path)) {
            app()->getLogger('crontab')->error(sprintf("$path not exists"));

            return false;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $className = '\\App\\Tasks\\' . pathinfo($item, PATHINFO_FILENAME);
            if (is_a($className, TaskHandler::class, true)) {
                $handlerAnnotations = $this->annotations->get($className);
                if (!is_object($handlerAnnotations)) {
                    continue;
                }
                $classAnnotations = $handlerAnnotations->getClassAnnotations();
                if (!$classAnnotations->has(self::ANNOTATION_NAME)) {
                    continue;
                }
                $scheduleAnnotation = $classAnnotations->get(self::ANNOTATION_NAME);
                $data = $scheduleAnnotation->getArguments();
                if (isset($data['cron'])) {
                    $data['cron'] = str_replace('#', '*', $data['cron']);
                }
                if (isset($data['second'])) {
                    $data['second'] = str_replace('#', '*', $data['second']);
                }
                $scheduleStruct = ScheduleStruct::factory($data);
                $scheduleStruct->handler = $className;

                if (!$scheduleStruct->cron || !CronExpression::isValidExpression($scheduleStruct->cron)) {
                    app()->getLogger('crontab')->error(sprintf("[Handler: %s] Cron: %s is not a valid crontab expression", $scheduleStruct->handler, $scheduleStruct->cron));
                    continue;
                }
                if (!empty($scheduleStruct->start)) {
                    if (preg_match("/^\d\d\d\d\-\d\d\-\d\d$/", $scheduleStruct->start)) {
                        $scheduleStruct->start = $scheduleStruct->start . ' 00:00:00';
                    }
                    if (!preg_match('/^\d\d\d\d\-\d\d\-\d\d \d\d:\d\d:\d\d$/', $scheduleStruct->start)) {
                        app()->getLogger('crontab')->error(sprintf("[Handler: %s] Start: %s is not a valid datetime", $scheduleStruct->handler, $scheduleStruct->start));
                        continue;
                    }
                }
                if (!empty($scheduleStruct->until)) {
                    if (preg_match("/^\d\d\d\d\-\d\d\-\d\d$/", $scheduleStruct->until)) {
                        $scheduleStruct->until = $scheduleStruct->until . ' 23:59:59';
                    }
                    if (!preg_match('/^\d\d\d\d\-\d\d\-\d\d \d\d:\d\d:\d\d$/', $scheduleStruct->until)) {
                        app()->getLogger('crontab')->error(sprintf("[Handler: %s] Until: %s is not a valid datetime", $scheduleStruct->handler, $scheduleStruct->until));
                        continue;
                    }
                }

                app()->getLogger('crontab')->debug(sprintf("[Handler: %s] %s, added", $scheduleStruct->handler, $scheduleStruct->toJson()));

                $this->scheduleTable->add($scheduleStruct);
            }
        }

        return true;
    }

    /**
     * 更新要执行的task
     */
    public function checkTask()
    {
        $this->cleanTable();
        $this->loadDueTasks();
    }

    /**
     * @param $key
     *
     * @return bool
     */
    public function validateTask($key)
    {
        $value = $this->scheduleTable->get($key);
        if (!$value) {
            return false;
        }

        $scheduleStruct = ScheduleStruct::factory($value);
        if ($scheduleStruct->times > 0 && $scheduleStruct->runTimes >= $scheduleStruct->times) {

            app()->getLogger('crontab')->info(sprintf("Task %s run up to max %d times. remove it.", $scheduleStruct->handler, $scheduleStruct->times));

            $this->scheduleTable->del($key);

            return false;
        }

        if (!empty($scheduleStruct->until) && strtotime($scheduleStruct->until) < time()) {

            app()->getLogger('crontab')->info(sprintf("Task %s run up to time %s. remove it.", $scheduleStruct->handler, $scheduleStruct->until));

            $this->scheduleTable->del($key);

            return false;
        }

        return true;
    }

    /**
     * 清理执行任务表
     */
    private function cleanTable()
    {
        // 清理限定次数，或者已经到期的任务
        foreach ($this->scheduleTable as $key => $value) {
            $this->validateTask($key);
        }

        // 清理已经投递完成的任务
        foreach ($this->runtimeTable as $key => $value) {
            if ($value['runStatus'] === self::FINISH) {
                $this->runtimeTable->del($key);
            }
        }
    }

    /**
     * 由管理进程，每分钟运行，取出当前一分钟内需要执行的任务。具体到每秒。
     *
     * 从计划表中找出需要运行的任务, 放入计划运行时表中
     */
    public function loadDueTasks()
    {
        if (count($this->scheduleTable) > 0) {
            foreach ($this->scheduleTable as $key => $schedule) {
                $scheduleStruct = ScheduleStruct::factory($schedule);

                // 未到启动时间
                if (!empty($scheduleStruct->start) && strtotime($scheduleStruct->start) > time()) {
                    continue;
                }

                // 是否到期
                $cron = CronExpression::factory($scheduleStruct->cron);
                if ($cron->isDue()) {
                    $minute = date("YmdHi");

                    $seconds = $this->parseCronNumber($scheduleStruct->second);
                    foreach ($seconds as $second) {
                        $runtimeTaskStruct = RuntimeTaskStruct::factory([
                            'taskKey'   => $key,
                            'handler'   => $scheduleStruct->handler,
                            'minute'    => $minute,
                            'second'    => $second,
                            'runStatus' => self::NORMAL,
                        ]);
                        $this->runtimeTable->add($runtimeTaskStruct);
                    }
                }
            }
        }
    }

    /**
     * 由执行进程每秒执行，获取当前需要运行的任务
     *
     * @return RuntimeTaskStruct[]
     */
    public function getExecTasks()
    {
        $data = [];
        if (count($this->runtimeTable) <= 0) {
            return $data;
        }

        foreach ($this->runtimeTable as $key => $value) {
            $runtimeStruct = RuntimeTaskStruct::factory($value);

            // Validate Tasks. 任务已经完成或者过期，不再处理
            if (!$this->validateTask($runtimeStruct->taskKey)) {
                $this->runtimeTable->del($key);
                continue;
            }

            // 到期并且未运行的
            if (intval(date('s')) == $runtimeStruct->second && $runtimeStruct->runStatus == self::NORMAL) {
                $data[$key] = $runtimeStruct;
            }
        }

        return $data;
    }

    /**
     * 开始任务
     *
     * @param int $key 主键
     *
     * @return bool
     */
    public function startTask($key)
    {
        return $this->runtimeTable->set($key, ['runStatus' => self::START]);
    }

    /**
     * 完成任务
     *
     * @param int $key 主键
     *
     * @return bool
     */
    public function finishTask($key)
    {
        $value = $this->runtimeTable->get($key);
        if ($value) {
            $runtimeStruct = RuntimeTaskStruct::factory($value);
            $this->scheduleTable->incr($runtimeStruct->taskKey, 'runTimes');
        }

        return $this->runtimeTable->set($key, ['runStatus' => self::FINISH]);
    }

    /**
     * @return \Uniondrug\Crontab\Tables\ScheduleTable
     */
    public function getScheduleTable()
    {
        return $this->scheduleTable;
    }

    /**
     * @return \Uniondrug\Crontab\Tables\RuntimeTable
     */
    public function getRunTimeTable()
    {
        return $this->runtimeTable;
    }

    /**
     * @param $s
     * @param $min
     * @param $max
     *
     * @return array
     */
    public function parseCronNumber($s, $min = 0, $max = 59)
    {
        $s = str_replace(' ', '', $s);

        $result = [];
        $v1 = explode(",", $s);
        foreach ($v1 as $v2) {
            $v3 = explode("/", $v2);
            $step = empty($v3[1]) ? 1 : $v3[1];
            $v4 = explode("-", $v3[0]);
            $_min = count($v4) == 2 ? $v4[0] : ($v3[0] == "*" ? $min : $v3[0]);
            $_max = count($v4) == 2 ? $v4[1] : ($v3[0] == "*" ? $max : $v3[0]);
            for ($i = $_min; $i <= $_max; $i += $step) {
                if (intval($i) < $min) {
                    $result[$min] = $min;
                } elseif (intval($i) > $max) {
                    $result[$max] = $max;
                } else {
                    $result[$i] = intval($i);
                }
            }
        }

        ksort($result);

        return $result;
    }
}
