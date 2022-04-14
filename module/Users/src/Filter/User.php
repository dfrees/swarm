<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Users\Filter;

use Application\Connection\ConnectionFactory;
use Interop\Container\ContainerInterface;
use Laminas\Filter\AbstractFilter;
use Application\Config\IDao;
use P4\Spec\Exception\NotFoundException as SpecNotFoundException;

/**
 * Class User. A filter to convert a user id value to the actual value from the user specification
 * @package Users\Filter
 */
class User extends AbstractFilter
{
    private $services;

    /**
     * User constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * Fetch the user based on the value user id. Designed to be used with a validator. Can be used to help
     * with case insensitivity where the provided value may be valid but the actual value from the spec is
     * required which may be different case.
     * @param string         $value      the user id
     * @return string if no user is found return the value otherwise return the user id from the spec
     */
    public function filter($value)
    {
        try {
            $userDao = $this->services->get(IDao::USER_DAO);
            $value   = $userDao->fetchById($value, $this->services->get(ConnectionFactory::P4_ADMIN))->getId();
        } catch (SpecNotFoundException $e) {
            // Ignore, value will be returned
        }
        return $value;
    }
}
