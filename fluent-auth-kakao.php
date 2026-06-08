<?php
/**
 * Plugin Name: Fluent Auth Kakao
 * Description: FluentAuth에 카카오 로그인을 추가하는 애드온
 * Version:     0.1.0
 * Author:      Daviz153
 * Requires PHP: 8.2
 * Text Domain: fluent-auth-kakao
 */

defined('ABSPATH') || exit;

define('FAK_VERSION', '0.1.0');
define('FAK_FILE',    __FILE__);
define('FAK_DIR',     plugin_dir_path(__FILE__));
define('FAK_URL',     plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    if (!defined('FLUENT_AUTH_VERSION')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . '<strong>Fluent Auth Kakao</strong>: FluentAuth 플러그인이 필요합니다.'
                . '</p></div>';
        });
        return;
    }

    require_once FAK_DIR . 'includes/class-kakao-auth.php';
    require_once FAK_DIR . 'includes/class-kakao-handler.php';
    require_once FAK_DIR . 'includes/class-settings.php';
    require_once FAK_DIR . 'includes/class-plugin.php';

    FluentAuthKakao\Plugin::getInstance();
});
