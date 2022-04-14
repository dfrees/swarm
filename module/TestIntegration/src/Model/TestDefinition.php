<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Model;

use Application\Validator\OwnersTrait;
use P4\Connection\ConnectionInterface as Connection;
use P4\Exception;
use Record\Exception\NotFoundException;
use Record\Key\AbstractKey;
use Workflow\Model\IWorkflow;

/**
 * Defines a test
 * @package TestIntegration\Model
 */
class TestDefinition extends AbstractKey implements ITestDefinition
{
    use OwnersTrait;
    const KEY_PREFIX = 'swarm-testdefinition-';
    const KEY_COUNT  = 'swarm-testdefinition:count';

    protected $fields = [
        self::FIELD_NAME => [
            self::ACCESSOR => 'getName',
            self::MUTATOR  => 'setName',
            'index'        => 1801
        ],
        self::FIELD_HEADERS => [
            self::ACCESSOR => 'getHeaders',
            self::MUTATOR  => 'setHeaders'
        ],
        self::FIELD_ENCODING => [
            self::ACCESSOR => 'getEncoding',
            self::MUTATOR  => 'setEncoding'
        ],
        self::FIELD_BODY => [
            self::ACCESSOR => 'getBody',
            self::MUTATOR  => 'setBody'
        ],
        self::FIELD_URL => [
            self::ACCESSOR => 'getUrl',
            self::MUTATOR  => 'setUrl'
        ],
        self::FIELD_TIMEOUT => [
            self::ACCESSOR => 'getTimeout',
            self::MUTATOR  => 'setTimeout'
        ],
        self::FIELD_OWNERS => [
            self::ACCESSOR => 'getOwners',
            self::MUTATOR  => 'setOwners'
        ],
        self::FIELD_SHARED => [
            self::ACCESSOR => 'getShared',
            self::MUTATOR  => 'setShared'
        ],
        self::FIELD_DESCRIPTION => [
            self::ACCESSOR => 'getDescription',
            self::MUTATOR  => 'setDescription'
        ],
        self::FIELD_ITERATE_PROJECT_BRANCHES => [
            self::ACCESSOR => 'getIterate',
            self::MUTATOR  => 'setIterate'
        ]
    ];

    /**
     * @inheritDoc
     */
    public function getDescription()
    {
        return $this->getRawValue(self::FIELD_DESCRIPTION);
    }

    /**
     * @inheritDoc
     */
    public function setDescription($description)
    {
        return $this->setRawValue(self::FIELD_DESCRIPTION, $description);
    }

    /**
     * @inheritDoc
     */
    public function getOwners($flip = false): array
    {
        return $this->getSortedField(IWorkflow::OWNERS, $flip);
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function isOwner($userId)
    {
        return $this->isUserAnOwner($this->getConnection(), $userId, $this->getOwners());
    }

    /**
     * @inheritDoc
     */
    public function setOwners(array $owners)
    {
        return $this->setRawValue(self::FIELD_OWNERS, $owners);
    }

    /**
     * @inheritDoc
     */
    public function getShared(): bool
    {
        return $this->getRawValue(self::FIELD_SHARED);
    }

    /**
     * @inheritDoc
     */
    public function setShared(bool $shared)
    {
        return $this->setRawValue(self::FIELD_SHARED, $shared);
    }

    /**
     * @inheritDoc
     */
    public function getName() : string
    {
        return $this->getRawValue(self::FIELD_NAME);
    }

    /**
     * @inheritDoc
     */
    public function setName(string $name)
    {
        return $this->setRawValue(self::FIELD_NAME, $name);
    }

    /**
     * @inheritDoc
     */
    public function getUrl() : string
    {
        return $this->getRawValue(self::FIELD_URL);
    }

    /**
     * @inheritDoc
     */
    public function setUrl(string $url)
    {
        return $this->setRawValue(self::FIELD_URL, $url);
    }

    /**
     * @inheritDoc
     */
    public function getBody()
    {
        return $this->getRawValue(self::FIELD_BODY);
    }

    /**
     * @inheritDoc
     */
    public function setBody($body)
    {
        return $this->setRawValue(self::FIELD_BODY, $body);
    }

    /**
     * @inheritDoc
     */
    public function getEncoding() : string
    {
        return $this->getRawValue(self::FIELD_ENCODING);
    }

    /**
     * @inheritDoc
     */
    public function setEncoding(string $encoding)
    {
        return $this->setRawValue(self::FIELD_ENCODING, $encoding);
    }

    /**
     * @inheritDoc
     */
    public function getHeaders()
    {
        return $this->getRawValue(self::FIELD_HEADERS);
    }

    /**
     * @inheritDoc
     */
    public function setHeaders($headers)
    {
        return $this->setRawValue(self::FIELD_HEADERS, $headers);
    }

    /**
     * @inheritDoc
     */
    public function getTimeout()
    {
        return $this->getRawValue(self::FIELD_TIMEOUT) ?: 0;
    }

    /**
     * @inheritDoc
     */
    public function setTimeout($timeout)
    {
        return $this->setRawValue(self::FIELD_TIMEOUT, $timeout);
    }

    /**
     * @inheritDoc
     */
    public function getIterate(): bool
    {
        return $this->getRawValue(self::FIELD_ITERATE_PROJECT_BRANCHES) ?:  false;
    }

    /**
     * @inheritDoc
     */
    public function setIterate(bool $iterate)
    {
        return $this->setRawValue(self::FIELD_ITERATE_PROJECT_BRANCHES, $iterate);
    }

    /**
     * Finds an item by its id.
     *
     * @param int|string $id the is to find
     * @param Connection $p4 the connection to use
     * @return TestDefinition|AbstractKey
     * @throws NotFoundException
     */
    public static function fetchById($id, Connection $p4 = null)
    {
        $p4 = $p4 ?: parent::getDefaultConnection();
        return parent::fetch($id, $p4);
    }

    /**
     * Override to indicate a custom search expression.
     * @return bool returns true
     */
    protected function isCustomSearchExpression()
    {
        // return true so that the default parent::makeSearchExpression is called to '|' any conditions together
        // using '='. If we do not do this the default behaviour is to build a search expression suffixed with
        // a '*' wildcard. This will result in false positives for a default case searching for unique names
        return true;
    }
}
