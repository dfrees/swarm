<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Filter;

use Application\InputFilter\InputFilter;
use Groups\Model\Group;
use Reviews\Model\Review as ReviewModel;
use Reviews\Validator\Reviewers as ReviewersValidator;
use Groups\Validator\Groups as GroupsValidator;
use Application\Model\IModelDAO;
use Users\Filter\User as UserFilter;

/**
 * Defines filters to run for a review.
 * @package Reviews\Filter
 */
class Review extends InputFilter
{
    const COMBINED_REVIEWERS = 'combinedReviewers';
    private $p4;
    private $request;
    private $services;

    /**
     * Review filter constructor.
     * @param ReviewModel       $review             the review
     * @param mixed             $request            the request
     * @param mixed             $services           services to get connection etc.
     * @param array             $transitions        permitted transitions for the review
     * @param boolean           $canEditReviewers   whether reviewers can be edited
     * @param boolean           $canEditAuthor      whether review author can be edited
     * @throws \P4\Spec\Exception\NotFoundException
     */
    public function __construct(
        ReviewModel $review,
        $request,
        $services,
        $transitions,
        $canEditReviewers,
        $canEditAuthor
    ) {
        $this->services = $services;
        $this->p4       = $services->get('p4');
        $this->request  = $request;

        $p4Admin    = $services->get('p4_admin');
        $translator = $services->get('translator');

        // (PHP 5.3 compatibility forces passing some values in as params as
        // $this-> cannot be used in a callback context)
        $this->addAuthor($canEditAuthor, $translator, $p4Admin);
        $this->addState($transitions, $review);
        $this->addCommitStatus($review);
        $this->addTestStatus();
        $this->addTestDetails();
        $this->addDeployDetails();
        $this->addDeployStatus();
        $this->addDescription();
        $this->addPatchUser();
        $this->addJoin();
        $this->addLeave();
        $this->addCombinedReviewers(
            $canEditReviewers,
            $review->getProjects(),
            $review->isValidAuthor() ? $review->getAuthorObject()->getId() : null
        );
        $this->add(Review::getVoteFilterDefinition($review));
        $this->addMode();
    }

    /**
     * Overrides the populate to specifically unset values that were not supplied in a patch
     * request. Patch should not have to supply everything so we should not validate inputs
     * it did not supply.
     */
    protected function populate()
    {
        parent::populate();
        if ($this->request->isPatch()) {
            foreach (array_keys($this->inputs) as $name) {
                $input = $this->inputs[$name];

                if (!isset($this->data[$name]) ||
                    $this->data[$name] == null ||
                    (is_string($this->data[$name]) && trim($this->data[$name]) === '') ||
                    empty($this->data[$name])) {
                    unset($this->data[$name]);
                }
            }
        }
    }

