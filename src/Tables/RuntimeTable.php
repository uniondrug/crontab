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
}
