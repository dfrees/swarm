<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TagProcessor\Service;

use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Application\Model\IModelDAO;
use Interop\Container\ContainerInterface;
use P4\Spec\Exception\NotFoundException;
use TagProcessor\Filter\ITagFilter;

/**
 * Class Wip
 * @package TagProcessor\Service
 */
class Wip implements IWip, InvokableService
{

    private $services;

    /**
     * Wip service constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * @inheritDoc
     */
    public function checkWip($id)
    {
        $p4Admin   = $this->services->get(ConnectionFactory::P4_ADMIN);
        $filter    = $this->services->get(ITagFilter::WIP_KEYWORD);
        $changeDao = $this->services->get(IModelDAO::CHANGE_DAO);
        $matches   = false;
        if ($id === 'default' || $filter->isDisabled()) {
            return $matches;
        }
        try {
            $change      = $changeDao->fetchById($id, $p4Admin);
            $description = $change->getDescription();
            // check for a change contains keyword in the description
            $matches = $filter->hasMatches($description ?? '');
        } catch (NotFoundException $nfe) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->info('Ran into error with checkWip: '. $nfe->getMessage());
        } catch (\Exception $error) {
            $logger = $this->services->get(SwarmLogger::SERVICE);
            $logger->err('Ran into error with checkWip: '. $error->getMessage());
        }
        return $matches;
    }
}
