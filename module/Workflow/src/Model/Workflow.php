<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Workflow\Model;

use Application\Model\IdTrait;
use Application\Model\IndexTrait;
use Application\Validator\OwnersTrait;
use Record\Exception\NotFoundException;
use Record\Key\AbstractKey;
use P4\Connection\ConnectionInterface as Connection;
use Record\Key\AbstractKey as KeyRecord;
use Workflow\Validator\Rule;
use P4\Exception as P4Exception;

/**
 * Model to define a workflow
 * @package Workflow\Model
 */
class Workflow extends AbstractKey implements IWorkflow
{
    use OwnersTrait;
    use IdTrait;
    use IndexTrait;
    const KEY_PREFIX    = 'swarm-workflow-';
    const UPGRADE_LEVEL = 1;
    const KEY_COUNT     = 'swarm-workflow:count';

    protected $fields = [
        IWorkflow::ON_SUBMIT => [
            'accessor' => 'getOnSubmit',
            'mutator'  => 'setOnSubmit',
            'index'    => 1702,
            'indexer'  => 'indexOnSubmit',
            'indexWords' => true
        ],
        IWorkflow::NAME => [
            'accessor' => 'getName',
            'mutator'  => 'setName',
            'index'    => 1701
        ],
        IWorkflow::DESCRIPTION => [
            'accessor' => 'getDescription',
            'mutator'  => 'setDescription'
        ],
        IWorkflow::SHARED => [
            'accessor'  => 'isShared',
            'mutator'   => 'setShared'
        ],
        IWorkflow::OWNERS => [
            'accessor' => 'getOwners',
            'mutator'  => 'setOwners'
        ],
        IWorkflow::END_RULES => [
            'accessor' => 'getEndRules',
            'mutator'  => 'setEndRules',
            'index'    => 1703,
            'indexer'  => 'indexEndRules',
            'indexWords' => true
        ],
        IWorkflow::AUTO_APPROVE => [
            'accessor' => 'getAutoApprove',
            'mutator'  => 'setAutoApprove',
            'index'    => 1704,
            'indexer'  => 'indexAutoApprove',
            'indexWords' => true
        ],
        IWorkflow::COUNTED_VOTES => [
            'accessor' => 'getCountedVotes',
            'mutator'  => 'setCountedVotes',
            'index'    => 1705,
            'indexer'  => 'indexCountedVotes',
            'indexWords' => true
        ],
        IWorkflow::GROUP_EXCLUSIONS => [
            'accessor' => 'getGroupExclusions',
            'mutator'  => 'setGroupExclusions',
            'index'    => 1706,
            'indexer'  => 'indexGroupExclusions',
            'indexWords' => true
        ],
        IWorkflow::USER_EXCLUSIONS => [
            'accessor' => 'getUserExclusions',
            'mutator'  => 'setUserExclusions',
            'index'    => 1707,
            'indexer'  => 'indexUserExclusions',
            'indexWords' => true
        ],
        IWorkflow::TESTS => [
            'accessor' => 'getTests',
            'mutator'  => 'setTests',
            'index'    => 1708,
            'indexer'  => 'indexTests'
        ],
        IWorkflow::UPGRADE => ['hidden' => true]
    ];

    protected function isCustomSearchExpression()
    {
        return true;
    }

