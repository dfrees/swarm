<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Log\Writer;

use Queue\Listener\Ping;
use Laminas\Log\Writer\Mock as ZendMock;

/**
 * Extends Zend Mock to ignore events for ping errors by default. These errors
 * will likely to always happen due to missing archive trigger.
 * Should caller want to include these events, call ignorePingEvents(false).
 */
class Mock extends ZendMock
{
    protected $ignorePingEvents    = true;
    protected $ignoreEventsGreater = 7;

    const IGNORE_EVENTS_GREATER = 'ignore_events_greater';

    /**
     * Extend parent to allow setting ignorePingEvents property via options.
     *
     * @param   array|Traversable   $options
     */
    public function __construct($options = null)
    {
        if ($options instanceof \Traversable) {
            $options = iterator_to_array($options);
        }

        if (is_array($options) && isset($options['ignore_ping_events'])) {
            $this->ignorePingEvents($options['ignore_ping_events']);
        }
        if (is_array($options) && isset($options[self::IGNORE_EVENTS_GREATER])) {
            $this->ignoreEventsGreater($options[self::IGNORE_EVENTS_GREATER]);
        }

        parent::__construct($options);
    }

    public function ignoreEventsGreater($level = 7)
    {
        $this->ignoreEventsGreater = (int) $level;
        return $this;
    }

    /**
     * Set whether to ignore ping events.
     *
     * @param   bool    $ignore     optional - true (default) if ping events should be ignored, false otherwise
     * @return  Mock    provides fluent interface
     */
    public function ignorePingEvents($ignore = true)
    {
        $this->ignorePingEvents = (bool) $ignore;
        return $this;
    }

    /**
     * Write a message to the log.
     * Ignore ping events if $includePingEvents is false.
     * Ignore Events that are greater than $ignoreEventsGreater level. Logging level are currently 1 to 8.
     *
     * @param   array   $event  event data
     */
    public function doWrite(array $event)
    {
        // skip if we got a ping event and we are set to ignore these
        if ($this->ignorePingEvents
            && isset($event['message'])
            && stripos($event['message'], Ping::LOG_ERROR_PREFIX) === 0
        ) {
            return;
        } elseif ($this->ignoreEventsGreater
            && isset($event['priority'])
            && $event['priority'] > $this->ignoreEventsGreater
        ) {
            return;
        }

        return parent::doWrite($event);
    }
}
