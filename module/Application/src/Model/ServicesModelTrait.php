<?php

namespace Application\Model;

use Application\Config\Services;
use Application\I18n\TranslatorFactory;
use Changes\Service\IChangeComparator;
use Projects\Helper\IFindAffected;

/**
 * This is introduced as a convenience to get hold of services in model
 * classes that are not currently managed by a DAO.
 * @package Application\Model
 */
trait ServicesModelTrait
{
    public static $servicesVar = 'SERVICES';

    /**
     * Get the comment DAO.
     * @return IModelDAO the comment DAO
     */
    public static function getCommentDao() : IModelDAO
    {
        return $GLOBALS[self::$servicesVar]->get(IModelDAO::COMMENT_DAO);
    }

    /**
     * Get the user DAO.
     * @return IModelDAO the user DAO
     */
    public static function getUserDao() : IModelDAO
    {
        return $GLOBALS[self::$servicesVar]->get(IModelDAO::USER_DAO);
    }

    /**
     * Get the group DAO.
     * @return IModelDAO the group DAO
     */
    public static function getGroupDao() : IModelDAO
    {
        return $GLOBALS[self::$servicesVar]->get(IModelDAO::GROUP_DAO);
    }

    /**
     * Get the Project DAO.
     * @return IModelDAO the project DAO
     */
    public static function getProjectDao() : IModelDAO
    {
        return $GLOBALS[self::$servicesVar]->get(IModelDAO::PROJECT_DAO);
    }

    /**
     * Get the Change DAO.
     * @return mixed the change DAO
     */
    public static function getChangeDao()
    {
        return $GLOBALS[self::$servicesVar]->get(IModelDAO::CHANGE_DAO);
    }

    /**
     * Get the Change Comparator service.
     * @return IChangeComparator  The change comparator Service
     */
    public static function getChangeComparatorService()
    {
        return $GLOBALS[self::$servicesVar]->get(Services::CHANGE_COMPARATOR);
    }

    /**
     * Get the Change service.
     * @return mixed The change Service
     */
    public static function getChangeService()
    {
        return $GLOBALS[self::$servicesVar]->get(Services::CHANGE_SERVICE);
    }

    /**
     * Get the Workflow manager.
     * @return mixed the workflow manager
     */
    public static function getWorkflowManager()
    {
        return $GLOBALS[self::$servicesVar]->get(Services::WORKFLOW_MANAGER);
    }

    /**
     * Get the Workflow DAO.
     * @return IModelDAO the workflow DAO
     */
    public static function getWorkflowDao() : IModelDAO
    {
        return $GLOBALS[self::$servicesVar]->get(IModelDAO::WORKFLOW_DAO);
    }

    /**
     * Get the TestDefinition DAO.
     * @return IModelDAO the TestDefinition DAO
     */
    public static function getTestDefinitionDao() : IModelDAO
    {
        return $GLOBALS[self::$servicesVar]->get(IModelDAO::TEST_DEFINITION_DAO);
    }

    /**
     * Get the services from $GLOBALS
     */
    public static function getServices()
    {
        return $GLOBALS[self::$servicesVar];
    }

    /**
     * Set the services in $GLOBALS
     * @param $services
     */
    public static function setServices($services)
    {
        $GLOBALS[self::$servicesVar] = $services;
    }

    /**
     * Get the service to find affected projects
     * @return IFindAffected
     */
    public static function getAffectedProjectsService()
    {
        return $GLOBALS[self::$servicesVar]->get(Services::AFFECTED_PROJECTS);
    }

    /**
     * Get the service for translation
     * @return mixed the translator
     */
    public static function getTranslatorService()
    {
        return $GLOBALS[self::$servicesVar]->get(TranslatorFactory::SERVICE);
    }

    /**
     * Get the linkify service
     * @return mixed
     */
    public static function getLinkifyService()
    {
        return $GLOBALS[self::$servicesVar]->get(Services::LINKIFY);
    }
}
