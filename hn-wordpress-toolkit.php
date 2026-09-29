<?php
/**
 * Plugin Name:  Hungry Nuggets WordPress Toolkit
 * Plugin URI:   https://github.com/thomasgermain93/hn-wordpress-toolkit
 * Description:  Boîte à outils WordPress de Hungry Nuggets, en modules activables depuis Réglages > HN Toolkit : conversion WebP/AVIF, désactivation des commentaires / articles / pages d'auteurs / pages de médias, ordre manuel par glisser-déposer, import/export de la configuration. Mise à jour automatique via GitHub.
 * Version:      1.4.0
 * Requires PHP: 7.3
 * Author:       Hungry Nuggets
 * Author URI:   https://hungrynuggets.com
 * License:      MIT
 *
 * -----------------------------------------------------------------------------
 * OVERVIEW (for developers and AI assistants)
 * -----------------------------------------------------------------------------
 * The plugin is a set of modules. Each module is a file in includes/modules/
 * and is only loaded when active: an inactive module registers no hook.
 *
 *  - includes/modules.php          Module registry (hn_toolkit_modules()),
 *                                  hn_module_active(), config option list.
 *  - includes/admin-dashboard.php  Settings > HN Toolkit: dashboard (one card
 *                                  and switch per module) + module tabs.
 *  - includes/class-updater.php    GitHub Releases auto-update.
 *  - includes/modules/*.php        The modules.
 *
 * Module states are boolean options. Modules that existed before 1.4.0 keep
 * their original option and default, so updating changes nothing on a site:
 *  - image_optimizer  hn_module_image_optimizer  default true
 *  - comments         hn_disable_comments        default false
 *  - posts            hn_disable_posts           default false
 *  - author_pages     hn_disable_author_pages    default false
 *  - media_pages      hn_disable_media_pages     default false
 *  - ordre_manuel     hn_module_ordre_manuel     default false
 *  - config           hn_module_config           default true
 *
 * Other options: hn_img_format, hn_img_quality, hn_img_maxsize (image
 * optimizer), hn_ordre_manuel (ordre manuel). All of them are included in the
 * config export.
 * -----------------------------------------------------------------------------
 */

defined('ABSPATH') || exit;

define('HN_TOOLKIT_VERSION', '1.4.0');
define('HN_TOOLKIT_FILE',    __FILE__);

require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/modules.php';
require_once __DIR__ . '/includes/admin-dashboard.php';

// ─── Auto-update via GitHub ────────────────────────────────────────────────
// Runs outside is_admin() so wp plugin update works in WP-CLI too.
add_action('init', function () {
    (new HN_Toolkit_Updater(
        HN_TOOLKIT_FILE,
        HN_TOOLKIT_VERSION,
        'thomasgermain93/hn-wordpress-toolkit'
    ))->init();
});

// ─── Active modules ────────────────────────────────────────────────────────
foreach (hn_toolkit_modules() as $hn_slug => $hn_module) {
    if (hn_module_active($hn_slug)) {
        require_once __DIR__ . '/includes/modules/' . $hn_module['file'];
    }
}
unset($hn_slug, $hn_module);
