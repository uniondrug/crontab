<?php
/**
 * ScheduleTable.php
 *
 */

namespace Uniondrug\Crontab\Tables;

use Uniondrug\Crontab\Structs\ScheduleStruct;
use Uniondrug\Server\Table;

class ScheduleTable extends Table
{
    protected $columns = [
        'handler'  => [Table::TYPE_STRING, 100],
        'cron'     => [Table::TYPE_STRING, 100],
        'second'   => [Table::TYPE_STRING, 64],
        'times'    => [Table::TYPE_INT, 4],
        'start'    => [Table::TYPE_STRING, 20],
        'until'    => [Table::TYPE_STRING, 20],
        'addTime'  => [Table::TYPE_STRING, 16],
        'runTimes' => [Table::TYPE_INT, 1],
    ];

    /**
     * @param \Uniondrug\Crontab\Structs\ScheduleStruct $scheduleStruct
     */
    public function add(ScheduleStruct $scheduleStruct)
    {
        $key = md5($scheduleStruct->toJson());
        $scheduleStruct->addTime = time();
        $this->set($key, $scheduleStruct->toArray());
    }
}
