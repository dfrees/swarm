<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\Helper;

use Application\Config\ConfigException;
use Application\Config\ConfigManager;
use Application\Factory\InvokableService;
use Application\Helper\ArrayHelper;
use Application\Model\IModelDAO;
use Application\View\Helper\ViewHelperFactory;
use Interop\Container\ContainerInterface;
use Markdown\Settings as MarkdownSettings;
use P4\Connection\ConnectionInterface;
use P4\Exception;
use P4\File\Exception\NotFoundException;
use P4\File\File;
use P4\File\Filter as FileFilter;
use P4\File\Query as FileQuery;
use P4\Model\Fielded\Iterator;
use Projects\Model\Project as ProjectModel;

/**
 * Service to find readme's for a given project based on mainline search.
 *
 * @package Projects\Helper
 */
class Readme implements InvokableService
{
    private $services;
    private $projectDao;
    private $mainlines;
    private $maxSize;
    private $extensions;
    private $markdownHelper;
    private $markdownEnabled;
    private $readme = false;

    private $defaultMarkDownExtensions = ['md'];

    /**
     * Constructor for the service.
     * Pre setup all the variable so they are ready when we want call this helper.
     *
     * @param ContainerInterface $services application services
     * @param array              $options  options
     * @throws ConfigException
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services        = $services;
        $this->projectDao      = $services->get(IModelDAO::PROJECT_DAO);
        $config                = $services->get(ConfigManager::CONFIG);
        $readmeMode            = ConfigManager::getValue(
            $config, ConfigManager::PROJECTS_README_MODE, MarkdownSettings::ENABLED
        );
        $this->markdownEnabled = $this->isMarkdownEnabled($readmeMode);
        $this->maxSize         = ConfigManager::getValue($config, ConfigManager::PROJECTS_MAX_README_SIZE, null);
        $this->extensions      = ConfigManager::getValue(
            $config, ConfigManager::MARKDOWN_FILE_EXTENSIONS, $this->defaultMarkDownExtensions
        );
        $this->mainlines       = ConfigManager::getValue($config, ConfigManager::PROJECTS_MAINLINES, []);
        $this->markdownHelper  = $this->services->get('ViewHelperManager');
    }

    /**
     * Check if workflow is enabled or not.
     *
     * @param string $mode The mode that we current have setup.
     * @return bool
     */
    public function isMarkdownEnabled($mode)
    {
        return $mode != MarkdownSettings::DISABLED ? true : false;
    }

    /**
     * Check if a readme file exists and process the file to be displayed if found.
     *
     * @param ProjectModel $project The project we want to get readme from.
     * @return string    readme              The data needed to render the readme
     * @throws NotFoundException
     * @throws Exception
     */
    public function getReadme(ProjectModel $project)
    {
        // If markdown is disabled then return early.
        if (!$this->markdownEnabled) {
            return $this->readme;
        }
        $p4Admin        = $project->getConnection();
        $branches       = $project->getBranches(ProjectModel::FIELD_NAME);
        $branchNameOnly = array_column($branches, ProjectModel::FIELD_NAME);
        $locations      = ArrayHelper::findMatchingStrings(
            $this->mainlines, $branchNameOnly
        );

        $branchNames = array_flip($branchNameOnly);
        $this->findReadmeWithinLocation($locations, $branchNames, $branches, $p4Admin);
        return $this->purifyReadme();
    }

    /**
     * Find the readme file within a give list of locations.
     *
     * @param array               $locations   The locations we need to search
     * @param array|null          $branchNames The name of each of the branches
     * @param array               $branches    The full branch data.
     * @param ConnectionInterface $p4Admin     The connection on the project.
     * @throws NotFoundException
     */
    protected function findReadmeWithinLocation(
        array $locations,
        array $branchNames,
        array $branches,
        ConnectionInterface $p4Admin
    ) {
        $readmeExtensions = empty($this->extensions) ? $this->defaultMarkDownExtensions : $this->extensions;
        // Check each mainline branch from the config to get a readme file
        // if multiple matching branches - get the file from the first one created
        foreach ($locations as $line) {
            if (isset($branchNames[$line])) {
                $branch = array_filter(
                    $branches,
                    function ($elem) use ($line) {
                        return $elem[ProjectModel::FIELD_NAME] === $line;
                    }
                );
                $branch = reset($branch);
                foreach ($branch[ProjectModel::FIELD_BRANCH_PATHS] as $depotPath) {
                    // Break early if we have exclusion mappings
                    if (substr($depotPath, 0, 1) === '-') {
                        break;
                    }
                    $fileList = $this->getFileList($depotPath, $readmeExtensions);
                    // There may be multiple files present, use the order of the extensions to pick
                    // the preferred file
                    foreach ($readmeExtensions as $extension) {
                        foreach ($fileList as $file) {
                            $ext = pathinfo($file->getFileSpec(), PATHINFO_EXTENSION);
                            if ($ext === $extension) {
                                $this->readme = File::fetch($file->getFileSpec(), $p4Admin, true);
                                return;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Purify the readme
     * @return string Return the readme.
     */
    protected function purifyReadme()
    {
        if ($this->readme) {
            $purifiedMarkdown = $this->markdownHelper->get(ViewHelperFactory::MARKUP);
            // If file is over $this->maxSize it will display upto that size and cut any ending.
            $contents = $this->readme->getDepotContents(
                [
                    File::UTF8_CONVERT => true, File::UTF8_SANITIZE => true, File::MAX_SIZE => $this->maxSize,
                ]
            );
            return $purifiedMarkdown($contents);
        }
        return '';
    }

    /**
     * Get the List of files we want to fetch.
     *
     * @param string $depotPath        The depot path we want to search.
     * @param array  $readmeExtensions The file extensions we want to look for.
     * @return Iterator
     */
    protected function getFileList($depotPath, $readmeExtensions): Iterator
    {
        // Remove ... from the end of a depot path
        $filePath       = substr($depotPath, -3) === '...' ? substr($depotPath, 0, -3) : null;
        $extensionRegex = implode('|', $readmeExtensions);
        // filter is case insensitive and if file is not deleted/renamed.
        $query = $this->prepareFileQuery($extensionRegex);
        $query->setFilespecs($filePath ? $filePath.'*' : $depotPath);
        $fileList = File::fetchAll($query);
        return $fileList;
    }

    /**
     * Prepare the file query.
     *
     * @param string $extensionRegex The regex for the extensions.
     * @return FileQuery
     */
    protected function prepareFileQuery(string $extensionRegex): FileQuery
    {
        $filter = FileFilter::create()->add(
            'depotFile',
            'readme.('.$extensionRegex.')$',
            FileFilter::COMPARE_REGEX,
            FileFilter::CONNECTIVE_AND,
            true
        )->add(
            'headAction',
            'delete',
            FileFilter::COMPARE_NOT_CONTAINS,
            FileFilter::CONNECTIVE_AND,
            true
        );

        $query = FileQuery::create()->setFilter($filter);
        return $query;
    }
}
