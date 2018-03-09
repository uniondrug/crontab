<?php
/**
 * CrontabServiceProvider.php
 *
 */

namespace Uniondrug\Crontab;

use Phalcon\Di\ServiceProviderInterface;

class CrontabServiceProvider implements ServiceProviderInterface
{
    public function register(\Phalcon\DiInterface $di)
    {
        $crontab = new Crontab();
        $crontab->init();

        $di->setShared('crontabService', $crontab);
    }
}
