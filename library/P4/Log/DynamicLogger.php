<?php
/**
 * Created by PhpStorm.
 * User: drobins
 * Date: 12/09/2017
 * Time: 13:28
 */

namespace P4\Log;

/**
 * Class DynamicLogger
 *
 * Allow a logger to be instantiated with additional log levels.
 *
 * @package P4\Log
 */
class DynamicLogger extends \Laminas\Log\Logger
{
    public function __call($name, $args)
    {
        if (!is_callable($this->$name)) {
            return $this->notice(
                sprintf(
                    '%s is not a defined log level. Defined levels are %s',
                    $name,
                    var_export($this->priorities, 1)
                )
            );
        }
        return call_user_func_array($this->$name, $args);
    }

    /**
     * DynamicLogger constructor. Build a new logger and add new priorities into to base array.
     *
     * @param null $options
     */
    public function __construct($options = null)
    {
        parent::__construct($options);
        if (isset($options['priorities'])) {
            foreach ($options['priorities'] as $priority) {
                $this->addLevel((int)$priority['level'], $priority['name']);
            }
        }
    }

    /**
     * Add a new logging level into a logger and include a new named closure of the 'level' name provided.
     *
     * @param $level The priority of these messages
     * @param $name  The name of the level
     */
    public function addLevel($level, $name)
    {
        $logger                    = $this;
        $this->priorities[$level]  = $name;
        $this->{strtolower($name)} = function ($message, $extra = array()) use ($level, &$logger) {
            return $logger->log($level, $message, $extra);
        };
    }
}
