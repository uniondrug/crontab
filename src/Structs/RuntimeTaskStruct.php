<?php
/**
 * RuntimeTaskStruct.php
 *
 */

namespace Uniondrug\Crontab\Structs;

use Uniondrug\Structs\Struct;

class RuntimeTaskStruct extends Struct
{
    /**
     * @var string
     */
    public $taskKey;

    /**
     * @var string
     */
    public $handler;

    /**
     * @var string
     */
    public $minute;

    /**
     * @var int
     */
    public $second;

    /**
     * @var int
     */
    public $runStatus;
}
