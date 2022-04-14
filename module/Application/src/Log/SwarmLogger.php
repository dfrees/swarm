<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\Log;

use Application\Config\ConfigManager;
use Application\Factory\InvokableService;
use Interop\Container\ContainerInterface;
use Application\Log\Writer\NullWriter;
use Laminas\Log\Writer\Stream;
use P4\Log\DynamicLogger;
use Laminas\Log\Processor\ReferenceId;
use Laminas\Log\Logger as LaminasLogger;

/**
 * Implementation of a logger for Swarm
 * @package Application\Log
 */
class SwarmLogger extends DynamicLogger implements InvokableService
{
    const SERVICE = 'logger';

    /**
     * @inheritDoc
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        parent::__construct(
            [
                'priorities' => [
                    [
                        'level' => 8,
                        'name' => 'TRACE'
                    ]
                ]
            ]
        );
        $config   = $services->get(ConfigManager::CONFIG);
        $file     = ConfigManager::getValue($config, ConfigManager::LOG_FILE);
        $priority = ConfigManager::getValue($config, ConfigManager::LOG_PRIORITY, null);
        $refId    = ConfigManager::getValue($config, ConfigManager::LOG_REFERENCE_ID, false);

        // if a file was specified but doesn't exist attempt to create
        // it (unless we are running on the command line).
        // for cli usage we don't want to risk the log being owned by
        // a user other than the web-server so we won't touch it here.
        if ($file && !file_exists($file) && is_writeable(dirname($file)) && php_sapi_name() !== 'cli') {
            touch($file);
        }

        // if a writable file was specified use it, otherwise just use null
        if ($file && is_writable($file)) {
            $writer = new Stream($file);
            if ($priority) {
                $writer->addFilter((int) $priority);
            }
            $this->addWriter($writer);
        } else {
            $this->addWriter(new NullWriter);
        }
        // If we want to trace the apache process we can enable this to help track which
        // worker is doing the work.
        if ($refId) {
            $processor = new ReferenceId();
            $this->addProcessor($processor);
        }
        // register a custom error handler; we can not use the logger's as
        // it would log 'context' which gets vastly too noisy
        set_error_handler(
            function ($level, $message, $file, $line) {
                if (error_reporting() & $level) {
                    $map = LaminasLogger::$errorPriorityMap;
                    $this->log(
                        isset($map[$level]) ? $map[$level] : $this::INFO,
                        $message,
                        [
                            'errno'   => $level,
                            'file'    => $file,
                            'line'    => $line
                        ]
                    );
                }
                return false;
            }
        );
    }

    /**
     * Builds a logger that does writes to a NullWriter. Useful for dispatch tests
     * that get the contents of stdout and do not want log messages.
     * @return DynamicLogger
     */
    public static function buildNullWriter()
    {
        $logger = self::buildDynamicLogger();
        $logger->addWriter(new NullWriter);
        return $logger;
    }

    /**
     * Builds a dynamic logger with default values.
     * @return DynamicLogger
     */
    private static function buildDynamicLogger()
    {
        return new DynamicLogger(
            [
                'priorities' => [
                    [
                        'level' => 8,
                        'name' => 'TRACE'
                    ]
                ]
            ]
        );
    }
}
