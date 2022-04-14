<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Reviews\Listener;

use Application\Log\SwarmLogger;
use Events\Listener\AbstractEventListener;
use Laminas\EventManager\Event;
use Application\Config\ConfigManager;
use Application\Config\ConfigException;
use Reviews\Model\IReview;
use Reviews\Service\IStatistics;

/**
 * Class Statistics. A listener to calculate review statistics
 * @package Reviews\Listener
 */
class Statistics extends AbstractEventListener
{
    /**
     * Calculate a complexity value on review content change
     * @param Event $event  the event
     */
    public function reviewChanged(Event $event)
    {
        $data = $event->getParam('data');
        // task.review is fired for state change etc, we only want to recalculate if an update has occurred
        // or a file or files have been deleted from the change list
        if ($data && (isset($data['updateFromChange']) || isset($data[IReview::DELFROMCHANGE]))) {
            $class    = get_class($this);
            $logger   = $this->services->get(SwarmLogger::SERVICE);
            $reviewId = $event->getParam(IReview::FIELD_ID);
            $logger->trace(sprintf("%s: Calculating complexity for review [%s]", $class, $reviewId));
            $complexityService = $this->services->get(IStatistics::COMPLEXITY_SERVICE);
            $complexityService->calculateComplexity($reviewId);
            $logger->trace(sprintf("%s: Calculated complexity for review [%s]", $class, $reviewId));
        }
    }

    /**
     * Attaches this event only if the complexity calculation configuration value is set to default to use the
     * Swarm implementation
     * @param mixed $eventName      the event name
     * @param array $eventDetail    the event detail
     * @return bool true if the complexity calculation configuration value is 'default', otherwise false
     * @throws ConfigException
     */
    public function shouldAttach($eventName, $eventDetail)
    {
        $config = $this->services->get(ConfigManager::CONFIG);
        return ConfigManager::getValue(
            $config,
            ConfigManager::REVIEWS_COMPLEXITY_CALCULATION,
            ConfigManager::DEFAULT
        ) === ConfigManager::DEFAULT;
    }
}
