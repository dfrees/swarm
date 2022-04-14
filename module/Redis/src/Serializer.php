<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Redis;

use Application\Log\SwarmLogger;
use Interop\Container\ContainerInterface;
use Laminas\Cache\Storage\Event;
use Laminas\Cache\Storage\Plugin\Serializer as LaminasSerializer;

/**
 * Class Serializer. Custom serializer plugin to handle serialization errors
 * @package Redis
 */
class Serializer extends LaminasSerializer
{
    private $services;

    /**
     * Serializer constructor.
     * @param ContainerInterface $services
     */
    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    /**
     * @inheritDoc
     * Override the Zend serializer to catch serialization issues and remove the
     * value from the items being set. This is so that population continues if
     * a record is in error rather than aborting the batch and halting population.
     */
    public function onWriteItemsPre(Event $event)
    {
        $serializer = $this->getOptions()->getSerializer();
        $params     = $event->getParams();
        $logger     = null;
        foreach ($params['keyValuePairs'] as $key => &$value) {
            try {
                $value = $serializer->serialize($value);
            } catch (\Exception $e) {
                unset($params['keyValuePairs'][$key]);
                if (!$logger) {
                    $logger = $this->services->get(SwarmLogger::SERVICE);
                }
                $previous = $e->getPrevious();
                $logger->err(
                    sprintf(
                        "Key [%s] failed to serialize. %s%s%s",
                        $key,
                        "Please contact Perforce support providing this message and any stack trace below",
                        "\n",
                        ($previous ? $previous->getTraceAsString() : $e->getTraceAsString())
                    )
                );
            }
        }
    }
}
