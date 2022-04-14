<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\Validator;

use Application\Connection\ConnectionFactory;
use Application\I18n\TranslatorFactory;
use Laminas\Validator\AbstractValidator;
use Record\Key\AbstractKey;
use Interop\Container\ContainerInterface;

/**
 * Class UniqueForField. A validator that can test a field on a model against a value to assert uniqueness.
 * @package Application\Validator
 */
class UniqueForField extends AbstractValidator
{
    private $services;
    private $existingId;
    private $fieldName;
    private $dao;
    const FRIENDLY_FIELD_NAME   = 'friendlyFieldName';
    const INVALID               = 'invalid';
    protected $messageTemplates = [self::INVALID => ''];

    /**
     * UniqueForField constructor.
     * @param ContainerInterface        $services       application services
     * @param string                    $modelName      name that will appear in any message that is raised, this does
     *                                                  not have to be a model class name
     * @param string                    $daoName        dao service name to use to fetch any existing records
     * @param string                    $fieldName      the name of the field on the model managed by the DAO to test
     *                                                  against, for example 'name'.
     * @param array|null                $options        Supports an options of:
     *                                                      id => <existing id> (for an updated record)
     *                                                      friendlyFieldName => <string> (a field name to appear in
     *                                                                           any messages for the case where we do
     *                                                                           not want the actual field name)
     */
    public function __construct(
        ContainerInterface $services,
        $modelName,
        $daoName,
        $fieldName,
        array $options = null
    ) {
        $this->services   = $services;
        $this->fieldName  = $fieldName;
        $this->dao        = $this->services->get($daoName);
        $this->existingId = isset($options['id']) ? (int)$options['id'] : null;
        $translator       = $services->get(TranslatorFactory::SERVICE);
        $messageFieldName = isset($options[self::FRIENDLY_FIELD_NAME])
            ? $options[self::FRIENDLY_FIELD_NAME]
            : $fieldName;

        $this->messageTemplates[self::INVALID] =
            $translator->t("A %s with this %s exists already.", [$modelName, $messageFieldName]);
        parent::__construct($options);
    }

    public function isValid($value)
    {
        $matching = $this->dao->fetchAll(
            [
                AbstractKey::FETCH_BY_KEYWORDS     => $value,
                AbstractKey::SPLIT_KEYWORDS        => false,
                AbstractKey::FETCH_KEYWORDS_FIELDS => [$this->fieldName]
            ],
            $this->services->get(ConnectionFactory::P4_ADMIN)
        );
        $count    = $matching ? $matching->count() : 0;
        // If there is no model found with the same value for fieldName this is valid
        // If there is 1 model with the same value for fieldName and we find a record
        // the ids must match for it to be valid (it is an update)
        $valid = $count === 0 || ($count === 1 && (int)$matching->first()->getId() === $this->existingId);
        if (!$valid) {
            $this->error(self::INVALID);
        }
        return $valid;
    }
}
