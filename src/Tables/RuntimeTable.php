<?php
/**
 * RuntimeTable.php
 *
 */

namespace Uniondrug\Crontab\Tables;

use Uniondrug\Crontab\Structs\RuntimeTaskStruct;
use Uniondrug\Server\Table;

class RuntimeTable extends Table
{
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
     * @var array
     */
    protected $columns = [
        'taskKey'   => [Table::TYPE_STRING, 32],
        'handler'   => [Table::TYPE_STRING, 100],
        'minute'    => [Table::TYPE_STRING, 20],
        'second'    => [Table::TYPE_STRING, 20],
        'runStatus' => [Table::TYPE_INT, 4],
    ];

    /**
     * @param \Uniondrug\Crontab\Structs\RuntimeTaskStruct $value
     */
    public function add(RuntimeTaskStruct $value)
    {
        $key = md5($value->toJson());
        $this->set($key, $value->toArray());
    }

    /**
     * @param $key
     */
    public function setFinish($key)
    {
        if ($item = $this->get($key)) {
            $item['runStatus'] = static::FINISH;
            $this->set($key, $item);
        }
    }

    /**
     * @param $key
     */
    public function setStart($key)
    {
        if ($item = $this->get($key)) {
            $item['runStatus'] = static::START;
            $this->set($key, $item);
        }
    }

    /**
     * @param $key
     */
    public function setNormal($key)
    {
        if ($item = $this->get($key)) {
            $item['runStatus'] = static::NORMAL;
            $this->set($key, $item);
        }
    }

    /**
     * @param $key
     *
     * @return bool
     */
    public function isFinished($key)
    {
        if ($item = $this->get($key)) {
            return $item['runStatus'] === static::FINISH;
        }
        return false;
    }

    /**
     * @param $key
     *
     * @return bool
     */
    public function isStart($key)
    {
        if ($item = $this->get($key)) {
            return $item['runStatus'] === static::START;
        }
        return false;
    }

    /**
     * @param $key
     *
     * @return bool
     */
    public function isNormal($key)
    {
        if ($item = $this->get($key)) {
            return $item['runStatus'] === static::NORMAL;
        }
        return false;
    }
}
