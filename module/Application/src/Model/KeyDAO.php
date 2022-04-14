<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Model;

use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use P4\OutputHandler\Limit;
use Record\Key\AbstractKey;

class KeyDAO implements InvokableService
{
    protected $services;
    protected $connection;

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services   = $services;
        $this->connection = $services->get(ConnectionFactory::P4_ADMIN);
    }

    /**
     * Breaks out the case of fetching by doing a 'p4 search'
     * Options such as max/after will still be honored.
     *
     * @param array  $options normalized fetch options (e.g. search/max/after)
     * @param string $prefix  The prefix to use.
     * @return mixed      the list of zero or more matching record objects
     */
    public function search($options, $prefix)
    {
        $defaults = [
            AbstractKey::FETCH_AFTER => null,
            AbstractKey::FETCH_MAXIMUM => null,
            AbstractKey::FETCH_SEARCH => null,
            AbstractKey::FETCH_TOTAL_COUNT => null,
            AbstractKey::FILTER_MAX => null,
        ];

        $options += $defaults;

        $connection = $this->connection;
        // pull out search/max/after/countAll
        $max = $options[AbstractKey::FETCH_MAXIMUM];
        if (isset($options[AbstractKey::FILTER_MAX]) && $options[AbstractKey::FILTER_MAX] > $max) {
            // The post fetch filtering wants lots of data, allow that to be greedy
            $max = $options[AbstractKey::FILTER_MAX];
        }

        $after    = $options[AbstractKey::FETCH_AFTER];
        $search   = $options[AbstractKey::FETCH_SEARCH];
        $countAll = $options[AbstractKey::FETCH_TOTAL_COUNT];
        $params   = [$search];
        $isAfter  = false;

        // if we are not counting all and we have a max but no after
        // we can use -m on new enough servers as an optimization
        if (!$countAll && $max && !$after && $connection->isServerMinVersion('2013.1')) {
            array_unshift($params, $max);
            array_unshift($params, '-m');
        }

        // setup an output handler to ensure max and after are honored
        $handler = new Limit;
        $handler->setMax($max)
                ->setCountAll($countAll)
                ->setFilterCallback(
                    function ($data) use ($after, &$isAfter, $prefix) {
                        // be defensive, exclude any ids that lack our key prefix (if we have one)
                        if ($prefix && strpos($data, $prefix) !== 0) {
                            return Limit::FILTER_EXCLUDE;
                        }

                        if ($after && !$isAfter) {
                            $isAfter = $after == $data;
                            return Limit::FILTER_SKIP;
                        }

                        return Limit::FILTER_INCLUDE;
                    }
                );
        return $connection->runHandler($handler, 'search', $params)->getData();
    }
}
