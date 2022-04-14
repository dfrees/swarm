<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Filter;

use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Filter\FormBoolean;
use Application\Filter\StringToId;
use Application\Filter\ArrayValues;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Model\IModelDAO;
use Application\Validator\FlatArray as FlatArrayValidator;
use Groups\Model\Group;
use Groups\Validator\Groups as GroupsValidator;
use Interop\Container\ContainerInterface;
use P4\Validate\GroupName as GroupNameValidator;
use Projects\Model\Project as ProjectModel;
use Projects\Validator\BranchPath as BranchPathValidator;
use Projects\Validator\Members;
use Record\Exception\NotFoundException;
use TestIntegration\Filter\EncodingValidator;
use Users\Validator\Users as UsersValidator;
use Workflow\Model\IWorkflow;
use Laminas\Filter\StringTrim;

class Project extends InputFilter implements InvokableService
{
    // Reserved strings that cannot be project names
    public static $reserved = ['add', 'edit', 'delete'];

    private $checkWorkflowPermission = true;
    private $toId                    = null;
    private $translator              = null;
    private $p4                      = null;
    private $p4User                  = null;
    private $services                = null;

    /**
     * Project constructor to set up filters.
     * @param ContainerInterface    $services   application services
     * @param array|null            $options    Can contain connection details.
     *
     *                                          If $options[ConnectionFactory::P4] is provided it must reference a
     *                                          ConnectionInterface and will be used for an admin like connection.
     *                                          If $options[ConnectionFactory::P4] is not provided it defaults to
     *                                          $services->get(ConnectionFactory::P4_ADMIN)
     *
     *                                          If $options[ConnectionFactory::P4_USER] is provided it must reference a
     *                                          ConnectionInterface and will be used for an user like connection.
     *                                          If $options[ConnectionFactory::P4_USER] is not provided it defaults to
     *                                          $services->get(ConnectionFactory::P4_USER)
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->toId       = new StringToId;
        $this->p4         = $options[ConnectionFactory::P4]      ?? $services->get(ConnectionFactory::P4_ADMIN);
        $this->p4User     = $options[ConnectionFactory::P4_USER] ?? $services->get(ConnectionFactory::P4_USER);
        $this->services   = $services;
        $this->translator = $this->p4->getService('translator');

        $this->addIdFilter();
        $this->addNameFilter();
        $this->addBooleanValidator(ProjectModel::FIELD_RETAIN_DEFAULT_REVIEWERS, $this->translator);
        $this->addIntegerValidator(ProjectModel::FIELD_MINIMUM_UP_VOTES, $this->translator, true);
        $this->addMembersFilter();
        $this->addSubGroupsFilter();
        $this->addOwnersFilter();
        $this->addPrivateFilter();
        $this->addDefaultsFilter();
        $this->addDescriptionFilter();
        $this->addBranchesFilter();
        $this->addJobViewFilter();
        $this->addEmailFlagsFilter();
        $this->addTestsFilter();
        $this->addDeployFilter();
        $this->addWorkflowFilter();
    }

    /**
     * Ensure that a workflow being linked to is valid and visible to the user linking
     * @param string        $value                      the workflow id
     * @return bool true if valid or errors if not
     */
    public function validateWorkflow($value)
    {
        // Trim manually allowing for null so that this can be used at project and branch level
        $stringTrim = new StringTrim();
        $value      = $stringTrim->filter($value);
        if (isset($value) && !empty($value)) {
            $matchingWorkflow = null;
            try {
                $wfDao            = $this->services->get(IModelDAO::WORKFLOW_DAO);
                $matchingWorkflow = $wfDao->fetch($value, $this->p4);
                if (!$matchingWorkflow->isShared()
                    && ($this->checkWorkflowPermission
                        && !$matchingWorkflow->isOwner($this->p4User->getUser(), $this->p4))) {
                    throw new NotFoundException();
                }
            } catch (NotFoundException $e) {
                // Workflow id does not exist at all
                return $this->translator->t('Workflow with id [%s] was not found.', [$value]);
            }
        }
        return true;
    }

