<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Validator;

use Application\Connection\ConnectionFactory;
use Laminas\Validator\AbstractValidator;
use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use Application\Config\IDao;

/**
 * Class TestDefinitionExists. Validates existence of a test definition
 * @package TestIntegration\Validator
 */
class TestDefinitionExists extends AbstractValidator implements InvokableService
{
    const UNKNOWN_IDS = 'unknownTestIds';

    private $services;
    protected $unknownIds;

    protected $messageTemplates = [
        self::UNKNOWN_IDS  => "Unknown test id(s): %ids%"
    ];

    protected $messageVariables = [
        'ids' => 'unknownIds'
    ];

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        parent::__construct($options);
    }

    /**
     * Validates an array of test definition ids to ensure they exist in Perforce
     * @param mixed $value  test ids
     * @return bool true if all exist, false otherwise
     */
    public function isValid($value)
    {
        $tdDao      = $this->services->get(IDao::TEST_DEFINITION_DAO);
        $unknownIds = [];
        foreach ((array)$value as $id) {
            if (!$tdDao->exists($id, $this->services->get(ConnectionFactory::P4_ADMIN))) {
                $unknownIds[] = $id;
            }
        }
        if (count($unknownIds)) {
            $this->unknownIds = implode(', ', $unknownIds);
            $this->error(self::UNKNOWN_IDS);
            return false;
        }
        return true;
    }
}
