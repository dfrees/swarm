<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace TestIntegration\Model;

use Application\Checker;

/**
 * Interface ITest describe the responsibilities of a test definition
 * @package TestIntegration\Model
 */
interface ITestDefinition
{
    const TESTDEFINITION                 = 'testdefinition';
    const TESTDEFINITIONS                = 'testdefinitions';
    const FIELD_HEADERS                  = 'headers';
    const FIELD_TITLE                    = 'title';
    const FIELD_ENCODING                 = 'encoding';
    const FIELD_NAME                     = 'name';
    const FIELD_BODY                     = 'body';
    const FIELD_URL                      = 'url';
    const FIELD_TIMEOUT                  = 'timeout';
    const FIELD_OWNERS                   = 'owners';
    const FIELD_SHARED                   = 'shared';
    const FIELD_DESCRIPTION              = 'description';
    const FIELD_ID                       = 'id';
    const FIELD_ITERATE_PROJECT_BRANCHES = 'iterate';
    const TEST_DEFINITION_CHECKER        = [Checker::NAME => self::TESTDEFINITION];
    const TEST_DEFINITION_OLD            = 'testDefinitionOld';
    const TEST_DEFINITION_NEW            = 'testDefinitionNew';

    /**
     * Get the description
     * @return mixed
     */
    public function getDescription();

    /**
     * Set the description.
     * @param mixed $description
     */
    public function setDescription($description);

    /**
     * Returns an array of owner ids associated with this definition. Owners can
     * be users or groups.
     * @param   bool    $flip       if true array keys are the owner ids (default is false)
     * @return  array   ids of all owners for this workflow
     */
    public function getOwners($flip = false) : array;

    /**
     * Set the owners. Values must be either a valid user or group identifier
     * @param array $owners
     */
    public function setOwners(array $owners);

    /**
     * Get shared
     * @return bool
     */
    public function getShared() : bool;

    /**
     * Set shared
     * @param bool $shared
     */
    public function setShared(bool $shared);

    /**
     * Determines if the user is an owner of the definition by checking individual and group ownership
     * @param string        $userId     user id to test for ownership
     * @return bool true if the user is an individual owner or member of a group that is an owner
     */
    public function isOwner($userId);

    /**
     * Get the name
     * @return string
     */
    public function getName() : string;

    /**
     * Set the name
     * @param string $name
     */
    public function setName(string $name);

    /**
     * Get the url
     * @return string
     */
    public function getUrl() : string;

    /**
     * Set the url
     * @param string $url
     */
    public function setUrl(string $url);

    /**
     * Get the body
     * @return string
     */
    public function getBody();

    /**
     * Set the body (allows null)
     * @param string $body
     */
    public function setBody($body);

    /**
     * Get the encoding
     * @return string
     */
    public function getEncoding() : string;

    /**
     * Set the encoding
     * @param string $encoding
     */
    public function setEncoding(string $encoding);

    /**
     * Get the headers
     * @return mixed
     */
    public function getHeaders();

    /**
     * Set the headers (allow null)
     * @param mixed $headers
     */
    public function setHeaders($headers);

    /**
     * Get the timeout
     * @return mixed
     */
    public function getTimeout();

    /**
     * Set the timeout
     * @param int $timeout
     */
    public function setTimeout($timeout);

    /**
     * Get the iterate
     * @return bool
     */
    public function getIterate(): bool;

    /**
     * Set the iterate.
     * @param bool $iterate
     */
    public function setIterate(bool $iterate);
}
