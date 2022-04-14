<?php
/**
 * Manager to help with getting values from a configuration array.
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Config;

use Laminas\ModuleManager\Listener\ConfigMergerInterface;
use Laminas\ModuleManager\ModuleEvent;
use P4\Log\Logger;
use ReflectionClass;
use ReflectionException;
use Exception;
use InvalidArgumentException;

class ConfigManager implements IConfigDefinition
{
    use ConfigMetadata;
    const OLD_NEW_PATH_MAPPING = [
        ConfigManager::MENTIONS_USERS_EXCLUDE_LIST  => ConfigManager::MENTIONS_USERS_BLACKLIST,
        ConfigManager::MENTIONS_GROUPS_EXCLUDE_LIST => ConfigManager::MENTIONS_GROUPS_BLACKLIST,
        ConfigManager::P4_SSO => ConfigManager::P4_SSO_ENABLED,
    ];
    /**
     * Gets a value from the config and checks if it is valid. Simple case at the moment - we may
     * want to enhance to add ranges etc.
     * @param $config array config data
     * @param $path string array path (for nested values use dot notation, for example 'reviews.expand_all_file_limit'.
     * @param null $default default value that will be returned without validation if any problems occur
     * @param null $metaData metadata override
     * @return array|bool|int|mixed|null|string
     * @throws ConfigException
     * @throws Exception
     */
    public static function getValue($config, $path, $default = null, $metaData = null)
    {
        // This will throw an exception if the value is not set
        $configValue     = null;
        $metaDataElement = null;
        $path            = self::getPath($config, $path);
        try {
            $configValue = self::getValueFromConfig($config, $path);
            try {
                $metaDataElement = self::getValueFromConfig($metaData ? $metaData : self::$configMetaData, $path);
            } catch (ConfigException $e) {
                // Something is wrong with our metadata, perhaps a path is asked for that we haven't yet catered for.
                // Don't fail because of this.
                return $configValue;
            }
            // This will throw an exception if validation fails
            $configValue = self::validate($configValue, $metaDataElement, $path);
        } catch (ConfigException $ce) {
            if ($default === null || $metaDataElement === null) {
                throw $ce;
            } else {
                $configValue = self::validateDefault($default, $metaDataElement);
            }
        }
        return $configValue;
    }

    /**
     * Check and return the correct path if the provided path fall under OLD_NEW_PATH_MAPPING
     * array otherwise return the path as is.
     * @param $config array config data
     * @param $path string array path (for nested values use dot notation, for example 'reviews.expand_all_file_limit'.
     * @return array|mixed
     */
    private static function getPath($config, $path)
    {
        if (array_key_exists($path, self::OLD_NEW_PATH_MAPPING)) {
            $oldPath = self::OLD_NEW_PATH_MAPPING[$path];
            // check if old path exists, if not then return new path
            try {
                $oldValue = self::getValueFromConfig($config, $oldPath);
                // check if new path exists along with old path, if not then return old path
                try {
                    $newValue = self::getValueFromConfig($config, $path);
                    // fall back to old path if it is set and new path is not set
                    $path = $newValue ? $path : ($oldValue ? $oldPath  : $path);
                } catch (ConfigException $ce) {
                    $path = $oldPath;
                }
            } catch (ConfigException $ce) {
               // assumed new path is defined since old path does not exist
            }
        } elseif (in_array($path, array_values(self::OLD_NEW_PATH_MAPPING))) {
            Logger::log(
                Logger::WARN,
                "Old path is still in use. Old path =  ". $path ." and New path = ".
                array_search($path, self::OLD_NEW_PATH_MAPPING)
            );
        }
        return $path;
    }

    /**
     * Make sure that if a default is provided it fits the metadata definition.
     * @param $value
     * @param $metaDataValue
     * @return null
     * @throws ConfigException
     */
    private static function validateDefault($value, $metaDataValue)
    {
        $type    = $metaDataValue[self::TYPE];
        $default = null;
        switch ($type) {
            case self::STRING:
                if (isset($metaDataValue[self::VALID_VALUES]) &&
                    !array_uintersect([$value], $metaDataValue[self::VALID_VALUES], 'strcasecmp')) {
                    throw new ConfigException(
                        "Value '" . $value . "' provided as a default must be one of '"
                        . implode(', ', $metaDataValue[self::VALID_VALUES]) . '\''
                    );
                }
                $default = isset($metaDataValue[self::CASE_SENSITIVITY]) ? $value : strtolower($value);
                break;
            default:
                $default = $value;
                break;
        }
        return $default;
    }

    /**
     * Validates the value.
     * @param mixed     $value          value to validate
     * @param array     $metaDataValue  string meta data
     * @param string    $path           the path
     * @return array|bool|int|null|string
     * @throws ConfigException
     */
    private static function validate($value, array $metaDataValue, $path)
    {
        // Validate all parameters here - currently just type is specified
        $type           = isset($metaDataValue[self::TYPE]) ? $metaDataValue[self::TYPE] : null;
        $convertedValue = null;
        $nullPermitted  =
            $value === null && isset($metaDataValue[self::ALLOW_NULL]) && $metaDataValue[self::ALLOW_NULL];
        $errorMessage   = null;
        switch ($type) {
            case self::INT_OR_HOURS_MINUTES_24:
                if (!is_array($value)) {
                    if (ctype_digit(strval($value))) {
                        $convertedValue = intval($value);
                    } else {
                        // Pick up any negative values before trying as a time
                        if (intval($value) < 0) {
                            $errorMessage = "Value must be >= 0";
                        } else {
                            try {
                                self::validate24HourTime($value);
                                $convertedValue = $value;
                            } catch (InvalidArgumentException $e) {
                                // Leave convertedValue as null
                                $errorMessage = $e->getMessage();
                            }
                        }
                    }
                }
                break;
            case self::INT:
                if (ctype_digit(strval($value))) {
                    $convertedValue = intval($value);
                }
                break;
            case self::BOOLEAN:
                if ($value === true || strcasecmp($value, 'true') == 0) {
                    $convertedValue = true;
                } elseif ($value === false || strcasecmp($value, 'false') == 0) {
                    $convertedValue = false;
                }
                break;
            case self::STRING:
                if (is_string($value)) {
                    if (isset($metaDataValue[self::CASE_SENSITIVITY])
                        && $metaDataValue[self::CASE_SENSITIVITY] === self::CASE_SENSITIVE) {
                        $convertedValue = $value;
                    } else {
                        $convertedValue = strtolower($value);
                    }
                    if (isset($metaDataValue[self::VALID_VALUES]) &&
                        !array_uintersect([$value], $metaDataValue[self::VALID_VALUES], 'strcasecmp')) {
                        // valid values are specified and the value does not match them
                        $convertedValue = null;
                    }
                }
                break;
            case self::ARRAY_OF_STRINGS:
                // We support setting a single string value that we will convert to an array
                $value = is_string($value) ? (array) $value : $value;
                if (is_array($value)) {
                    $convertedValue = [];
                    foreach ($value as $arrayValue) {
                        if (isset($metaDataValue[self::FORCE_STRING])) {
                            $arrayValue = (string)$arrayValue;
                        }
                        if (is_string($arrayValue)) {
                            // Check if the case sensitivity enabled.
                            if (isset($metaDataValue[self::CASE_SENSITIVITY])
                                && $metaDataValue[self::CASE_SENSITIVITY] === self::CASE_SENSITIVE) {
                                $convertedValue[] = $arrayValue;
                                // Now also compare the array in case sensitive mode.
                                if (isset($metaDataValue[self::VALID_VALUES]) &&
                                    !array_intersect([$arrayValue], $metaDataValue[self::VALID_VALUES])) {
                                    // valid values are specified and the value does not match them
                                    $convertedValue = null;
                                    break;
                                }
                            } else {
                                $convertedValue[] = strtolower($arrayValue);
                                if (isset($metaDataValue[self::VALID_VALUES]) &&
                                    !array_uintersect(
                                        [$arrayValue],
                                        $metaDataValue[self::VALID_VALUES],
                                        'strcasecmp'
                                    )
                                ) {
                                    // valid values are specified and the value does not match them
                                    $convertedValue = null;
                                    break;
                                }
                            }
                        } else {
                            $convertedValue = null;
                            break;
                        }
                    }
                }
                break;
            case self::HTTP_STRING:
                if (is_string($value)) {
                    $convertedValue = rtrim($value, '/');
                    if ($convertedValue && strpos(strtolower($convertedValue), 'http') !== 0) {
                        $convertedValue = 'http://' . $convertedValue;
                    }
                }
                break;
            case self::SWARM_SETTING:
                if (array_uintersect([$value], self::$swarmSettings, 'strcasecmp')) {
                    // SWARM_SETTING has its own set of permitted values in configuration
                    // that are case sensitive
                    $convertedValue = $value;
                } else {
                    $convertedValue = null;
                }
                break;
            case self::ARRAY:
                if (is_array($value)) {
                    $convertedValue = $value;
                }
                break;

            case null:
                $convertedValue = $value;
                break;
        }
        if ($convertedValue === null && !$nullPermitted) {
            throw new ConfigException(
                "Value '" . (is_array($value) ? var_export($value, true) : $value) .
                "' at path '" . $path . "' is invalid" . ($errorMessage ? ". $errorMessage" : '')
            );
        }
        return $convertedValue;
    }

    /**
     * Iterates through the configuration to find a value.
     * @param $config array config
     * @param $path string array path (for nested values use dot notation, for example 'reviews.expand_all_file_limit'.
     * @return mixed
     * @throws ConfigException if the path does not exist or the configuration being searched is not an array.
     */
    private static function getValueFromConfig($config, $path)
    {
        $ref  = &$config;
        $keys = explode('.', $path);
        foreach ($keys as $idx => $key) {
            if (!is_array($ref)) {
                throw new ConfigException('Configuration is not an array');
            }
            if (!array_key_exists($key, $ref)) {
                throw new ConfigException("Path '" . $path . "' does not exist", ConfigException::PATH_DOES_NOT_EXIST);
            }
            $ref = &$ref[$key];
        }
        return $ref;
    }

    /**
     * Updates the merged config by overriding it with a value from the config.php file if it was set.
     * This gives the ability to replace values in merged config rather than merging them in. For example
     * Zend merges arrays so default settings of [1, 2, 3] in a module.config.php and [4] in the glob file
     * result in a merged value of [1, 2, 3, 4] when in some cases we just want [4]. Any paths that are present
     * in self::ARRAY_REPLACE_PATHS will be treated as replacements rather than merges.
     * @param ConfigMergerInterface $configListener the config listener
     * @param string                $globConfigPath config glob path
     * @param array|null            $paths          optional paths to use in place of self::ARRAY_REPLACE_PATHS
     * @throws ReflectionException
     * @throws Exception if the path does not exist or the configuration being searched is not an array.
     */
    public static function updateMerged(ConfigMergerInterface $configListener, $globConfigPath, $paths = null)
    {
        // Using reflection is not ideal but overriding the ConfigListener to access values
        // has not proved possible with the current version of Zend
        $reflection = new ReflectionClass('\Laminas\ModuleManager\Listener\ConfigListener');
        $var        = $reflection->getProperty('configs');
        $var->setAccessible(true);
        $configs = $var->getValue($configListener);
        $paths   = $paths ? $paths : self::ARRAY_REPLACE_PATHS;
        if (isset($configs[$globConfigPath])) {
            $mergedConfig = $configListener->getMergedConfig(false);
            // If path value is set in the config.php file then use that rather than the value
            // created from merging Module/module.config.php with the glob file
            $globConfig = $configs[$globConfigPath];
            foreach ($paths as $path) {
                try {
                    $value = self::getValueFromConfig($globConfig, $path);
                    if ($value && !empty($value)) {
                        $keys = explode('.', $path);
                        $temp = &$mergedConfig;
                        foreach ($keys as $key) {
                            $temp = &$temp[$key];
                        }
                        $temp = $value;
                    }
                } catch (ConfigException $e) {
                    // Path was not set in the glob file, will fall back to defaults
                }
            }
            $configListener->setMergedConfig($mergedConfig);
        }
    }

    /**
     * Validates that value is in 24 hour time format
     * @param string|null $value the value
     * @return string the value
     * @throws InvalidArgumentException if the value is not in the correct format
     */
    public static function validate24HourTime($value)
    {
        $matches = [];
        preg_match(self::HOURS_MINUTES_24_PATTERN, $value, $matches);
        if ($matches) {
            return $value;
        }
        throw new InvalidArgumentException("Value does not match 24 hour pattern HH:ii");
    }

    /**
     * Here we want to change the default behaviour whereby Zend merges arrays together rather than replaces when
     * values are provided in defaults. This function is attached as a listener in the Application Module to
     * be triggered on the ModuleEvent::EVENT_MERGE_CONFIG event
     * @param ModuleEvent $e
     * @throws ReflectionException
     */
    public function mergeConfig(ModuleEvent $e)
    {
        if (defined('BASE_DATA_PATH')) {
            self::updateMerged(
                $e->getConfigListener(),
                realpath(BASE_DATA_PATH . '/config.php')
            );
        }
    }
}