    /**
     * Add filters for the author field. (PHP 5.3 compatibility forces passing these values in as
     * params as $this-> cannot be used in a callback context)
     * @param $canEditAuthor
     * @param $translator
     * @param $p4Admin
     */
    private function addAuthor($canEditAuthor, $translator, $p4Admin)
    {
        $this->add(
            [
                'name'              => 'author',
                'required'          => $this->request->isPatch() === false,
                'continue_if_empty' => true,
                'filters'       => [
                    [
                        'name'      => '\Laminas\Filter\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                // Use a filter to get the real user id from the spec. On a case insensitive server all
                                // cases are valid authors but we want the value as it is in the spec
                                $userFilter = new UserFilter($this->services);
                                return $userFilter->filter($value);
                            }
                        ]
                    ],
                ],
                'validators'    => [
                    // Override the default NotEmpty output with custom message.
                    [
                        'name'                   => 'NotEmpty',
                        // If this validator proves that the value is invalid do not carry on with
                        // any future chained reviews
                        'break_chain_on_failure' => true,
                        'options'                => [
                            'message' => $translator->t("Author is required and cannot be empty.")
                        ]
                    ],
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) use ($canEditAuthor, $translator, $p4Admin) {
                                if (!$canEditAuthor) {
                                    return $translator->t('You do not have permission to change author.');
                                }
                                // For a single string translation replacement it would be OK to not supply the
                                // array as $value is used, but I'm choosing to be specific for clarity
                                $userDAO = $this->services->get(IModelDAO::USER_DAO);
                                if (!$userDAO->exists($value, $p4Admin)) {
                                    return $translator->t("User ('%s') does not exist.", [$value]);
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
     * Add filters for the state field. (PHP 5.3 compatibility forces passing these values in as
     * params as $this-> cannot be used in a callback context)
     * @param $transitions
     * @param $review
     */
    private function addState($transitions, $review)
    {
        // declare state field
        $this->add(
            [
                'name'          => 'state',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) use ($transitions, $review) {
                                if (!in_array($value, array_keys($transitions))) {
                                    return "You cannot transition this review to '%s'.";
                                }

                                // if a commit is already going on, error out for second attempt
                                if ($value == 'approved:commit' && $review->isCommitting()) {
                                    return "A commit is already in progress.";
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
     * Add filters for commit status. (PHP 5.3 compatibility forces passing these values in as
     * params as $this-> cannot be used in a callback context)
     * @param $review
     */
    private function addCommitStatus($review)
    {
        $this->add(
            [
                'name'          => 'commitStatus',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) use ($review) {
                                // if a commit is already going on, don't allow clearing status
                                if ($review->isCommitting()) {
                                    return "A commit is in progress; can't clear commit status.";
                                }

                                if ($value) {
                                    return "Commit status can only be cleared; not set.";
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
     * Add filters for test status.
     */
    private function addTestStatus()
    {
        $this->add(
            [
                'name'          => 'testStatus',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if (!in_array($value, ['pass', 'fail'])) {
                                    return "Test status must be 'pass' or 'fail'.";
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
     * Add filters for test details.
     */
    private function addTestDetails()
    {
        $this->add(
            [
                'name'          => 'testDetails',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\IsArray'
                    ],
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                // if url is set, validate it
                                if (isset($value['url']) && strlen($value['url'])) {
                                    $validator = new \Laminas\Validator\Uri;
                                    if (!$validator->isValid($value['url'])) {
                                        return "Url in test details must be a valid uri.";
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
     * Add filters for deploy status.
     */
    private function addDeployStatus()
    {
        $this->add(
            [
                'name'          => 'deployStatus',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if (!in_array($value, ['success', 'fail'])) {
                                    return "Deploy status must be 'success' or 'fail'.";
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
     * Add filters for deploy details.
     */
    private function addDeployDetails()
    {
        $this->add(
            [
                'name'          => 'deployDetails',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\IsArray'
                    ],
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                // if url is set, validate it
                                if (isset($value['url']) && strlen($value['url'])) {
                                    $validator = new \Laminas\Validator\Uri;
                                    if (!$validator->isValid($value['url'])) {
                                        return "Url in deploy details must be a valid uri.";
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
     * Add filters for description. We normalize line endings to \n to keep git-fusion happy and trim excess whitespace.
     */
    private function addDescription()
    {
        $this->add(
            [
                'name'          => 'description',
                'required'      => $this->request->isPatch() === false,
                'filters'       => [
                    [
                        'name'      => '\Laminas\Filter\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                return preg_replace('/(\r\n|\r)/', "\n", $value);
                            }
                        ]
                    ],
                    'trim'
                ]
            ]
        );
    }

    /**
     * Add filters for patch user pseudo-field for purpose of modifying active participant's properties.
     */
    private function addPatchUser()
    {
        $this->add(
            [
                'name'          => 'patchUser',
                'required'      => false,
                'filters'       => [
                    [
                        'name'      => '\Laminas\Filter\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                // note null/false are handled oddly on older filter_var's
                                // so just leave em be if that's what we came in with
                                $value = (array) $value;
                                if (isset($value['required'])
                                    && !is_null($value['required'])
                                    && !is_bool($value['required'])
                                    && !is_numeric($value['required'])
                                ) {
                                    $value['required'] = filter_var(
                                        $value['required'],
                                        FILTER_VALIDATE_BOOLEAN,
                                        FILTER_NULL_ON_FAILURE
                                    );
                                }

                                if (isset($value['notificationsDisabled'])
                                    && !is_null($value['notificationsDisabled'])
                                    && !is_bool($value['notificationsDisabled'])
                                ) {
                                    $value['notificationsDisabled'] = filter_var(
                                        $value['notificationsDisabled'],
                                        FILTER_VALIDATE_BOOLEAN,
                                        FILTER_NULL_ON_FAILURE
                                    );
                                }
                                return $value;
                            }
                        ]
                    ]
                ],
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                // ensure at least one known property has been provided
                                $knownProperties = count(
                                    array_intersect_key(
                                        (array)$value,
                                        array_flip(['required', 'notificationsDisabled'])
                                    )
                                );
                                if ($knownProperties === 0) {
                                    return "You must specify at least one known property.";
                                }

                                // check that required/notificationsDisabled properties contain valid values
                                $existsAndNull = function ($key) use ($value) {
                                    return array_key_exists($key, $value) && $value[$key] === null;
                                };
                                if ($existsAndNull('required')) {
                                    return "Invalid value specified for required field, expecting true or false";
                                }
                                if ($existsAndNull('notificationsDisabled')) {
                                    return "Invalid value specified for notifications field, expecting true or false";
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
     * Add filters for 'join' pseudo-field for purpose of adding active user as a reviewer.
     */
    private function addJoin()
    {
        $this->add(
            [
                'name'          => 'join',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if ($value != $this->services->get('user')->getId()) {
                                    return "Cannot join review, not logged in as %s.";
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
     * Add filters for 'leave' pseudo-field for purpose of removing active user as a reviewer.
     */
    private function addLeave()
    {
        $this->add(
            [
                'name'          => 'leave',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if ($value != $this->services->get('user')->getId()) {
                                    $validateGroup = new GroupsValidator(['connection' => $this->p4]);
                                    return $validateGroup->isValid(Group::getGroupName($value))?:
                                        "Cannot leave review, not logged in as %s.";
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
     * Filter for the 'combinedReviewers' pseudo-field for purpose of editing the reviewers list.
     * (PHP 5.3 compatibility forces passing these values in as params as $this-> cannot be used in a callback context)
     * @param boolean       $canEditReviewers   whether reviewers can be edited
     * @param array         $projects           the projects from the review to validate against the retention policy
     * @param string|null   $author             the review author if valid, null otherwise
     */
    private function addCombinedReviewers($canEditReviewers, $projects, $author)
    {
        $this->add(
            [
                'name'          => self::COMBINED_REVIEWERS,
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\IsArray'
                    ],
                    [
                        'name'      => '\Laminas\Validator\Callback',
                        'options'   => [
                            'callback' => function ($value) use ($canEditReviewers) {
                                if (!$canEditReviewers) {
                                    return 'You do not have permission to edit reviewers.';
                                }
                                return true;
                            }
                        ]
                    ],
                    new ReviewersValidator($this->p4, $projects, $author),
                ]
            ]
        );
    }

    /**
     * Builds filters for 'vote' pseudo-field for purpose of adding user vote.
     * @param $review
     * @return array the filters
     */
    public static function getVoteFilterDefinition($review)
    {
        return [
            'name'          => 'vote',
            'required'      => false,
            'filters'       => [
                [
                    'name'      => '\Laminas\Filter\Callback',
                    'options'   => [
                        'callback'  => function ($vote) use ($review) {
                            if (!is_array($vote)) {
                                return $vote;
                            }

                            $vote += ['value' => null, 'version' => null];
                            $valid = ['up' => 1, 'down' => -1, 'clear' => 0];
                            if (array_key_exists($vote['value'], $valid)) {
                                $vote['value'] = $valid[$vote['value']];
                            }
                            if (!$vote['version'] || !$review->hasVersion($vote['version'])) {
                                $vote['version'] = null;
                            }

                            return $vote;
                        }
                    ]
                ]
            ],
            'validators'    => [
                [
                    'name'      => '\Application\Validator\Callback',
                    'options'   => [
                        'callback'  => function ($vote) {
                            // only allow 0, -1, 1 as values
                            if (!is_array($vote) || !is_int($vote['value'])
                                || !in_array($vote['value'], [1, -1, 0])
                            ) {
                                return "Invalid vote value";
                            }

                            if ($vote['version'] && !ctype_digit((string) $vote['version'])) {
                                return "Invalid vote version";
                            }

                            return true;
                        }
                    ]
                ]
            ]
        ];
    }

    /**
     * Validate that any provided value for mode is [append|replace]
     */
    private function addMode()
    {
        $this->add(
            [
                'name'          => 'mode',
                'required'      => false,
                'validators'    => [
                    [
                        'name'      => '\Application\Validator\Callback',
                        'options'   => [
                            'callback'  => function ($value) {
                                if (!in_array($value, ['append', 'replace'])) {
                                    return "$value is unsupported for mode, please use 'append' or 'replace'.";
                                }
                                return true;
                            }
                        ]
                    ]
                ]
            ]
        );
    }
}
