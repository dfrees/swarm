<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Filter;

use Api\IRequest;
use Application\Connection\ConnectionFactory;
use Application\Filter\FormBoolean;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Application\Validator\GreaterThanInt;
use Application\Validator\IsBool;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\Validator\StringLength;
use Record\Key\AbstractKey;
use Reviews\ITransition;
use Reviews\Model\IReview;
use Reviews\Model\Review;
use Reviews\Validator\States;
use Application\Validator\ArrayValuesValidator;
use Users\Validator\Users as UserValidator;
use Application\Filter\KeywordsFields;

/**
 * Defines filters to run for getting reviews.
 * @package Reviews\Filter
 */
class GetReviews extends InputFilter implements IGetReviews
{
    private $translator;
    private $connectionOption;
    const INVALID_HAS_VOTED       = 'invalidHasVoted';
    const INVALID_TEST_STATUS     = 'invalidTestStatus';
    const INVALID_MY_COMMENTS     = 'invalidMyComments';
    const INVALID_RESULT_ORDER    = 'invalidResultOrder';
    const INVALID_KEYWORDS_FIELDS = 'invalidKeywordsFields';

    /**
     * Get reviews filter constructor.
     *
     * @param mixed $services services to get connection etc.
     * @param array $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator       = $services->get(TranslatorFactory::SERVICE);
        $this->connectionOption = ['connection' => $services->get(ConnectionFactory::P4_ADMIN)];
        $this->addStateFilter();
        $this->addMetadataFilter();
        $this->addProjectFilter();
        $this->addMaxFilter();
        $this->addHasVotedFilter();
        $this->addTestStatusValidator();
        $this->addHasCommentedFilter();
        $this->addAfterFilter();
        $this->addHasReviewerFilter();
        foreach ([
                IReview::FETCH_BY_AUTHOR,
                IReview::FETCH_BY_AUTHOR_PARTICIPANTS,
                IReview::FETCH_BY_PARTICIPANTS,
                IReview::FETCH_BY_DIRECT_PARTICIPANTS
            ] as $field) {
            $this->addRoleFilter($field);
        }
        $this->addKeywordsFilter();
        $this->addKeywordsFieldsFilter();
        $this->addResultOrderFilter();
        $this->addAfterUpdatedFilter();
        $this->addAfterSortedFilter();
    }

    /**
     * Adds a filter for afterSorted This is an optional field but if provided should be an integer greater than 0
     */
    private function addAfterSortedFilter()
    {
        $input = new DirectInput(Review::FETCH_AFTER_SORTED);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => 0]));
        $this->add($input);
    }

    /**
     * Adds a filter for result order. This is an optional field but if provided should either be 'created' or 'updated'
     */
    private function addResultOrderFilter()
    {
        $input = new DirectInput(IRequest::RESULT_ORDER);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(
            new ArrayValuesValidator(
                $this->translator,
                [IRequest::RESULT_ORDER_CREATED, IRequest::RESULT_ORDER_UPDATED],
                self::INVALID_RESULT_ORDER,
                IRequest::RESULT_ORDER
            )
        );
        $this->add($input);
    }

    /**
     * Adds a filter for afterUpdated. This is an optional field but if provided should be an integer greater than 0
     */
    private function addAfterUpdatedFilter()
    {
        $input = new DirectInput(Review::FETCH_AFTER_UPDATED);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => 0]));
        $this->add($input);
    }

    /**
     * Adds a filter for keywords. This is an optional field but if provided should be a string with minimum length 1.
     */
    private function addKeywordsFilter()
    {
        $input = new DirectInput(Review::FETCH_BY_KEYWORDS);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new StringLength(['min' => 1]));
        $this->add($input);
    }

    /**
     * Adds a filter for keywordsFields. This determines what fields 'keywords' filter searches.
     */
    private function addKeywordsFieldsFilter()
    {
        $input = new DirectInput(Review::FETCH_KEYWORDS_FIELDS);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(
            new ArrayValuesValidator(
                $this->translator,
                KeywordsFields::getKeywordsFields(new Review()),
                self::INVALID_KEYWORDS_FIELDS,
                AbstractKey::FETCH_KEYWORDS_FIELDS,
                [ArrayValuesValidator::SUPPORT_ARRAYS => true]
            )
        );
        $this->add($input);
    }

    /**
     * Adds a filter for metadata to ensure if present the value is boolean or can be converted to boolean.
     */
    private function addMetadataFilter()
    {
        $input = new DirectInput(IRequest::METADATA);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new FormBoolean([FormBoolean::NULL_AS_FALSE => false]));
        $input->getValidatorChain()->attach(new IsBool());
        $this->add($input);
    }

    /**
     * Add the state filter to validate the state being used are valid.
     */
    private function addStateFilter()
    {
        $input = new DirectInput(IReview::FETCH_BY_STATE);
        $input->setRequired(false);
        $input->getValidatorChain()
            ->attach(
                new States(
                    $this->translator,
                    [
                        States::VALID_STATES => array_merge(
                            ITransition::ALL_VALID_TRANSITIONS,
                            ITransition::SPECIAL_TRANSITIONS
                        )
                    ]
                )
            );
        $this->add($input);
    }

    /**
     * Add a project filter. Can be a single string or an array so here we just accept a value in the
     * filter so it is returned by getValues
     */
    private function addProjectFilter()
    {
        $input = new DirectInput(IReview::FETCH_BY_PROJECT);
        $input->setRequired(false);
        $this->add($input);
    }

    /**
     * Add a max filter to validate the max values being used are valid.
     */
    private function addMaxFilter()
    {
        $input = new DirectInput(IReview::FETCH_MAX);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => 0]));
        $this->add($input);
    }

    /**
     * Add a vote filter to validate the has voted values.
     */
    private function addHasVotedFilter()
    {
        $input = new DirectInput(IReview::FETCH_BY_HAS_VOTED);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(
            new VoteValidator(
                $this->translator,
                VoteValidator::VALID_FILTERS,
                self::INVALID_HAS_VOTED,
                IReview::FETCH_BY_HAS_VOTED
            )
        );
        $this->add($input);
    }

    /**
     * Add the test status validator to valid fetching by testStatus. Valid values are 'pass' and 'fail'
     */
    private function addTestStatusValidator()
    {
        $input = new DirectInput(IReview::FETCH_BY_TEST_STATUS);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(
            new ArrayValuesValidator(
                $this->translator,
                [IReview::TEST_STATUS_PASS, IReview::TEST_STATUS_FAIL],
                self::INVALID_TEST_STATUS,
                IReview::FETCH_BY_TEST_STATUS
            )
        );
        $this->add($input);
    }
    /*
     * Add a vote filter to validate the has voted values.
     */
    private function addHasCommentedFilter()
    {
        $input = new DirectInput(IReview::FETCH_BY_MY_COMMENTS);
        $input->setRequired(false);
        $input->getFilterChain()->attach(
            new FormBoolean(
                [
                    FormBoolean::NULL_AS_FALSE => false,
                    FormBoolean::FALSE_VALUE => 'false',
                    FormBoolean::TRUE_VALUE => 'true'
                ]
            )
        );
        $input->getValidatorChain()->attach(
            new ArrayValuesValidator(
                $this->translator,
                ['true','false'],
                self::INVALID_MY_COMMENTS,
                IReview::FETCH_BY_MY_COMMENTS
            )
        );
        $this->add($input);
    }

    /**
     * Add an after filter to validate the after values being used are valid.
     */
    private function addAfterFilter()
    {
        $input = new DirectInput(AbstractKey::FETCH_AFTER);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new GreaterThanInt(['min' => 0]));
        $this->add($input);
    }

    /**
     * Adds a filter for hasReviewer to ensure if present the value is boolean or can be converted to boolean.
     */
    private function addHasReviewerFilter()
    {
        $input = new DirectInput(IReview::FETCH_BY_HAS_REVIEWER);
        $input->setRequired(false);
        $input->getFilterChain()->attach(
            new FormBoolean(
                [FormBoolean::NULL_AS_FALSE => false, FormBoolean::FALSE_VALUE => '0', FormBoolean::TRUE_VALUE => '1']
            )
        );
        $input->getValidatorChain()->attach(
            new ArrayValuesValidator(
                $this->translator,
                ['0','1'],
                IReview::FETCH_BY_HAS_REVIEWER,
                IReview::FETCH_BY_HAS_REVIEWER
            )
        );
        $this->add($input);
    }

    /**
     * Add a role filter to validate the roles being passed is valid.
     * @param string $field role field containing author, participants or authorparticipants
     */
    private function addRoleFilter(string $field)
    {
        $input = new DirectInput($field);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new UserValidator($this->connectionOption));
        $this->add($input);
    }
}
