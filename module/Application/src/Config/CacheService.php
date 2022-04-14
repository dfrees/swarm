<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Config;

use Application\Cache\AbstractCacheService;
use Application\I18n\TranslatorFactory;
use Interop\Container\ContainerInterface;

/**
 * Service to handle deletion of the config cache and module class map files.
 * @package Application\Config
 */
class CacheService extends AbstractCacheService implements ICacheService
{
    private $services;
    const DEFAULT_CACHE_PATH    = DATA_PATH . '/cache';
    const WORKERS_SHUTDOWN_NOTE = 'Workers are shutdown. They will start automatically by recurring task.';

    /**
     * @inheritdoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
    }

    /**
     * This is not ideal. In order for values to be picked up for .po generation strings
     * must be specifically mentioned with a call to a function that returns a string
     * (even though it will actually never be called).
     * We are forced to repeat the values for constants above.
     */
    private static function msgIds()
    {
        CacheService::t('Workers are shutdown. They will start automatically by recurring task.');
    }

    /**
     * Dummy translation.
     * @param $value
     * @return mixed
     */
    private static function t($value)
    {
        return $value;
    }

    /**
     * Deletes the config cache and module cache files if found and restart the workers
     *
     * @param string  $key      ID to remove, this is ignored in this case. File names are derived from
     *                          application configuration
     * @param array   $options  options to consider. Config cache files do not currently use these options.
     * @return array         Message for each file indicating whether if was deleted or not
     */
    public function delete($key, array $options = []) : array
    {
        $configFile   = $this->getConfigPath();
        $classFile    = $this->getClassmapPath();
        $queueManager = $this->services->get('queue');

        $messages          = [];
        $configFileDeleted = @unlink($configFile);
        $classFileDeleted  = @unlink($classFile);

        $status = $queueManager->restartWorkers();

        $messages[] = $this->getMessage($configFile, $configFileDeleted);
        $messages[] = $this->getMessage($classFile, $classFileDeleted);

        if ($status) {
            $translator = $this->services->get(TranslatorFactory::SERVICE);
            array_push($messages, $translator->t(self::WORKERS_SHUTDOWN_NOTE));
        }

        return $messages;
    }

    /**
     * @inheritdoc
     */
    public function getConfigPath() : string
    {
        $appConfig = $this->services->get(self::SERVICE);
        $cacheDir  = $appConfig[self::MODULE_LISTENER_OPTIONS][self::CACHE_DIR] ?? self::DEFAULT_CACHE_PATH;
        return $cacheDir
            . '/' . self::CONFIG_PREFIX
            . ($appConfig[self::CONFIG_CACHE_KEY] ?? '')
            . '.php';
    }

    /**
     * @inheritdoc
     */
    public function getClassmapPath() : string
    {
        $appConfig = $this->services->get(self::SERVICE);
        $cacheDir  = $appConfig[self::MODULE_LISTENER_OPTIONS][self::CACHE_DIR] ?? self::DEFAULT_CACHE_PATH;
        return $cacheDir
            . '/' . self::CLASSMAP_PREFIX
            . ($appConfig[self::MODULE_MAP_CACHE_KEY] ?? '')
            . '.php';
    }

    /**
     * Builds a message based on a file and the result from testing deletion.
     * @param string        $file   the path to the file
     * @param bool          $result the result from the attempt to delete
     * @return string the messages
     */
    private function getMessage(string $file, bool $result) : string
    {
        $translator = $this->services->get(TranslatorFactory::SERVICE);
        return $result
            ? $translator->t("File %s deleted", [$file])
            : $translator->t("File %s was not deleted", [$file]);
    }
}