    /**
     * Whether workflow permission should be checked
     * @param boolean       $checkWorkflowPermission    defaults to true. If true any workflow will be checked to
     *                                                  see if it still can be accessed by virtue of being shared or
     *                                                  owned. Sometimes we want this to be disabled (for example
     *                                                  saving from the application for a workflow that previously was
     *                                                  shared but is no longer.
     * @return mixed this filter
     */
    public function setCheckWorkflowPermission($checkWorkflowPermission)
    {
        $this->checkWorkflowPermission = $checkWorkflowPermission;
        return $this;
    }

    /**
     * Get the value of check workflow permission
     *
     * @return bool
     */
    public function getCheckWorkflowPermission()
    {
        return $this->checkWorkflowPermission;
    }

    /**
     * Validates the id. Declare id, but make it optional and rely on name validation.
     * You can place the 'name' into id for adds and it will auto-filter it.
     */
    private function addIdFilter()
    {
        $this->add(
            [
                'name'     => 'id',
                'required' => false,
                'filters'  => [$this->toId]
            ]
        );
    }

    /**
     * Validates subgroups using the validator for groups.
     * @see GroupsValidator
     */
    private function addSubGroupsFilter()
    {
        $this->add(
            [
                'name'       => 'subgroups',
                'required'   => false,
                'filters'    => [new ArrayValues],
                'validators' => [
                    [
                        'name'                   => '\Application\Validator\FlatArray',
                        'break_chain_on_failure' => true
                    ],
                    new GroupsValidator(['connection' => $this->p4])
                ]
            ]
        );
    }

    /**
     * Validates that a project has members or subgroups.
     */
    private function addMembersFilter()
    {
        $membersInput = new DirectInput(ProjectModel::FIELD_MEMBERS);
        $membersInput->getFilterChain()->attach(new ArrayValues);
        $membersInput->getValidatorChain()
            ->attach(new FlatArrayValidator(), true)
            ->attach(new UsersValidator(['connection' => $this->p4]))
            ->attach(new Members($this->translator));
        $this->add($membersInput);
    }

