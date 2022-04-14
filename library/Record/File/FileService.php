<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Record\File;

use P4\Connection\Exception\CommandException;
use P4\File\File;
use P4\Model\Connected\ConnectedAbstract;
use P4\Exception as P4Exception;
use Closure;
use Exception;

/**
 * Simplified handler for reading and writing files to a special depot storage location.
 */
class FileService extends ConnectedAbstract
{
    protected $config;

    /**
     * Retrieve contents from a file in the depot
     *
     * @param  string   $fileSpec   file location (either absolute depot path or relative to base_path)
     * @return string               the contents of the file
     * @throws P4Exception
     */
    public function read($fileSpec)
    {
        return $this->getFile($fileSpec)->getDepotContents();
    }

    /**
     * Stream the contents to STDOUT
     *
     * @param   string  $fileSpec   file location (either absolute depot path or relative to base_path)
     * @return  File                a file instance
     * @throws P4Exception
     */
    public function stream($fileSpec)
    {
        return $this->getFile($fileSpec)->streamDepotContents();
    }

    /**
     * Manipulate a file in the depot using an anonymous function. This is used for writing from strings and
     * local files, and also for deleting from the depot.
     *
     * Example invocation:
     *
     *      $this->manipulateFile(
     *          $filespec,
     *          function ($file) use ($filespec) {
     *              $file->delete();
     *              return "Deleted: " . $filespec;
     *          }
     *      );
     *
     * The returned string is used for the submit message when the changes are applied.
     *
     * @param string    $fileSpec   full or partial p4 fileSpec (partial fileSpecs will be absolutized)
     * @param Closure   $callback   anonymous function that accepts a $file parameter and performs some action on it.
     *                              must return a string to use as a submit message.
     * @param mixed     $options    options to use. Added to enable extension to provide extra functionality rather than
     *                              just submitting for this legacy code. If options are null, 'action' is unset or
     *                              'action' is set to anything other than 'submit' no action will be carried out and
     *                              the action will be left to the callback; otherwise a submit is carried out
     * @return mixed the file
     * @throws P4Exception
     */
    public function manipulateFile($fileSpec, Closure $callback, $options = [])
    {
        $options += [
            'action' => 'submit'
        ];

        $p4   = $this->getConnection();
        $pool = $p4->getService('clients');
        $pool->grab();
        $file = null;

        try {
            $pool->reset(true);

            $file = new File($p4);
            $file->setFilespec($this->absolutize($fileSpec));

            $message = $callback($file);

            if ($options['action'] === 'submit') {
                $file = $file->submit($message);
            }
        } catch (\Exception $e) {
        }

        try {
            $pool->clearFiles();
        } catch (\Exception $clearFilesException) {
        }

        $pool->release();

        // exceptions in the callback take priority over clearFiles exceptions
        if (isset($e)) {
            throw $e;
        }

        if (isset($clearFilesException)) {
            throw $clearFilesException;
        }
        return $file;
    }

    /**
     * Write raw data to a file in the Depot
     *
     * @param   string  $fileSpec   file location (either absolute depot path or relative to base_path)
     * @param   string  $data       the data to be written
     * @return mixed
     * @throws P4Exception
     */
    public function write($fileSpec, $data)
    {
        return $this->manipulateFile(
            $fileSpec,
            function ($file) use ($data, $fileSpec) {
                $file->setLocalContents($data);
                $file->add();

                return "Added: " . $fileSpec;
            }
        );
    }

    /**
     * Copies the specified file to a local client workspace, then writes it to the depot.
     *
     * @param   string  $fileSpec       file location (either absolute depot path or relative to base_path)
     * @param   string  $location       the location of the file on the local filesystem
     * @param   bool    $move           optional - move the file from $location to the active client (default: false)
     * @return mixed
     * @throws P4Exception
     */
    public function writeFromFile($fileSpec, $location, $move = false)
    {
        return $this->manipulateFile(
            $fileSpec,
            function ($file) use ($fileSpec, $location, $move) {
                $localFilename = $file->getLocalFilename();
                $file->createLocalPath();

                if ($move && !@rename($location, $localFilename)) {
                    throw new \RuntimeException("Unable to move file: " . $location);
                }

                if (!$move && !@copy($location, $localFilename)) {
                    throw new \RuntimeException("Unable to copy file: " . $location);
                }

                // @todo make this work for files that already exist at $filespec
                $file->add();

                return "Added: " . $fileSpec;
            }
        );
    }

    /**
     * Delete a file in the Depot
     *
     * @param   string  $fileSpec   file location (either absolute depot path or relative to base_path)
     * @return mixed
     * @throws P4Exception
     */
    public function delete($fileSpec)
    {
        return $this->manipulateFile(
            $fileSpec,
            function ($file) use ($fileSpec) {
                $file->delete();
                return "Deleted: " . $fileSpec;
            }
        );
    }

    /**
     * Get an instance of File from the Depot
     *
     * @param   string  $fileSpec   file location (either absolute depot path or relative to base_path)
     * @return  File                a file instance
     * @return mixed
     * @throws P4Exception
     */
    public function getFile($fileSpec)
    {
        $path = $this->absolutize($fileSpec);
        return File::fetch($path, $this->getConnection(), true);
    }

    /**
     * Take a filespec and resolve it to a absolute location in the depot.
     * If the filespec is already absolute, it will be returned as-is.
     *
     * @param   string  $fileSpec       file location (either absolute depot path or relative to base_path)
     * @return  string                  the full path to the depot location of the file
     * @return mixed
     * @throws Exception
     */
    public function absolutize($fileSpec)
    {
        if (is_null($fileSpec) || $fileSpec === '' || !strlen(trim($fileSpec, '/'))) {
            throw new \InvalidArgumentException('FileService::absolutize($filespec) requires a non-empty filespec');
        }

        if (substr($fileSpec, 0, 2) == '//') {
            $path = $fileSpec;
        } else {
            $path = $this->getBasePath() . '/' . $fileSpec;
        }

        return $path;
    }

    /**
     * Configure the file service.
     *
     * Expects an array:
     *
     *   array(
     *     'base_path' => '//.swarm'
     *   )
     *
     * @param $config   array containing 'base_path' key for storage location
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @return  array   the file service configuration array
     */
    public function getConfig()
    {
        return (array) $this->config + ['base_path' => null];
    }

    /**
     * Get the base location for writing files to.
     *
     * @return string   the base depot path (defaults to "//.swarm" if not set)
     * @throws Exception
     */
    public function getBasePath()
    {
        if (!isset($this->config['base_path']) || strlen(trim($this->config['base_path'], '/')) == 0) {
            throw new Exception('Administrator must set $config[\'depot_storage\'][\'base_path\']');
        }
        return rtrim($this->config['base_path'], '/');
    }

    /**
     * Check if given $path is writable
     *
     * @param   string  $path       the path to check for writability
     * @return  bool                whether $path is writable or not
     * @throws CommandException
     * @throws P4Exception
     */
    public function isWritable($path)
    {
        try {
            $result = $this->getConnection()->run("protects", ["-m", $this->absolutize($path)]);
        } catch (CommandException $e) {
            if (strpos($e->getMessage(), 'must refer to client')) {
                return false;
            }
            if (strpos($e->getMessage(), 'Protections table is empty')) {
                return true;
            }
            throw $e;
        }
        return in_array($result->getData(0, "permMax"), ['write', 'super', 'admin']);
    }
}
