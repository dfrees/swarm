<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Changes\Listener;

use Application\Config\ConfigManager;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Events\Listener\AbstractEventListener;
use P4\Spec\Change;
use Queue\Manager as QueueManager;
use Reviews\Model\Review;
use Laminas\EventManager\Event;

class ChangeListener extends AbstractEventListener
{
    public function handleChangeSave(Event $event)
    {
        parent::log($event);
        $delay = ConfigManager::getValue(
            $this->services->get(ConfigManager::CONFIG),
            ConfigManager::QUEUE_WORKER_CHANGE_SAVE_DELAY,
            5000
        ) / 1000;
        $this->services->get(SwarmLogger::SERVICE)->trace('Delay for task.changesaved is ' . $delay);
        // We ignore default changelist as we don't need to add a future task for them.
        if ($event->getParam('id') === 'default') {
            return;
        }
        // schedule the task in the future to handle the synchronization
        $this->services->get(QueueManager::SERVICE)->addTask(
            'changesaved',
            $event->getParam('id'),
            $event->getParam('data'),
            time() + $delay
        );
    }

    public function descriptionSync(Event $event)
    {
        parent::log($event);
        $id      = $event->getParam('id');
        $logger  = $this->services->get(SwarmLogger::SERVICE);
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
        $config  = $this->services->get(ConfigManager::CONFIG);

        // if we are configured to synchronize descriptions,
        // and there is something to synchronize (it's not a new change)
        if (isset($config[ConfigManager::REVIEWS][ConfigManager::SYNC_DESCRIPTIONS])
            && $config[ConfigManager::REVIEWS][ConfigManager::SYNC_DESCRIPTIONS] === true
            && $id !== 'default'
        ) {
            try {
                $change = Change::fetchById($id, $p4Admin);

                // find any associated reviews with this change, and ensure they are updated
                $reviews  = Review::fetchAll([Review::FETCH_BY_CHANGE => $id], $p4Admin);
                $keywords = $this->services->get('review_keywords');
                foreach ($reviews as $review) {
                    $description = $review->getDescription();
                    // An extra newline character is always appear at the end of change description
                    // which results in unnecessary update description activity even when description are same.
                    // To prevent this rtrim is used to trim the extra newline character.
                    if ($description != rtrim($change->getDescription())) {
                        $review->setDescription($keywords->filter($change->getDescription()))->save();
                    } else {
                        continue;
                    }
                    // schedule task.review so @mentions get updated
                    $this->services->get(QueueManager::SERVICE)->addTask(
                        'review',
                        $review->getId(),
                        [
                            'previous'            => ['description' => $description],
                            'isDescriptionChange' => true,
                            'user'                => $change->getUser()
                        ]
                    );
                }
            } catch (\Exception $e) {
                $logger->err($e);
            }
        }
    }
}
