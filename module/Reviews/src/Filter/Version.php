<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Reviews\Filter;

use Application\I18n\TranslatorFactory;
use Application\InputFilter\DirectInput;
use Application\InputFilter\InputFilter;
use Interop\Container\ContainerInterface;
use Reviews\Validator\Version as VersionValidator;
use Laminas\Validator\LessThan;

/**
 * Class Version, filter for validating version values
 * @package Reviews\Filter
 */
class Version extends InputFilter implements IVersion
{
    private $maxVersion;
    private $maxFromVersion;
    private $translator;

    /**
     * Version constructor.
     * @param ContainerInterface $services
     * @param array|null $options specify self::MAX_VERSION and self::MAX_FROM
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->maxVersion     = $options[self::MAX_VERSION] ?? 0;
        $this->maxFromVersion = $options[self::MAX_FROM] ?? 0;
        $this->translator     = $services->get(TranslatorFactory::SERVICE);
        $this->addVersionFilter([self::FROM => -1, self::TO => 1]);
        $this->addFromFilter();
    }

    /**
     * Add a version filter for each field
     * @param array $fields fields
     */
    private function addVersionFilter(array $fields)
    {
        foreach ($fields as $field => $min) {
            $input = new DirectInput($field);
            $input->setRequired(false);
            $input->getValidatorChain()->attach(
                new VersionValidator(
                    $this->translator, ['min' => $min, 'max' => $this->maxVersion]
                )
            );
            $this->add($input);
        }
    }

    /**
     * Specific filter to validate from being less than or equal to 'to'
     */
    private function addFromFilter()
    {
        $input = new DirectInput(self::FROM);
        $input->setRequired(false);
        $input->getValidatorChain()->attach(new LessThan(['max' => $this->maxFromVersion, 'inclusive' => true]));
        $this->add($input);
    }
}
