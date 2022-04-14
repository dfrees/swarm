<?php
namespace Reviews\Filter;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Config\IConfigDefinition;
use Application\Connection\ConnectionFactory;
use Application\Helper\ArrayHelper;
use Application\InputFilter\InputFilter;
use Application\Permissions\ConfigCheck;
use Groups\Model\Group;
use Interop\Container\ContainerInterface;
use Reviews\Model\IReview;
use Reviews\UpdateService;
use Reviews\Validator\Reviewers as ReviewersValidator;
use Laminas\InputFilter\Input;
use Laminas\Http\Request as HttpRequest;
use Exception;

/**
 * Class Participants to filter and validate Participants
 * @package Reviews\Filter
 */
class Participants extends InputFilter implements IParticipants
{
    private $review;
    private $p4Admin;
    private $services;
    private $reviewersValidator;
    private $validateIds;

    /**
     * Participants filter constructor.
     * @param ContainerInterface $services application services
     * @param array|null $options
     * @throws Exception
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services    = $services;
        $this->p4Admin     = $services->get(ConnectionFactory::P4_ADMIN);
        $this->review      = $options[self::REVIEW];
        $this->validateIds = $options[self::VALIDATE_IDS] ?? true;
        $this->addParticipantsFilter();
    }

    /**
     * It takes the review model and participant data. Remove the blacklist users and then
     * arrange the participant data to correct format so that it can validate using participants
     * filter. Below is the example response:
     * [
     *      "bruno" => [],
     *      "testUser" => [ "required" => 1 ],
     *      "bob" => [ "vote" => [ "value" => 1, "version" => 1, "isStale" => false] ]
     * ]
     * @param array $data Participants data
     * @param string $method request method value
     * @return array|null
     * @throws ConfigException
     * @throws Exception
     */
    public function getCombinedReviewers($data, $method)
    {
        $data              = $this->removeExcludedReviewers($data);
        $combinedReviewers = null;
        $requiredReviewers = $data[IReview::REQUIRED_REVIEWERS] ?? null;
        $reviewers         =
            isset($data[IReview::REVIEWERS])
                ? is_array($data[IReview::REVIEWERS])
                ? $data[IReview::REVIEWERS] : explode(",", $data[IReview::REVIEWERS])
                : null;
        if ($reviewers != null || $requiredReviewers !== null) {
            $combinedReviewers = [];
        }
        if ($reviewers != null) {
            array_map(
                function ($value) use (&$combinedReviewers) {
                    $combinedReviewers[$value] = [];
                },
                array_unique(array_merge($reviewers, (array) $requiredReviewers))
            );
        }
        if ($requiredReviewers !== null) {
            $reviewerQuorums = $data[IReview::REVIEWER_QUORUMS] ?? [];
            array_map(
                function ($key, $value) use (&$combinedReviewers) {
                    $combinedReviewers[$key][IParticipants::REQUIRED] = $value;
                },
                array_keys($reviewerQuorums),
                $reviewerQuorums
            );
            array_map(
                function ($value) use (&$combinedReviewers) {
                    // Groups may already have been set from the quorums field
                    if (!isset($combinedReviewers[$value][IParticipants::REQUIRED])) {
                        $combinedReviewers[$value][IParticipants::REQUIRED] = true;
                    }
                },
                $requiredReviewers
            );
        }
        $projects                 = $this->review->getProjects();
        $defaultRetainedReviewers = [];
        $defaultRetainedReviewers = UpdateService::mergeDefaultReviewersForProjects(
            $projects,
            $defaultRetainedReviewers,
            $this->p4Admin,
            [UpdateService::ALWAYS_ADD_DEFAULT => false]
        );
        if ($method === HttpRequest::METHOD_POST) {
            // merge new participant data with old participant data
            $combinedReviewers = ArrayHelper::merge($this->review->getParticipantsData(), $combinedReviewers);
        } elseif ($method === HttpRequest::METHOD_DELETE) {
            /*Add default retain reviewer to new payload which are not exist in payload and
            removed the existing retained reviewer from the payload. This will help when we ran the reviewers
            validator*/
            $defaultRetainedReviewersKeys = array_keys($defaultRetainedReviewers);
            $combinedReviewersKeys        = array_keys($combinedReviewers);
            sort($defaultRetainedReviewersKeys);
            sort($combinedReviewersKeys);
            if ($defaultRetainedReviewersKeys != $combinedReviewersKeys) {
                foreach ($defaultRetainedReviewers as $key => $value) {
                    if (array_key_exists($key, $combinedReviewers)) {
                        unset($combinedReviewers[$key]);
                    } else {
                        $combinedReviewers[$key] = $value;
                    }
                }
            } else {
                // when all default retain reviewers are added to delete only author need to add
                // This will help when we ran the reviewer validator
                $author = $this->review->isValidAuthor() ? $this->review->getAuthorObject()->getId() : null;
                unset($combinedReviewers);
                $combinedReviewers[$author] = [];
            }
        }
        if ($combinedReviewers !== null) {
            // Before we update participants we need to preserve any fields already set (for example
            // 'vote' and 'notificationsDisabled' etc)
            foreach ($this->review->getParticipantsData() as $participant => $participantData) {
                foreach ($participantData as $participantField => $fieldValue) {
                    // We do not want the old required field as we have worked this out.
                    // We do not want the old minimum required field as we will set this only if retained
                    // participants next
                    if ($method === HttpRequest::METHOD_POST) {
                        if ($participantField !== IParticipants::REQUIRED
                            && $participantField !== IReview::FIELD_MINIMUM_REQUIRED) {
                            $combinedReviewers[$participant][$participantField] = $fieldValue;
                        }
                    }
                    // Preserve FIELD_MINIMUM_REQUIRED from the default retained reviewers if present for all
                    // methods
                    if (isset($defaultRetainedReviewers[$participant]) &&
                        isset($defaultRetainedReviewers[$participant][IReview::FIELD_MINIMUM_REQUIRED])) {
                        $combinedReviewers[$participant][IReview::FIELD_MINIMUM_REQUIRED] =
                            $defaultRetainedReviewers[$participant][IReview::FIELD_MINIMUM_REQUIRED];
                    }
                }
            }
        }
        return $combinedReviewers;
    }

