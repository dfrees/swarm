<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Projects\View\Helper;

use Application\Model\IModelDAO;
use Projects\Filter\ProjectList as ProjectListFilter;
use Projects\Model\Project as ProjectModel;
use Application\View\Helper\AbstractHelper;

class ProjectList extends AbstractHelper
{
    const HIDE_PRIVATE = 'hidePrivate';
    const NO_LINK      = 'noLink';
    const URL_HELPER   = 'urlHelper';
    const STYLE        = 'style';

    /**
     * Returns the markup for a project/branch list
     *
     * @param   array|string|null   $projects   the projects/branches to list
     * @param   string|null         $active     the active project if applicable
     * @param   array|null          $options      HIDE_PRIVATE - do not render private projects
     *                                                 NO_LINK - disable linking to the project
     *                                              URL_HELPER - optional, plugin/helper to use when generating links
     *                                                   STYLE - set to a string with custom styles for the link
     * @return  string              the project list html
     * @throws \Exception
     */
    public function __invoke($projects = null, $active = null, $options = null)
    {
        if (!$projects || empty($projects)) {
            return '';
        }
        $options   = (array) $options + [
            static::HIDE_PRIVATE => false,
            static::NO_LINK      => false,
            static::URL_HELPER   => null,
            static::STYLE        => ''
            ];
        $filter    = new ProjectListFilter;
        $projects  = $filter->filter($projects);
        $view      = $this->getView();
        $services  = $this->services;
        $style     = $options[static::STYLE]
            ? ' style="' . $view->escapeHtmlAttr($options[static::STYLE]) . '"'
            : '';
        $urlHelper = $options[static::URL_HELPER] ?: [$view, 'url'];

        // url helper must be a callable.
        if (!is_callable($urlHelper)) {
            throw new \InvalidArgumentException(
                'Url helper must be a callable.'
            );
        }

        $projectDAO = $services->get(IModelDAO::PROJECT_DAO);
        // fetching the models for all projects we are interested in so we can get the project name
        // for the links.
        $models = $projectDAO->fetchAll(
            [ProjectModel::FETCH_BY_IDS => array_keys($projects)],
            $services->get('p4_admin')
        );

        // if we are hiding private projects, filter $projects list to keep only public projects
        if ($options[static::HIDE_PRIVATE]) {
            foreach ($projects as $project => $branches) {
                if (!isset($models[$project]) || $models[$project]->isPrivate()) {
                    unset($projects[$project]);
                }
            }
        }

        // we don't need to output the project id if we have an active project
        // with at least one branch and there are no other projects.
        $justBranch = strlen($active)
            && isset($projects[$active])
            && count($projects[$active]) > 0
            && count($projects) == 1;

        // generate a list of project-branch names. we will later implode with ', ' to join them
        $names  = [];
        $noHref = $options[static::NO_LINK];
        foreach ($projects as $project => $branches) {
            $names[] = $this->buildProjectLink(
                $view,
                $noHref,
                $project,
                $style,
                $urlHelper,
                $models[$project],
                $justBranch,
                $branches ? implode(", ", $branches) : null
            );
        }
        return implode(", \n", $names);
    }
    /**
     * This is a generic function to build the links for projects or branch.
     *
     * @param      $view
     * @param      $noHref
     * @param      $project
     * @param      $style
     * @param      $url
     * @param      $model
     * @param      $justBranch
     * @param null $branches
     *
     * @return string
     */
    private function buildProjectLink($view, $noHref, $project, $style, $url, $model, $justBranch, $branches = null)
    {
        if ($noHref) {
            $link = ($justBranch ? '' : $view->escapeHtml($project) . ':')
                . (is_null($branches) ? '' : $view->escapeHtml($branches));
        } else {
            $link = '<a data-name="' . $view->escapeHtml($model->getName()) . '" data-id="' . $model->getId()
                . '" href="'
                . call_user_func($url, 'project', ['project' => $project])
                . '"' . $style .'>'
                . ($justBranch ? '' : $view->escapeHtml($project) . ':')
                . (is_null($branches) ? '' : $view->escapeHtml($branches))
                . '</a>';
        }
        return $link;
    }
}
