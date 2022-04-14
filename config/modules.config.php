<?php
/**
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */
/**
 * List of enabled modules for this application.
 *
 * This should be an array of module namespaces used in the application.
 */
$core = [
    'Laminas\InputFilter',
    'Laminas\Mail',
    'Laminas\Mvc\I18n',
    'Laminas\Log',
    'Laminas\Router',
    'Laminas\Validator',
    'Laminas\Navigation',
    'Laminas\ZendFrameworkBridge',
    'Application',
    'Events',
    'Queue',
    'Users',
    'Reviews',
    'Workflow',
    'Projects',
    'Files',
    'Changes',
    'Jobs',
    'Groups',
    'Activity',
    'Comments',
    'Notifications',
    'Jira',
    'LibreOffice',
    'Xhprof',
    'Api',
    'Saml',
    'Search',
    'ShortLinks',
    'Attachments',
    'Mail',
    'Markdown',
    'Imagick',
    'Demo',
    'Xhprof',
    'Redis',
    'TestIntegration',
    'Menu',
    'Spec',
    'ThreeJS',
    'TagProcessor'
];

$custom = [];
if (file_exists(__DIR__ . '/custom.modules.config.php')) {
    $custom = require __DIR__ . '/custom.modules.config.php';
}
return array_merge($core, $custom);
