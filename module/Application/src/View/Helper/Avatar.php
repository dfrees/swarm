<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
namespace Application\View\Helper;

use Application\Config\ConfigManager;

/**
 * Renders an avatar
 *
 * @package Application\View\Helper
 */
class Avatar
{
    const DEFAULT_COUNT = 6;
    const GROUPS_AVATAR = 'groups';
    const ROUNDED       = 'img-rounded';
    const SIZE_64       = '64';
    const SIZE_128      = '128';
    const SIZE_256      = '256';
    const DEFAULTVAL    = 'default';
    const URI           = 'uri';

    /**
     * Renders a image tag and optional link for the an avatar.
     *
     * @param  mixed          $services    application services
     * @param  AbstractHelper $view        the Zend view
     * @param  string         $id          id for the avatar owner
     * @param  string         $email       email for the avatar owner
     * @param  string         $name        name of the avatar owner
     * @param  string|int     $size        the size of the avatar (e.g. 64, 128)
     * @param  bool           $link        optional - link to the user (default=true)
     * @param  bool           $class       optional - class to add to the image
     * @param  bool           $fluid       optional - match avatar size to the container
     * @param  string         $sheetSuffix optional - suffix to append to the size to get the sprite sheet
     *
     * @return string
     * @throws \Application\Config\ConfigException
     */
    public static function getAvatar(
        $services,
        $view,
        $id,
        $email,
        $name,
        $size,
        $link = true,
        $class = null,
        $fluid = true,
        $sheetSuffix = ''
    ) {
        $config = $services->get('config');
        $size   = (int) $size ? : static::SIZE_64;
        // pick a default image and color for this user - if no user, pick system avatar
        // we do this by summing the ascii values of all characters in their id
        // then we modulo divide by 6 to get a remainder in the range of 0-5.
        $class .= ' as-' . $size . $sheetSuffix;

        $details = self::getAvatarDetails($config, $id, $email, $size);
        $url     = $details[static::URI];
        if ($id) {
            $i      = $details[static::DEFAULTVAL];
            $class .= ' ai-' . $i;
            $class .= ' ac-' . $i;
        } else {
            $class .= ' avatar-system';
        }

        // build the actual img tag we'll be using
        $fluid = $fluid ? 'fluid' : '';
        $class = $view->escapeHtmlAttr(trim('avatar ' . $class));
        $alt   = $view->escapeHtmlAttr($name);
        $html  = '<img width="' . $size . '" height="' . $size . '" alt="' . $alt . '"'
            . ' src="' . $url . '" data-user="' . $view->escapeHtmlAttr($id) . '"'
            . ' class="' . $class . '" onerror="$(this).trigger(\'img-error\')"'
            . ' onload="$(this).trigger(\'img-load\')">';

        $href = $sheetSuffix === static::GROUPS_AVATAR ? $view->url('group', ['group' => $id]) :
            $view->url('user', ['user' => $id]);
        if ($link && $id) {
            $html = '<a href="' . $href . '" title="' . $alt . '"' . ' class="avatar-wrapper avatar-link ' . $fluid
                . '">' . $html . '</a>';
        } else {
            $html = '<div class="avatar-wrapper ' . $fluid . '" title="' . $alt . '">' . $html . '</div>';
        }

        return $html;
    }

    /**
     * This get you the avatar details and returns it to be used.
     *
     * @param array  $config   This is the config manager
     * @param string $id       The username that is given
     * @param string $email    The email address for the user
     * @param string $size     The size we want to enforce the image to.
     *
     * @return array
     * @throws \Application\Config\ConfigException
     */
    public static function getAvatarDetails($config, $id, $email, $size = Avatar::SIZE_64)
    {

        // determine the url to use for this user's avatar based on the configured pattern
        // if user is null or no pattern is configured, fallback to a blank gif via data uri
        $webServerHttps         = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $configExternalUrlHttps = isset($config['environment']['external_url']) &&
            strpos($config['environment']['external_url'], 'https') !== false;

        // build url with https if either of web server configuration or external url in swarm
        // configuration has https enabled
        $url = $webServerHttps || $configExternalUrlHttps
            ? ConfigManager::getValue($config, ConfigManager::AVATARS_HTTPS)
            : ConfigManager::getValue($config, ConfigManager::AVATARS_HTTP);
        $url = $url && $id
            ? $url
            : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

        $replace = [
            '{user}'    => $id,
            '{email}'   => $email,
            '{hash}'    => $email ? md5(strtolower($email)) : '00000000000000000000000000000000',
            '{default}' => 'blank',
            '{size}'    => $size,
        ];

        return [
            static::DEFAULTVAL => ((array_sum(array_map('ord', str_split($id))) % static::DEFAULT_COUNT) + 1),
            static::URI        => str_replace(array_keys($replace), array_map('rawurlencode', $replace), $url),
        ];
    }
}