    /**
     * Produces a 'p4 search' expression for the given field/value pairs.
     *
     * Extends parent to put the branches and project level workflow search
     * together.
     *
     * @param   array   $conditions     field/value pairs to search for
     *
     * @return  string  a query expression suitable for use with p4 search
     */
    protected static function makeSearchExpression($conditions)
    {
        return implode(
            ') | (',
            explode(
                ") (",
                str_replace(
                    '|||',
                    '|',
                    implode(
                        '|',
                        explode(
                            " ",
                            parent::makeSearchExpression($conditions)
                        )
                    )
                )
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function getAutoApprove()
    {
        $autoApprove = $this->getRawValue(IWorkflow::AUTO_APPROVE);
        if (!is_array($autoApprove)) {
            $autoApprove = json_decode($autoApprove, true);
        }
        return $autoApprove !== null && $autoApprove !== false ? $autoApprove : [];
    }

    /**
     * @inheritDoc
     */
    public function setAutoApprove($autoApprove)
    {
        return $this->setRawValue(IWorkflow::AUTO_APPROVE, $autoApprove);
    }

    /**
     * @inheritDoc
     */
    public function setCountedVotes($countedVotes)
    {
        return $this->setRawValue(IWorkflow::COUNTED_VOTES, $countedVotes);
    }

    /**
     * @inheritDoc
     */
    public function getCountedVotes()
    {
        $countedVotes = $this->getRawValue(IWorkflow::COUNTED_VOTES);
        if (!is_array($countedVotes)) {
            $countedVotes = json_decode($countedVotes, true);
        }
        return $countedVotes !== null && $countedVotes !== false ? $countedVotes : [];
    }

    /**
     * @inheritDoc
     */
    public function setEndRules($endRules)
    {
        return $this->setRawValue(IWorkflow::END_RULES, $endRules);
    }

    /**
     * @inheritDoc
     */
    public function getEndRules()
    {
        $endRules = $this->getRawValue(IWorkflow::END_RULES);
        if (!is_array($endRules)) {
            $endRules = json_decode($endRules, true);
        }
        return $endRules !== null && $endRules !== false ? $endRules : [];
    }

    /**
     * @see IWorkflow::getOnSubmit()
     */
    public function getOnSubmit()
    {
        $onSubmit = $this->getRawValue(IWorkflow::ON_SUBMIT);
        if (!is_array($onSubmit)) {
            $onSubmit = json_decode($onSubmit, true);
        }
        return $onSubmit !== null && $onSubmit !== false ? $onSubmit : [];
    }

    /**
     * @see IWorkflow::setOnSubmit()
     */
    public function setOnSubmit($onSubmit)
    {
        return $this->setRawValue(IWorkflow::ON_SUBMIT, $onSubmit);
    }

    /**
     * @inheritDoc
     */
    public function isOwner($userId)
    {
        return $this->isUserAnOwner($this->getConnection(), $userId, $this->getOwners());
    }

    /**
     * @see IWorkflow::canEdit()
     */
    public function canEdit($userIdentifier = null)
    {
        if ($userIdentifier instanceof Connection) {
            $editable = $this->isOwner($userIdentifier->getUser()) || $userIdentifier->isSuperUser();
        } elseif (is_string($userIdentifier)) {
            $editable = $this->isOwner($userIdentifier);
        } else {
            $editable = $this->isOwner($this->getConnection()->getUser());
        }
        return $editable;
    }

    /**
     * @see IWorkflow::getName()
     */
    public function getName()
    {
        return $this->getRawValue(IWorkflow::NAME);
    }

    /**
     * @see IWorkflow::setName()
     */
    public function setName($name)
    {
        return $this->setRawValue(IWorkflow::NAME, $name);
    }

    /**
     * @see IWorkflow::getDescription()
     */
    public function getDescription()
    {
        return $this->getRawValue(IWorkflow::DESCRIPTION);
    }

    /**
     * @see IWorkflow::setDescription()
     */
    public function setDescription($description)
    {
        return $this->setRawValue(IWorkflow::DESCRIPTION, $description);
    }

    /**
     * @see IWorkflow::isShared()
     */
    public function isShared()
    {
        return (bool) $this->getRawValue(IWorkflow::SHARED);
    }

    /**
     * @see IWorkflow::setShared()
     */
    public function setShared($shared)
    {
        return $this->setRawValue(IWorkflow::SHARED, (bool) $shared);
    }

    /**
     * @see IWorkflow::getOwners()
     */
    public function getOwners($flip = false)
    {
        return $this->getSortedField(IWorkflow::OWNERS, $flip);
    }

    /**
     * @see IWorkflow::setOwners()
     */
    public function setOwners($owners)
    {
        return $this->setRawValue(IWorkflow::OWNERS, $owners);
    }

    /**
     * @inheritDoc
     */
    public function getGroupExclusions()
    {
        $exclusions = $this->getRawValue(IWorkflow::GROUP_EXCLUSIONS);
        if (!is_array($exclusions)) {
            $exclusions = json_decode($exclusions, true);
        }
        return $exclusions !== null && $exclusions !== false ? $exclusions : [];
    }

    /**
     * @inheritDoc
     */
    public function setGroupExclusions($groupExclusions)
    {
        return $this->setRawValue(IWorkflow::GROUP_EXCLUSIONS, $groupExclusions);
    }

    /**
     * @inheritDoc
     */
    public function getUserExclusions()
    {
        $exclusions = $this->getRawValue(IWorkflow::USER_EXCLUSIONS);
        if (!is_array($exclusions)) {
            $exclusions = json_decode($exclusions, true);
        }
        return $exclusions !== null && $exclusions !== false ? $exclusions : [];
    }

    /**
     * @inheritDoc
     */
    public function setUserExclusions($userExclusions)
    {
        return $this->setRawValue(IWorkflow::USER_EXCLUSIONS, $userExclusions);
    }

    /**
     * Finds an item by its id.
     *
     * @param int|string $id the is to find
     * @param Connection $p4 the connection to use
     * @return Workflow|AbstractKey
     * @throws NotFoundException
     */
    public static function fetchById($id, Connection $p4 = null)
    {
        $p4 = $p4 ?: parent::getDefaultConnection();
        return parent::fetch($id, $p4);
    }

    /**
     * Extends parent to undo our flip logic and hex decode.
     *
     * @param   string  $id     the stored id used by p4 key
     * @return  int             the user facing id
     */
    public static function decodeId($id)
    {
        // nothing to do if the id is null
        if ($id === null) {
            return null;
        }

        // strip off our key prefix
        $id = substr($id, strlen(static::KEY_PREFIX));

        // hex decode it and subtract from 32 bit int to undo our sorting trick
        return (int) (0xFFFFFFFF - hexdec($id));
    }

    /**
     * Provide a simple boolean response indicating whether this workflow contains the global workflow rules.
     *
     * @return bool                  true/false
     */
    public function isGlobal()
    {
        return IWorkflow::GLOBAL_WORKFLOW_ID === $this->getId();
    }

    /**
     * Index the on submit rule values.
     * @param mixed     $onSubmit       on submit to index
     * @return array - the values of with and without review rules
     */
    public function indexOnSubmit($onSubmit)
    {
        $keywords = [];
        if (isset($onSubmit[IWorkflow::WITH_REVIEW][IWorkflow::RULE])) {
            $keywords[] = $onSubmit[IWorkflow::WITH_REVIEW][IWorkflow::RULE];
        }
        if (isset($onSubmit[IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE])) {
            $keywords[] = $onSubmit[IWorkflow::WITHOUT_REVIEW][IWorkflow::RULE];
        }
        return $keywords;
    }

    /**
     * Index the end rules value.
     * @param mixed     $endRules       end rules to index
     * @return string
     */
    public function indexEndRules($endRules)
    {
        $keyword = '';
        if (isset($endRules[IWorkflow::UPDATE][IWorkflow::RULE])) {
            $keyword = $endRules[IWorkflow::UPDATE][IWorkflow::RULE];
        }
        return $keyword;
    }

    /**
     * Index the auto approve value.
     * @param mixed       $autoApprove      auto approve to index
     * @return string
     */
    public function indexAutoApprove($autoApprove)
    {
        return $this->indexField($autoApprove);
    }

    /**
     * Index the counted votes value.
     * @param mixed       $countedVotes     counted votes to index
     * @return string
     */
    public function indexCountedVotes($countedVotes)
    {
        return $this->indexField($countedVotes);
    }

    /**
     * Index the user exclusions.
     * @param mixed       $userExclusions   exclusions to index
     * @return string
     */
    public function indexUserExclusions($userExclusions)
    {
        return $this->indexField($userExclusions);
    }

    /**
     * Index the group exclusions.
     * @param mixed       $groupExclusions        exclusions to index
     * @return string
     */
    public function indexGroupExclusions($groupExclusions)
    {
        return $this->indexField($groupExclusions);
    }

    /**
     * Upgrade this record on save.
     *
     * @param   KeyRecord|null  $stored     an instance of the old record from storage or null if adding
     * @throws P4Exception
     */
    protected function upgrade(KeyRecord $stored = null)
    {
        // For new workflows, set the current level
        if (!$stored) {
            $this->set(IWorkflow::UPGRADE, static::UPGRADE_LEVEL);
            return;
        }

        // For upgraded workflows, do nothing
        if ((int)$stored->get(IWorkflow::UPGRADE) >= static::UPGRADE_LEVEL) {
            return;
        }

        // Make sure all data is written for out-of-date schemas
        $this->original = null;

        // Upgrade to level 1
        //  - add empty user and group exclusion rulesets
        if ((int)$stored->get(IWorkflow::UPGRADE) === 0) {
            $emptyExclusions = [IWorkflow::RULE => [], IWorkflow::MODE => IWorkflow::MODE_INHERIT];
            // On submit
            $withInherit                                             = $stored->get(IWorkflow::ON_SUBMIT);
            $withInherit[IWorkflow::WITH_REVIEW][IWorkflow::MODE]    = IWorkflow::MODE_INHERIT;
            $withInherit[IWorkflow::WITHOUT_REVIEW][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
            $this->set(IWorkflow::ON_SUBMIT, $withInherit);
            // End rules
            $withInherit                                     = $stored->get(IWorkflow::END_RULES);
            $withInherit[IWorkflow::UPDATE][IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
            $this->set(IWorkflow::END_RULES, $withInherit);
            // Auto approve
            $withInherit                  = $stored->get(IWorkflow::AUTO_APPROVE);
            $withInherit[IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
            $this->set(IWorkflow::AUTO_APPROVE, $withInherit);
            // Counted votes
            $withInherit                  = $stored->get(IWorkflow::COUNTED_VOTES);
            $withInherit[IWorkflow::MODE] = IWorkflow::MODE_INHERIT;
            $this->set(IWorkflow::COUNTED_VOTES, $withInherit);
            // Exclusions
            $this->set(IWorkflow::GROUP_EXCLUSIONS, $emptyExclusions);
            $this->set(IWorkflow::USER_EXCLUSIONS, $emptyExclusions);
            $this->set(IWorkflow::TESTS, []);
            $this->repairRuleIndexes($stored);
            $this->set(IWorkflow::UPGRADE, 1);
        }
    }

    /**
     * Removes any obsolete rule value to workflow id instances that were not cleaned up in the past
     * @param KeyRecord|null  $stored     an instance of the old record from storage or null if adding
     * @throws P4Exception
     */
    private function repairRuleIndexes($stored)
    {
        // For each rule remove all possible rule indexes and then add the index currently from the stored entity
        $this->removeIndexValue(
            1702,
            array_unique(array_merge(Rule::VALID_WITH_REVIEW, Rule::VALID_WITHOUT_REVIEW))
        );
        $this->index(1702, self::ON_SUBMIT, $stored->getOnSubmit(), false);

        $this->removeIndexValue(1703, Rule::VALID_END_RULES);
        $this->index(1703, self::END_RULES, $stored->getEndRules(), false);

        $this->removeIndexValue(1704, Rule::VALID_AUTO_APPROVE);
        $this->index(1704, self::AUTO_APPROVE, $stored->getAutoApprove(), false);

        $this->removeIndexValue(1705, Rule::VALID_COUNTED_VOTES);
        $this->index(1705, self::COUNTED_VOTES, $stored->getCountedVotes(), false);

        // We can just 'empty' for non-global - newly created global will be correct
        if (!$this->isGlobal()) {
            $this->setIndexValue(1706, [static::EMPTY_INDEX_VALUE], false);
            $this->setIndexValue(1707, [static::EMPTY_INDEX_VALUE], false);
        }
    }

    /**
     * Index a simple field that specifies rule
     * @param array     $field  the field
     * @return string the index keyword
     */
    private function indexField($field)
    {
        $keyword = '';
        if (isset($field[IWorkflow::RULE])) {
            $keyword = $field[IWorkflow::RULE];
        }
        return $keyword;
    }

    /**
     * @inheritDoc
     */
    public function getTests()
    {
        $tests = $this->getRawValue(IWorkflow::TESTS) ?: [];
        foreach ($tests as &$test) {
            if (!isset($test[IWorkflow::BLOCKS])) {
                $test[IWorkflow::BLOCKS] = IWorkflow::NOTHING;
            }
        }
        return $tests;
    }

    /**
     * @inheritDoc
     */
    public function setTests($tests)
    {
        return $this->setRawValue(IWorkflow::TESTS, $tests);
    }

    /**
     * Indexes the test ids. (should enable quick search for all workflows for a test)
     * @param mixed         $tests      tests to index
     * @return array
     */
    public function indexTests($tests)
    {
        $keywords = [];
        if ($tests) {
            foreach ($tests as $test) {
                $keywords[] = $test[IWorkflow::TEST_ID];
            }
        }
        return $keywords;
    }
}