    /**
     * Validates the name
     * - Cannot be empty
     * - Must contain at least one letter or number
     * - Must not already be in use by another project
     */
    private function addNameFilter()
    {
        $this->add(
            [
                'name'       => ProjectModel::FIELD_NAME,
                'filters'    => ['trim'],
                'validators' => [
                    [
                        'name'      => 'NotEmpty',
                        'options'   => [
                            'message' => "Name is required and can't be empty."
                        ]
                    ],
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                $id = $this->toId->filter($value);
                                if (!$id) {
                                    return $this->translator->t('Name must contain at least one letter or number.');
                                }
                                // ensure the Id isn't over the max of 1000.
                                $validator = new GroupNameValidator;
                                $validator->setAllowMaxValue(ProjectModel::MAX_VALUE);
                                if (!$validator->isValid($id)) {
                                    return implode(' ', $validator->getMessages());
                                }
                                // if it isn't an add, we assume the caller will take care
                                // of ensuring existence.
                                if (!$this->isAdd()) {
                                    return true;
                                }

                                // try to get project (including deleted) matching the name
                                $matchingProjects = ProjectModel::fetchAll(
                                    [
                                        ProjectModel::FETCH_INCLUDE_DELETED => true,
                                        ProjectModel::FETCH_BY_IDS          => [$id]
                                    ],
                                    $this->p4
                                );

                                if ($matchingProjects->count() || in_array($id, Project::$reserved)) {
                                    return $this->translator->t(
                                        'This name is taken. Please pick a different name.'
                                    );
                                }
                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Validates the owners using the users validator
     * @see UsersValidator
     */
    private function addOwnersFilter()
    {
        $this->add(
            [
                'name'       => 'owners',
                'required'   => false,
                'filters'    => [new ArrayValues],
                'validators' => [
                    [
                        'name'                   => '\Application\Validator\FlatArray',
                        'break_chain_on_failure' => true
                    ],
                    new UsersValidator(['connection' => $this->p4])
                ]
            ]
        );
    }

    /**
     * Validates that the private flag is of the correct type
     */
    private function addPrivateFilter()
    {
        $this->add(
            [
                'name'              => 'private',
                'required'          => false,
                'continue_if_empty' => true,
                'filters'    => [['name' => FormBoolean::class]],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options'  => [
                            'callback' => function ($value) {
                                if (is_bool($value)) {
                                    return true;
                                } else {
                                    return $this->translator->t(
                                        "%s cannot be translated to a boolean value", [$value]
                                    );
                                }
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Validates defaults for the project (reviewers currently)
     * - default reviewers must be an array of valid users/groups
     */
    private function addDefaultsFilter()
    {
        $this->add(
            [
                'name'              => 'defaults',
                'required'          => false,
                'continue_if_empty' => false,
                'filters'           => [
                    [
                        'name'     => 'Callback',
                        'options'  => [
                            'callback' => function ($value) {
                                // treat empty string as empty array
                                $value = empty($value) ? [] : $value;

                                // normalize the posted default details to only contain our expected keys
                                $defaults = [
                                    'reviewers' => []
                                ];
                                foreach ((array) $value as $name => $default) {
                                    if (isset($defaults[$name])) {
                                        if ($name === 'reviewers' && !empty($default)) {
                                            // If default reviewers have been passed as strings e.g. ['super']
                                            // convert to ['super' => array()], otherwise use the value given
                                            foreach ($default as $defaultKey => $defaultValue) {
                                                if (is_array($defaultValue)) {
                                                    $defaults[$name][$defaultKey] = $defaultValue;
                                                } else {
                                                    $defaults[$name][$defaultValue] = [];
                                                }
                                            }
                                        }
                                    }
                                }
                                return $defaults;
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                // Verify defaults
                                if (!is_array($value)) {
                                    return $this->translator->t(
                                        "'defaults' must be an array but is '%s'.",
                                        [$value]
                                    );
                                }
                                if (isset($value['reviewers'])) {
                                    $usersValidator  =
                                        new UsersValidator(['connection' => $this->p4]);
                                    $groupsValidator =
                                        new GroupsValidator(['connection' => $this->p4]);
                                    // There are defaults verify that default reviewers are users or groups
                                    foreach ((array)$value['reviewers'] as $id => $defaultReviewer) {
                                        if (Group::isGroupName($id)) {
                                            if (!$groupsValidator->isValid(Group::getGroupName($id))) {
                                                return implode(' ', $groupsValidator->getMessages());
                                            }
                                        } elseif (!$usersValidator->isValid($id)) {
                                            return implode(' ', $usersValidator->getMessages());
                                        }
                                    }
                                }
                                return $value;
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Validates that the description is a string and trims whitespace.
     */
    private function addDescriptionFilter()
    {
        $this->add(
            [
                'name'              => ProjectModel::FIELD_DESCRIPTION,
                'required'          => false,
                'continue_if_empty' => true,
                'filters'           => [['name' => 'StringTrim']],
                'validators'        => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                return is_string($value)
                                    ?: $this->translator->t("Description must be a string.");
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Validates branch definition
     * - name must be unique within the project and contain at least 1 letter or number
     * - default reviewers must be valid users/groups
     * - minimum votes should be a valid integer
     * - a workflow id must link to an existing workflow
     * - moderators must be valid users/groups
     */
    private function addBranchesFilter()
    {
        $this->add(
            [
                'name'     => 'branches',
                'required' => false,
                'filters'  => [
                    [
                        'name'    => 'Callback',
                        'options' => [
                            'callback' => function ($value) {
                                // treat empty string as null
                                $value = $value === '' ? null : $value;

                                // exit early if we have not received an array of arrays (or empty array)
                                // the validator will handle these - or in the case of null, simply won't run
                                if (!is_array($value) || in_array(false, array_map('is_array', $value))) {
                                    return $value;
                                }

                                // normalize the posted branch details to only contain our expected keys
                                // also, generate an id (based on name) for entries lacking one
                                $normalized = [];
                                $defaults   = [
                                    'id'                                         => null,
                                    ProjectModel::FIELD_NAME                     => null,
                                    'paths'                                      => '',
                                    'moderators'                                 => [],
                                    'moderators-groups'                          => [],
                                    'defaults'                                   => ['reviewers' => []],
                                    ProjectModel::FIELD_WORKFLOW                 => null,
                                    ProjectModel::FIELD_RETAIN_DEFAULT_REVIEWERS => false,
                                    ProjectModel::FIELD_MINIMUM_UP_VOTES         => null
                                ];
                                foreach ((array) $value as $branch) {
                                    $branch = (array) $branch + $defaults;
                                    $branch = array_intersect_key($branch, $defaults);

                                    $branch['id'] = $this->toId->filter($branch['name']);

                                    // turn paths text input into an array
                                    // trim and remove any empty entries
                                    $paths           = $branch['paths'];
                                    $paths           = is_array($paths) ? $paths : preg_split("/[\n\r]+/", $paths);
                                    $branch['paths'] = array_filter(array_map('trim', $paths), 'strlen');

                                    // Check that the defaults reviewers for a given branch is set and ensure it isn't
                                    // empty as '' can be seen as set but empty.
                                    if (isset($branch['defaults']['reviewers'])
                                        && !empty($branch['defaults']['reviewers'])
                                    ) {
                                        // If default reviewers have been passed as strings e.g. ['super']
                                        // convert to ['super' => array()], otherwise use the value given
                                        foreach ($branch['defaults']['reviewers'] as $defaultKey => $defaultValue) {
                                            if (is_array($defaultValue)) {
                                                $branch['defaults']['reviewers'][$defaultKey] = $defaultValue;
                                            } else {
                                                unset($branch['defaults']['reviewers'][$defaultKey]);
                                                $branch['defaults']['reviewers'][$defaultValue] = [];
                                            }
                                        }
                                    } else {
                                        $branch['defaults']['reviewers'] = [];
                                    }
                                    if (isset($branch[ProjectModel::FIELD_WORKFLOW])
                                        && $branch[ProjectModel::FIELD_WORKFLOW] === IWorkflow::NO_WORKFLOW_ID) {
                                        $branch[ProjectModel::FIELD_WORKFLOW] = null;
                                    }
                                    $branch[ProjectModel::FIELD_MINIMUM_UP_VOTES] =
                                        $this->callbackValidateInteger(
                                            $branch[ProjectModel::FIELD_MINIMUM_UP_VOTES],
                                            ProjectModel::FIELD_MINIMUM_UP_VOTES,
                                            $this
                                        );

                                    $normalized[] = $branch;
                                }

                                return $normalized;
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (!is_array($value)) {
                                    return "Branches must be passed as an array.";
                                }

                                // ensure all branches have a name and id.
                                // also ensure that no id is used more than once.
                                $ids        = [];
                                $branchPath = new BranchPathValidator(['connection' => $this->p4]);
                                foreach ($value as $branch) {
                                    if (!is_array($branch)) {
                                        return $this->translator->t("All branches must be in array form.");
                                    }

                                    if (!strlen($branch['name'])) {
                                        return "All branches require a name.";
                                    }

                                    // given our normalization, we assume an empty id results from a bad name
                                    if (!strlen($branch['id'])) {
                                        return $this->translator->t(
                                            'Branch name must contain at least one letter or number.'
                                        );
                                    }

                                    if (in_array($branch['id'], $ids)) {
                                        return $this->translator->t("Two branches cannot have the same id.") .
                                            ' ' . $this->translator->t("'%s' is already in use.", [$branch['id']]);
                                    }

                                    $validReviewerRetention = $this->validateBoolean(
                                        ProjectModel::FIELD_RETAIN_DEFAULT_REVIEWERS,
                                        $branch[ProjectModel::FIELD_RETAIN_DEFAULT_REVIEWERS],
                                        $this->translator
                                    );
                                    if ($validReviewerRetention !== true) {
                                        return $validReviewerRetention;
                                    }

                                    // Minimum Up votes validator
                                    if (isset($branch[ProjectModel::FIELD_MINIMUM_UP_VOTES])) {
                                        $validMinimumUpVotes = $this->validateIntegerGreaterThanZero(
                                            ProjectModel::FIELD_MINIMUM_UP_VOTES,
                                            $branch[ ProjectModel::FIELD_MINIMUM_UP_VOTES ],
                                            $this->translator
                                        );
                                        // $validMinimumUpVotes will either be the validated int value or an
                                        // error message
                                        if (filter_var($validMinimumUpVotes, FILTER_VALIDATE_INT) === false) {
                                            return $validMinimumUpVotes;
                                        }
                                    }

                                    // validate branch paths
                                    if (!$branchPath->isValid($branch['paths'])) {
                                        return $this->translator->t(
                                            "Error in '%s' branch: ",
                                            [$branch['name']]
                                        ) . implode(' ', $branchPath->getMessages());
                                    }

                                    // verify branch moderators
                                    $usersValidator =
                                        new UsersValidator(['connection' => $this->p4]);
                                    if (!$usersValidator->isValid($branch['moderators'])) {
                                        return implode(' ', $usersValidator->getMessages());
                                    }

                                    // verify branch moderators-groups
                                    if (isset($branch['moderators-groups'])
                                        && !is_array($branch['moderators-groups'])) {
                                        return $this->translator->t(
                                            "Error in '%s' branch 'moderators-groups' must be an array but is '%s'.",
                                            [$branch['name'], $branch['moderators-groups']]
                                        );
                                    }
                                    $groupsValidator = new GroupsValidator(
                                        ['connection' => $this->p4, 'allowProject' => false]
                                    );
                                    if (!$groupsValidator->isValid($branch['moderators-groups'])) {
                                        return implode(' ', $groupsValidator->getMessages());
                                    }

                                    // Verify defaults
                                    if (isset($branch['defaults']) && !is_array($branch['defaults'])) {
                                        return $this->translator->t(
                                            "Error in '%s' branch 'defaults' must be an array but is '%s'.",
                                            [$branch['name'], $branch['defaults']]
                                        );
                                    }
                                    if (isset($branch['defaults']) && isset($branch['defaults']['reviewers'])) {
                                        // There are defaults verify that default reviewers are users or groups
                                        foreach ($branch['defaults']['reviewers'] as $id => $defaultReviewer) {
                                            if (Group::isGroupName($id)) {
                                                if (!$groupsValidator->isValid(Group::getGroupName($id))) {
                                                    return implode(' ', $groupsValidator->getMessages());
                                                }
                                            } elseif (!$usersValidator->isValid($id)) {
                                                return implode(' ', $usersValidator->getMessages());
                                            }
                                        }
                                    }
                                    if (isset($branch[ProjectModel::FIELD_WORKFLOW])) {
                                        $workflowResult = $this->validateWorkflow(
                                            $branch[ProjectModel::FIELD_WORKFLOW]
                                        );
                                        if ($workflowResult !== true) {
                                            return $workflowResult;
                                        }
                                    }
                                    $ids[] = $branch['id'];
                                }
                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Validates the job filter
     * - must be of the format field=value
     */
    private function addJobViewFilter()
    {
        $this->add(
            [
                'name'              => 'jobview',
                'required'          => false,
                'continue_if_empty' => true,
                'filters'           => [['name' => 'StringTrim']],
                'validators'        => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (!is_string($value)) {
                                    return "Job filter must be a string.";
                                }

                                if (!strlen($value)) {
                                    return true;
                                }

                                $filters = preg_split('/\s+/', $value);
                                foreach ($filters as $jobFilter) {
                                    if (!preg_match('/^([^=()|]+)=([^=()|]+)$/', $jobFilter)) {
                                        return $this->translator->t(
                                            "Job filter only supports field=value conditions and the '*' wildcard."
                                        );
                                    }
                                }

                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Validates email flags
     * - must be scalar values
     */
    private function addEmailFlagsFilter()
    {
        $this->add(
            [
                'name'     => 'emailFlags',
                'required' => false,
                'filters'  => [
                    [
                        'name'    => 'Callback',
                        'options' => [
                            'callback' => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }

                                return [
                                    'change_email_project_users'   => isset($value['change_email_project_users'])
                                        ? $value['change_email_project_users']
                                        : true,
                                    'review_email_project_members' => isset($value['review_email_project_members'])
                                        ? $value['review_email_project_members']
                                        : true,
                                ];
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                $flatArrayValidator = new FlatArrayValidator;
                                return $flatArrayValidator->isValid($value)
                                    ?: $this->translator->t(
                                        "Email flags must be an associative array of scalar values."
                                    );
                            }
                        ]
                    ]
                ]
            ]
        );
    }

    /**
     * Validates test settings
     */
    private function addTestsFilter()
    {
        $this->add(
            [
                'name'     => 'tests',
                'required' => false,
                'filters'  => [
                    [
                        'name'    => 'Callback',
                        'options' => [
                            'callback' => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }

                                return [
                                    'enabled'    => isset($value['enabled'])  ? (bool) $value['enabled'] : false,
                                    'url'        => isset($value['url'])      ? $value['url']            : null,
                                    'postBody'   => isset($value['postBody']) ? trim($value['postBody']) : null,
                                    'postFormat' => isset($value['postFormat'])
                                        ? trim($value['postFormat'])
                                        : EncodingValidator::URL,
                                ];
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $this->translator->t(
                                        "Tests must be an associative array of scalar values."
                                    );
                                }
                                if (!is_null($value['url']) && !is_string($value['url'])) {
                                    return $this->translator->t('URL for tests must be a string.');
                                }
                                if ($value['enabled'] && !strlen($value['url'])) {
                                    return $this->translator->t(
                                        'URL for tests must be provided if tests are enabled.'
                                    );
                                }
                                if (!is_null($value['postBody']) && !is_string($value['postBody'])) {
                                    return $this->translator->t('POST Body for tests must be a string.');
                                }

                                // we only support URL and JSON encoded data
                                $format = $value['postFormat'];
                                if (strcasecmp($format, EncodingValidator::URL) === 0
                                    && strcasecmp($format, EncodingValidator::JSON) === 0) {
                                    return $this->translator->t('POST data for tests must be URL or JSON encoded.');
                                }

                                // validate based on format
                                $body = $value['postBody'] ?: '';
                                if (strcasecmp($format, EncodingValidator::URL) === 0) {
                                    parse_str($body, $data);
                                    if (strlen($body) && !count($data)) {
                                        return $this->translator->t(
                                            'POST data expected to be URL encoded, but could not be decoded.'
                                        );
                                    }
                                } else {
                                    $data = @json_decode($body, true);
                                    if (strlen($body) && is_null($data)) {
                                        return $this->translator->t(
                                            'POST data expected to be JSON encoded, but could not be decoded.'
                                        );
                                    }
                                }
                                return true;
                            }
                        ]
                    ]
                ],
            ]
        );
    }

    /**
     * Validates deployment settings
     */
    private function addDeployFilter()
    {
        $this->add(
            [
                'name'     => 'deploy',
                'required' => false,
                'filters'  => [
                    [
                        'name'    => 'Callback',
                        'options' => [
                            'callback'  => function ($value) {
                                // invalid values need to be returned directly to the validator
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $value;
                                }

                                return [
                                    'enabled' => isset($value['enabled']) ? (bool) $value['enabled'] : false,
                                    'url'     => isset($value['url'])     ? $value['url']            : null,
                                ];
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                $flatArrayValidator = new FlatArrayValidator;
                                if (!$flatArrayValidator->isValid($value)) {
                                    return $this->translator->t(
                                        "Deployment settings must be an associative array of scalar values."
                                    );
                                }
                                if (!is_null($value['url']) && !is_string($value['url'])) {
                                    return $this->translator->t('URL for deploy must be a string.');
                                }
                                if ($value['enabled'] && !strlen($value['url'])) {
                                    return $this->translator->t(
                                        'URL for deploy must be provided if deployment is enabled.'
                                    );
                                }

                                return true;
                            }
                        ]
                    ]
                ],
            ]
        );
    }

    /**
     * Validates the workflow
     * - if workflow is set it must link to an existing workflow
     */
    private function addWorkflowFilter()
    {
        $this->add(
            [
                'name'              => ProjectModel::FIELD_WORKFLOW,
                'required'          => false,
                'continue_if_empty' => true,
                'filters'           => [
                    [
                        'name'    => 'Callback',
                        'options' => [
                            'callback' => function ($value) {
                                if (!$value || (isset($value) && $value === IWorkflow::NO_WORKFLOW_ID)) {
                                    return null;
                                } else {
                                    return $value;
                                }
                            }
                        ]
                    ]
                ],
                'validators' => [
                    [
                        'name'    => '\Application\Validator\Callback',
                        'options' => [
                            'callback' => function ($value) {
                                return $this->validateWorkflow($value);
                            }
                        ]
                    ]
                ]
            ]
        );
    }
}
