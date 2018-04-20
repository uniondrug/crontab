<?php
/**
 * ScheduleTable.php
 *
 */

namespace Uniondrug\Crontab\Tables;

use Uniondrug\Server\Table;

class WorkersTable extends Table
{
    const STATUS_IDLE = 0;

    const STATUS_BUSY = 1;

    protected $columns = [
        'id'     => [Table::TYPE_INT, 4],
        'count'  => [Table::TYPE_INT, 4],
        'status' => [Table::TYPE_INT, 1],
    ];

    /**
     * @param int $processId
     */
    public function add(int $processId, $id)
    {
        $this->set($processId, [
            'id'     => $id,
            'count'  => 0,
            'status' => static::STATUS_IDLE,
        ]);
    }

    public function getBusyCount()
    {
        $count = 0;
        foreach ($this as $pid => $worker) {
            if ($worker['status'] === static::STATUS_BUSY) {
                $count ++;
            }
        }

        return $count;
    }

    public function getIdleCount()
    {
        $count = 0;
        foreach ($this as $pid => $worker) {
            if ($worker['status'] === static::STATUS_IDLE) {
                $count ++;
            }
        }

        return $count;
    }

    public function setIdle(int $processId)
    {
        if ($worker = $this->get($processId)) {
            $worker['status'] = static::STATUS_IDLE;
            $this->set($processId, $worker);
        }
    }

    public function setBusy(int $processId)
    {
        if ($worker = $this->get($processId)) {
            $worker['status'] = static::STATUS_BUSY;
            $this->set($processId, $worker);
        }
    }

    public function getCount(int $processId)
    {
        if ($worker = $this->get($processId)) {
            return $worker['count'];
        }

        return 0;
    }

    public function addCount(int $processId)
    {
        $this->incr($processId, 'count', 1);
    }
}
