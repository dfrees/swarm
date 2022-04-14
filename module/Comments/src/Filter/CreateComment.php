<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Comments\Filter;

use Application\Connection\ConnectionFactory;
use Application\Filter\DefaultValue;
use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Comments\Model\IComment as ModelInterface;
use Comments\Validator\Context;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\Validator\Regex;
use Users\Validator\UserConnection;

class CreateComment extends Comment
{
    // The pattern used to match a topic
    const TOPIC_REGEX = '/(' .
        '(' . ModelInterface::TOPIC_REVIEWS  . '|' . ModelInterface::TOPIC_CHANGES . ')\/(\d+)|' .
        '(' . ModelInterface::TOPIC_JOBS . ')\/(.+)' .
        ')/';
    private $connection;
    private $services;

    /**
     * CreateComment constructor.
     * @param ContainerInterface $services
     * @param array|null $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->translator = $services->get(TranslatorFactory::SERVICE);
        $this->connection = (isset($options['connection']) && $options['connection'])
            ? $options['connection']
            : $services->get(ConnectionFactory::P4);
        $caseSensitive    = $this->connection->isCaseSensitive();
        $this->services   = $services;
        $this->addBodyValidator(true);
        $this->addTopicValidator();
        $this->addUserValidator($caseSensitive);
        $this->addTaskStateValidator();
        $this->addContextValidator();
    }

    /**
     * Add in a topic validator to ensure that we get one of
     * - reviews/nnn
     * - changes/nnn
     * - jobs/xxx
     */
    private function addTopicValidator()
    {
        $input = new DirectInput(ModelInterface::TOPIC);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getValidatorChain()->attach(new Regex(['pattern' => self::TOPIC_REGEX]));
        $this->add($input);
    }

    /**
     * Add in a user validator to ensure that the value provided is that associated with the
     * current connection; by inference the current connection must be a valid user as it will
     * be logged into the server
     */
    private function addUserValidator($caseSensitive)
    {
        $input = new DirectInput(ModelInterface::USER);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new StringTrim());
        $input->getFilterChain()->attach(
            new DefaultValue(
                [DefaultValue::DEFAULT => $this->services->get(ConnectionFactory::P4)->getUser()]
            )
        );
        $input->getValidatorChain()->attach(
            new UserConnection(
                [
                    UserConnection::TOKEN => $this->connection->getUser(),
                    UserConnection::STRICT => false,
                    UserConnection::CASE_SENSITIVE => $caseSensitive
                ]
            )
        );
        $this->add($input);
    }

    /**
     * Add in a custom context validator to ensure that a context value makes sense, the detail
     * of the validation rules is documented in the validator
     */
    private function addContextValidator()
    {
        $input = new DirectInput(ModelInterface::CONTEXT);
        $input->setRequired(false);
        $input->getFilterChain()->attach(new ContextAttributes($this->inputs[ModelInterface::TOPIC]));
        $input->getValidatorChain()
            ->attach(new Context([ModelInterface::TOPIC => $this->inputs[ModelInterface::TOPIC]]));
        $this->add($input);
    }
}
