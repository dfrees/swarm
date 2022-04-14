<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

namespace Application\View\Helper;

use Application\Config\Services;
use Files\View\Helper\DecodeSpec;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Activity\View\Helper\Activity;
use Projects\View\Helper\ProjectList;
use Projects\View\Helper\ProjectSidebar;
use Reviews\View\Helper\Reviews;
use Reviews\View\Helper\Keywords;
use Reviews\View\Helper\ReviewersChanges;
use Reviews\View\Helper\AuthorChange;
use Users\View\Helper\User;
use Users\View\Helper\UserLink;
use Users\View\Helper\Avatar;
use Users\View\Helper\Avatars;
use Users\View\Helper\NotificationSettings;
use Users\View\Helper\Settings;
use Files\View\Helper\DecodeFilespec;
use Files\View\Helper\FileSize;
use Files\View\Helper\FileTypeView;
use Comments\View\Helper\Comments;
use Laminas\View\Helper\BasePath;
use Groups\View\Helper\GroupToolbar;
use Groups\View\Helper\GroupSidebar;
use Groups\View\Helper\Avatar as GroupAvatar;
use Groups\View\Helper\Avatars as GroupAvatars;
use Groups\View\Helper\NotificationSettings as GroupNotificationSettings;
use Markdown\View\Helper\MarkupMarkdown;

class ViewHelperFactory implements FactoryInterface
{
    const ACTIVITY              = 'activity';
    const PROJECT_LIST          = 'projectList';
    const PROJECT_SIDEBAR       = 'projectSidebar';
    const REVIEWS               = 'reviews';
    const REVIEW_KEYWORDS       = 'reviewKeywords';
    const REVIEWERS_CHANGES     = 'reviewersChanges';
    const AUTHOR_CHANGE         = 'authorChange';
    const USER                  = 'user';
    const USER_LINK             = 'userLink';
    const AVATAR                = 'avatar';
    const AVATARS               = 'avatars';
    const NOTIFICATION_SETTINGS = 'notificationSettings';
    const USER_SETTINGS         = 'userSettings';
    const COMMENTS              = 'comments';
    const ASSET_BASE_PATH       = 'assetBasePath';
    const BREADCRUMBS           = 'breadcrumbs';
    const BODY_CLASS            = 'bodyClass';
    const CSRF                  = 'csrf';
    const ESCAPE_FULL_URL       = 'escapeFullUrl';
    const HEAD_LINK             = 'swarmHeadLink';
    const HEAD_SCRIPT           = 'swarmHeadScript';
    const LINKIFY               = 'linkify';
    const PERMISSIONS           = 'permissions';
    const PREFORMAT             = 'preformat';
    const QUALIFIED_URL         = 'qualifiedUrl';
    const REQUEST               = 'request';
    const SHORTEN_STACK_TRACE   = 'shortenStackTrace';
    const TRUNCATE              = 'truncate';
    const UTF8_FILTER           = 'utf8Filter';
    const WORDIFY               = 'wordify';
    const WORD_WRAP             = 'wordWrap';
    const T                     = 't';
    const TE                    = 'te';
    const TP                    = 'tp';
    const TPE                   = 'tpe';
    const FILE_SIZE             = 'fileSize';
    const DECODE_FILE_SPEC      = 'decodeFilespec';
    const DECODE_SPEC           = 'decodeSpec';
    const GROUP_TOOLBAR         = 'groupToolbar';
    const GROUP_SIDEBAR         = 'groupSidebar';
    const GROUP_AVATAR          = 'groupAvatar';
    const GROUP_AVATARS         = 'groupAvatars';
    const GROUP_NOT_SETTINGS    = 'groupNotificationSettings';
    const MARKUP                = 'markupMarkdown';
    const SERVICE               = 'serviceContainer';
    const FILE_TYPE_VIEW        = 'fileTypeView';
    const ROUTE_MATCH           = 'routeMatch';

    /**
     * @inheritDoc
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        switch ($requestedName) {
            case self::SERVICE:
                return new Service($container);
            case self::ACTIVITY:
                return new Activity();
            case self::PROJECT_LIST:
                return new ProjectList($container);
            case self::PROJECT_SIDEBAR:
                return new ProjectSidebar($container);
            case self::REVIEWS:
                return new Reviews($container);
            case self::REVIEW_KEYWORDS:
                return new Keywords($container);
            case self::REVIEWERS_CHANGES:
                return new ReviewersChanges();
            case self::AUTHOR_CHANGE:
                return new AuthorChange();
            case self::USER:
                return new User($container);
            case self::USER_LINK:
                return new UserLink($container);
            case self::AVATAR:
                return new Avatar($container);
            case self::AVATARS:
                return new Avatars($container);
            case self::NOTIFICATION_SETTINGS:
                return new NotificationSettings();
            case self::USER_SETTINGS:
                return new Settings();
            case self::COMMENTS:
                return new Comments($container);
            case self::ASSET_BASE_PATH:
                return new BasePath();
            case self::BREADCRUMBS:
                return new Breadcrumbs();
            case self::BODY_CLASS:
                return new BodyClass();
            case self::CSRF:
                return new Csrf($container);
            case self::ESCAPE_FULL_URL:
                return new EscapeFullUrl();
            case self::HEAD_LINK:
                return new HeadLink($container);
            case self::HEAD_SCRIPT:
                return new HeadScript($container);
            case self::LINKIFY:
                return $container->get(Services::LINKIFY);
            case self::PERMISSIONS:
                return new Permissions($container);
            case self::PREFORMAT:
                return new Preformat($container);
            case self::QUALIFIED_URL:
                return new QualifiedUrl($container);
            case self::REQUEST:
                return new Request($container);
            case self::SHORTEN_STACK_TRACE:
                return new ShortenStackTrace();
            case self::TRUNCATE:
                return new Truncate();
            case self::UTF8_FILTER:
                return new Utf8Filter();
            case self::WORDIFY:
                return new Wordify();
            case self::WORD_WRAP:
                return new WordWrap();
            case self::T:
                return new Translate();
            case self::TE:
                return new TranslateEscape();
            case self::TP:
                return new TranslatePlural();
            case self::TPE:
                return new TranslatePluralEscape();
            case self::FILE_SIZE:
                return new FileSize($container);
            case self::DECODE_FILE_SPEC:
                return new DecodeFilespec();
            case self::DECODE_SPEC:
                return new DecodeSpec();
            case self::GROUP_TOOLBAR:
                return new GroupToolbar($container);
            case self::GROUP_SIDEBAR:
                return new GroupSidebar($container);
            case self::GROUP_AVATAR:
                return new GroupAvatar($container);
            case self::GROUP_AVATARS:
                return new GroupAvatars($container);
            case self::GROUP_NOT_SETTINGS:
                return new GroupNotificationSettings();
            case self::MARKUP:
                return new MarkupMarkdown($container);
            case self::FILE_TYPE_VIEW:
                return new FileTypeView($container);
            case self::ROUTE_MATCH:
                return new RouteMatch();
        }
        return null;
    }
}
