<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Files\Model;

use P4\File\File;

/**
 * Class FileUpdateResult. To encapsulate the result of updating a file
 * @package Files\Model
 */
class FileUpdateResult
{
    private $file;
    private $change;

    /**
     * FileUpdateResult constructor.
     * @param File      $file       file for the result
     * @param mixed     $change     change for the result (optional)
     */
    public function __construct(File $file, $change = null)
    {
        $this->file   = $file;
        $this->change = $change;
    }

    /**
     * Get the file for the update result
     * @return File
     */
    public function getFile() : File
    {
        return $this->file;
    }

    /**
     * Get the change for the update result
     * @return mixed
     */
    public function getChange()
    {
        return $this->change;
    }
}