    /**
     * It checks whether reviewers validator contains the retained reviewers
     * errors.
     * @return boolean
     */
    public function hasRetainedReviewersError()
    {
        return $this->reviewersValidator->hasRetainedReviewersError();
    }

    /**
     * It brings retained reviewers ids if filled by retained reviewer error
     * @return array
     */
    public function hasRetainedReviewers()
    {
        return $this->reviewersValidator->hasRetainedReviewers();
    }

    /**
     * Add filter for validating Participants data
     * @throws Exception
     */
    private function addParticipantsFilter()
    {
        $review                   = $this->review;
        $projects                 = $review->getProjects();
        $author                   = $review->isValidAuthor() ? $review->getAuthorObject()->getId() : null;
        $input                    = new Input(self::COMBINED_REVIEWERS);
        $this->reviewersValidator = new ReviewersValidator(
            $this->p4Admin,
            $projects,
            $author,
            true,
            $this->validateIds
        );
        $input->getValidatorChain()->attach($this->reviewersValidator);
        $this->add($input);
    }

    /**
     * Removes excluded reviewers from the $data object.
     * Note it will only do this if the excluded reviewer does not already exist.
     *
     * @param array       $data   values from the request
     * @return array      $data with removing excluded user and group if any
     * @throws ConfigException
     */
    private function removeExcludedReviewers($data)
    {
        $config         = $this->services->get(IConfigDefinition::CONFIG);
        $caseSensitive  = $this->services->get(ConnectionFactory::P4_ADMIN)->isCaseSensitive();
        $groupsExcluded = ConfigManager::getValue($config, IConfigDefinition::MENTIONS_GROUPS_EXCLUDE_LIST, []);
        $usersExcluded  = ConfigManager::getValue($config, IConfigDefinition::MENTIONS_USERS_EXCLUDE_LIST, []);

        if (count($groupsExcluded) + count($usersExcluded) == 0) {
            return $data;
        }

        $old      = $this->review->get();
        $oldNames = $old && isset($old['participantsData']) ? array_keys($old['participantsData']) : [];

        if (isset($data[IReview::REVIEWER])
            && $this->inExcluded(
                $data[IReview::REVIEWER],
                $oldNames,
                $groupsExcluded,
                $usersExcluded,
                $caseSensitive
            )
        ) {
            unset($data[IReview::REVIEWER]);
        }

        if (isset($data[IReview::REVIEWERS])) {
            foreach ($data[IReview::REVIEWERS] as $index => $reviewer) {
                if ($this->inExcluded($reviewer, $oldNames, $groupsExcluded, $usersExcluded, $caseSensitive)) {
                    unset($data[IReview::REVIEWERS][$index]);
                }
            }
            $data[IReview::REVIEWERS] = array_values($data[IReview::REVIEWERS]);
        }

        if (isset($data[IReview::REQUIRED_REVIEWERS])) {
            foreach ($data[IReview::REQUIRED_REVIEWERS] as $index => $reviewer) {
                if ($this->inExcluded($reviewer, $oldNames, $groupsExcluded, $usersExcluded, $caseSensitive)) {
                    unset($data[IReview::REQUIRED_REVIEWERS][$index]);
                }
            }
            $data[IReview::REQUIRED_REVIEWERS] = array_values($data[IReview::REQUIRED_REVIEWERS]);
        }
        return $data;
    }

    /**
     * Only excludes if adding a blacklisted user or group that does not already exist in the reviewers.
     *
     * @param string        $name              the name to check
     * @param array         $oldNames          list of users & groups already in the reviewers list
     * @param array         $groupsExcluded    list of excluded groups
     * @param array         $usersExcluded     list of excluded users
     * @param bool          $caseSensitive     if p4d is case sensitive
     * @return bool
     */
    private function inExcluded($name, $oldNames, $groupsExcluded, $usersExcluded, $caseSensitive)
    {
        if (in_array($name, $oldNames)) {
            return false;
        }

        $groupName     = Group::getGroupName($name);
        $groupExcluded = Group::isGroupName($name)
            && ConfigCheck::isExcluded($groupName, $groupsExcluded, $caseSensitive);

        if ($groupExcluded || ConfigCheck::isExcluded($name, $usersExcluded, $caseSensitive)) {
            return true;
        }

        return false;
    }
}
