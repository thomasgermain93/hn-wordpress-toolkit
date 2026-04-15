<?php
/**
 * Plugin Name:  Hungry Nuggets WordPress Toolkit
 * Plugin URI:   https://github.com/thomasgermain93/hn-wordpress-toolkit
 * Description:  Hungry Nuggets internal WordPress toolkit. Modules: image optimization (WebP/AVIF), comments/posts/author pages/media pages disablers, config import/export. GitHub-based auto-update.
 * Version:      1.1.7
 * Requires PHP: 8.0
 * Author:       Hungry Nuggets
 * Author URI:   https://hungrynuggets.com
 * License:      MIT
 *
 * -----------------------------------------------------------------------------
 * OVERVIEW (for developers and AI assistants)
 * -----------------------------------------------------------------------------
 * This plugin intercepts WordPress image uploads and converts them to WebP or
 * AVIF, replaces the original file, and updates the attachment metadata accordingly.
 * It also provides a configuration import/export module to replicate settings
 * across WordPress installations.
 *
 * Key behaviours:
 *  - Conversion happens in the `wp_handle_upload` filter at priority 5, before
 *    WordPress generates thumbnails.
 *  - PNG files with transparency are converted via GD (imagecreatefrompng +
 *    imagewebp) to preserve the alpha channel. All other formats use
 *    wp_get_image_editor() for JPEG/GIF → WebP, or Imagick for AVIF.
 *  - The original file is deleted after a successful conversion; only the
 *    converted file is stored on disk.
 *  - MIME type detection is done via file extension, not $upload['type'], to
 *    work around a WP 6.9.x bug that misdetects JPEG as image/avif.
 *  - Only the 'thumbnail' intermediate size is generated (150×150 crop).
 *    This keeps the uploads directory lean for sites that manage their own
 *    responsive sizing (e.g. Breakdance, Elementor, etc.).
 *  - Quality and max-dimension settings are stored in wp_options and exposed
 *    in Settings > Media.
 *  - Config import/export is available via Settings > HN Toolkit. Export
 *    generates a JSON file; import validates and applies options using the
 *    same sanitize callbacks as the settings API.
 *  - Automatic updates are provided via HN_Toolkit_Updater, which
 *    checks the GitHub Releases API every 12 hours.
 *
 * WordPress options:
 *  - hn_img_format           (string)  'webp' | 'avif'   default: 'webp'
 *  - hn_img_quality          (int)     1–100              default: 90
 *  - hn_img_maxsize          (int)     pixels             default: 2000
 *  - hn_disable_comments     (bool)    true | false       default: false
 *  - hn_disable_author_pages (bool)    true | false       default: false
 *  - hn_disable_media_pages  (bool)    true | false       default: false
 *
 * Filters used:
 *  - wp_handle_upload              priority 5  — conversion entry point
 *  - intermediate_image_sizes_advanced         — limits to thumbnail only
 *  - jpeg_quality                              — syncs WP editor quality
 *  - wp_editor_set_quality                     — syncs WP editor quality
 *
 * Admin hooks:
 *  - admin_init    — registers settings in the 'media' option group
 *  - admin_menu    — adds Settings > HN Toolkit page
 *  - admin_post_hn_export_config — handles config export
 *  - admin_post_hn_import_config — handles config import
 *
 * Update flow:
 *  - pre_set_site_transient_update_plugins — injects GitHub release if newer
 *  - plugins_api                           — provides plugin info modal data
 *
 * ── Comments module ──────────────────────────────────────────────────────
 * When hn_disable_comments is true, all comment & pingback functionality
 * is suppressed site-wide:
 *  - comments_open and pings_open filters return false (priority 20)
 *  - wp_count_comments returns an empty object (zeroed counts)
 *  - The "Comments" admin menu item is removed
 *  - The "Comments" meta box is removed from all post types
 *  - Native Discussion settings inputs are greyed out via inline JS
 *
 * WordPress options:
 *  - hn_disable_comments (bool)  default: false
 *
 * Admin hooks:
 *  - admin_init    — registers setting in the 'discussion' option group
 *  - admin_menu    — removes Comments menu item (priority 999)
 *  - add_meta_boxes — removes commentsdiv meta box from all post types
 *
 * ── Author pages module ──────────────────────────────────────────────────
 * When hn_disable_author_pages is true, author archive pages are redirected
 * to the homepage with a 301 status code.
 *  - admin_init    — registers setting in the 'general' option group
 *  - template_redirect — 301 redirect author archives to home_url('/')
 *
 * ── Media pages module ───────────────────────────────────────────────────
 * When hn_disable_media_pages is true, attachment pages are redirected
 * (301) to the direct file URL (prevents thin content indexing).
 *  - admin_init — registers setting in the 'media' option group
 *  - template_redirect — redirects attachment pages to file URL (301)
 * -----------------------------------------------------------------------------
 */

defined('ABSPATH') || exit;

define('HN_TOOLKIT_VERSION', '1.1.7');
define('HN_TOOLKIT_FILE',    __FILE__);

require_once __DIR__ . '/includes/class-updater.php';

// ─── Auto-update via GitHub ────────────────────────────────────────────────
// Runs outside is_admin() so wp plugin update works in WP-CLI too.
add_action('init', function () {
    (new HN_Toolkit_Updater(
        HN_TOOLKIT_FILE,
        HN_TOOLKIT_VERSION,
        'thomasgermain93/hn-wordpress-toolkit'
    ))->init();
});

// ─── Option accessors ──────────────────────────────────────────────────────

/**
 * Target format for converted images.
 * @return string 'webp' | 'avif'
 */
function hn_img_format(): string {
    return get_option('hn_img_format', 'webp');
}

/**
 * Compression quality (1–100).
 */
function hn_img_quality(): int {
    return (int) get_option('hn_img_quality', 90);
}

/**
 * Maximum width or height in pixels. Images larger than this are resized
 * proportionally before conversion.
 */
function hn_img_maxsize(): int {
    return (int) get_option('hn_img_maxsize', 2000);
}

/**
 * Whether media (attachment) pages are disabled.
 */
function hn_media_pages_disabled(): bool {
    return (bool) get_option('hn_disable_media_pages', false);
}

// ─── Settings API — Settings > Media ──────────────────────────────────────
add_action('admin_init', function () {

    register_setting('media', 'hn_img_format', [
        'sanitize_callback' => fn($v) => in_array($v, ['webp', 'avif'], true) ? $v : 'webp',
    ]);
    register_setting('media', 'hn_img_quality', [
        'sanitize_callback' => fn($v) => max(1, min(100, (int) $v)),
    ]);
    register_setting('media', 'hn_img_maxsize', [
        'sanitize_callback' => fn($v) => max(100, (int) $v),
    ]);

    add_settings_section('hn_img_section', 'Optimisation des images', '__return_false', 'media');

    add_settings_field('hn_img_format', 'Format de conversion', function () {
        $val     = hn_img_format();
        $avif_ok = class_exists('Imagick') && in_array('AVIF', Imagick::queryFormats(), true);
        ?>
        <select name="hn_img_format" id="hn_img_format">
            <option value="webp" <?php selected($val, 'webp'); ?>>WebP</option>
            <option value="avif" <?php selected($val, 'avif'); ?> <?php disabled(! $avif_ok); ?>>
                AVIF<?php echo $avif_ok ? '' : ' (Imagick libavif non disponible)'; ?>
            </option>
        </select>
        <p class="description">
            WebP fonctionne via GD (toujours disponible). AVIF nécessite Imagick compilé avec libavif.
            <?php if (! $avif_ok): ?>
                <br><strong>Ce serveur ne supporte pas AVIF.</strong>
            <?php endif; ?>
        </p>
        <?php
    }, 'media', 'hn_img_section');

    add_settings_field('hn_img_quality', 'Qualité de compression', function () {
        $val = hn_img_quality();
        echo '<input type="number" name="hn_img_quality" id="hn_img_quality" '
           . 'value="' . esc_attr($val) . '" min="1" max="100" class="small-text"> %';
        echo '<p class="description">Qualité de l\'image convertie (1–100). Recommandé&nbsp;: 85–92.</p>';
    }, 'media', 'hn_img_section');

    add_settings_field('hn_img_maxsize', 'Taille max (px)', function () {
        $val = hn_img_maxsize();
        echo '<input type="number" name="hn_img_maxsize" id="hn_img_maxsize" '
           . 'value="' . esc_attr($val) . '" min="100" step="1" class="small-text"> px';
        echo '<p class="description">Largeur/hauteur maximale après conversion. Le ratio est conservé.</p>';
    }, 'media', 'hn_img_section');

    // ── Media pages (attachments) ────────────────────────────────────────
    register_setting('media', 'hn_disable_media_pages', [
        'sanitize_callback' => fn($v) => (bool) $v,
    ]);

    add_settings_section('hn_media_section', '', '__return_false', 'media');

    add_settings_field(
        'hn_disable_media_pages',
        'Pages de médias',
        'hn_render_disable_media_pages_field',
        'media',
        'hn_media_section'
    );
});

// ─── Quality sync ──────────────────────────────────────────────────────────
add_filter('jpeg_quality',          fn() => hn_img_quality());
add_filter('wp_editor_set_quality', fn() => hn_img_quality());

/**
 * Render the toggle field for disabling media pages.
 */
function hn_render_disable_media_pages_field(): void {
    $val = hn_media_pages_disabled();
    ?>
    <label class="hn-toggle-wrap" for="hn_disable_media_pages">
        <input type="hidden" name="hn_disable_media_pages" value="0">
        <input type="checkbox" class="hn-toggle-input" name="hn_disable_media_pages" id="hn_disable_media_pages" value="1" <?php checked($val); ?>>
        <span class="hn-toggle-track"></span>
        <span>Désactiver les pages de médias</span>
    </label>
    <p class="description">Redirige les pages d'attachments vers l'URL directe du fichier (301).</p>
    <?php
}

// ─── Front-end: redirect attachment pages ─────────────────────────────────
add_action('template_redirect', function () {
    if (hn_media_pages_disabled() && is_attachment()) {
        $url = wp_get_attachment_url(get_the_ID());
        wp_redirect($url ?: home_url('/'), 301);
        exit;
    }
});

// ─── Upload conversion ─────────────────────────────────────────────────────
/**
 * Convert uploaded images to the configured format.
 * Runs at priority 5, before WordPress generates intermediate sizes.
 *
 * @param  array{file: string, url: string, type: string} $upload
 * @return array
 */
add_filter('wp_handle_upload', function (array $upload): array {

    $file_path = $upload['file'];
    $ext       = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

    // Resolve actual MIME type from extension.
    // WP 6.9.x has a bug that can misdetect JPEG as image/avif — do not trust
    // $upload['type'] alone for JPEG/PNG/GIF.
    $real_type = $upload['type'];
    if ($ext === 'png')                              $real_type = 'image/png';
    elseif (in_array($ext, ['jpg', 'jpeg'], true))  $real_type = 'image/jpeg';
    elseif ($ext === 'gif')                          $real_type = 'image/gif';

    if (! in_array($real_type, ['image/jpeg', 'image/png', 'image/gif'], true)) {
        return $upload; // SVG, AVIF, WebP, etc. — skip.
    }

    $format   = hn_img_format();
    $quality  = hn_img_quality();
    $maxsize  = hn_img_maxsize();
    $mime     = 'image/' . $format;

    $dir      = pathinfo($file_path, PATHINFO_DIRNAME);
    $name     = pathinfo($file_path, PATHINFO_FILENAME);
    $new_path = $dir . '/' . $name . '.' . $format;
    $converted = false;

    if ($format === 'webp') {
        if ($real_type === 'image/png' && function_exists('imagecreatefrompng') && function_exists('imagewebp')) {
            // Use GD for PNG → WebP to preserve alpha channel.
            $src = imagecreatefrompng($file_path);
            if ($src) {
                $src = hn_img_resize_gd($src, $maxsize);
                imagealphablending($src, false);
                imagesavealpha($src, true);
                if (imagewebp($src, $new_path, $quality)) {
                    $converted = true;
                }
                imagedestroy($src);
            }
        } else {
            // JPEG / GIF → WebP via wp_get_image_editor.
            $converted = hn_img_via_editor($file_path, $new_path, $mime, $quality, $maxsize);
        }

    } elseif ($format === 'avif' && class_exists('Imagick')) {
        try {
            $img = new Imagick($file_path);
            if ($img->getImageWidth() > $maxsize || $img->getImageHeight() > $maxsize) {
                $img->resizeImage($maxsize, $maxsize, Imagick::FILTER_LANCZOS, 1, true);
            }
            $img->setImageFormat('avif');
            $img->setImageCompressionQuality($quality);
            $img->stripImage();
            $img->writeImage($new_path);
            $img->destroy();
            $converted = file_exists($new_path);
        } catch (Exception $e) {
            // Imagick AVIF not available — leave original intact.
        }
    }

    if ($converted && file_exists($new_path)) {
        @unlink($file_path);
        $upload['file'] = $new_path;
        $upload['url']  = str_replace(basename($upload['url']), basename($new_path), $upload['url']);
        $upload['type'] = $mime;
    }

    return $upload;

}, 5);

// ─── Intermediate sizes: thumbnail only ────────────────────────────────────
/**
 * Suppress medium, medium_large and large sub-sizes.
 * Only the thumbnail (150×150 crop) is generated on upload.
 */
add_filter('intermediate_image_sizes_advanced', function (array $sizes): array {
    return isset($sizes['thumbnail']) ? ['thumbnail' => $sizes['thumbnail']] : [];
});

// ─── GD helpers ────────────────────────────────────────────────────────────

/**
 * Resize a GD image resource if either dimension exceeds $maxsize.
 * Preserves aspect ratio and alpha channel.
 *
 * @param  \GdImage $src
 * @param  int      $maxsize
 * @return \GdImage  Original resource if no resize needed, new resource otherwise.
 */
function hn_img_resize_gd(\GdImage $src, int $maxsize): \GdImage {
    $w = imagesx($src);
    $h = imagesy($src);

    if ($w <= $maxsize && $h <= $maxsize) {
        return $src;
    }

    $ratio = min($maxsize / $w, $maxsize / $h);
    $nw    = (int) round($w * $ratio);
    $nh    = (int) round($h * $ratio);

    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    return $dst;
}

/**
 * Convert an image file using wp_get_image_editor (Imagick or GD).
 * Used for JPEG and GIF → WebP conversions.
 *
 * @param  string $source   Absolute path to source file.
 * @param  string $dest     Absolute path for output file.
 * @param  string $mime     Target MIME type (e.g. 'image/webp').
 * @param  int    $quality  Compression quality (1–100).
 * @param  int    $maxsize  Max width/height in pixels.
 * @return bool             True if conversion succeeded.
 */
function hn_img_via_editor(string $source, string $dest, string $mime, int $quality, int $maxsize): bool {
    $editor = wp_get_image_editor($source);
    if (is_wp_error($editor)) {
        return false;
    }

    $size = $editor->get_size();
    if ($size && ($size['width'] > $maxsize || $size['height'] > $maxsize)) {
        $editor->resize($maxsize, $maxsize, false);
    }

    $editor->set_quality($quality);
    $saved = $editor->save($dest, $mime);

    if (is_wp_error($saved)) {
        return false;
    }

    return file_exists($saved['path'] ?? $dest);
}

// ─── Comments module — Global disable ─────────────────────────────────────

/**
 * Whether comments are globally disabled.
 */
function hn_comments_disabled(): bool {
    return (bool) get_option('hn_disable_comments', false);
}

// ─── Settings API — Settings > Discussion ─────────────────────────────────
add_action('admin_init', function () {

    register_setting('discussion', 'hn_disable_comments', [
        'sanitize_callback' => fn($v) => (bool) $v,
    ]);

    // Empty title = no <h2> rendered by WP, we render our own header in the field.
    add_settings_section('hn_comments_section', '', '__return_false', 'discussion');

    add_settings_field(
        'hn_disable_comments',
        'Commentaires',
        'hn_render_disable_comments_field',
        'discussion',
        'hn_comments_section'
    );
});

// ─── Inline CSS: toggle switch styles ─────────────────────────────────────

/**
 * Output inline CSS for the HN toggle switch component.
 */
function hn_render_toggle_css(): void {
    ?>
    <style>
    .hn-toggle-wrap { display:flex; align-items:center; gap:10px; cursor:pointer; }
    .hn-toggle-input { position:absolute; opacity:0; width:0; height:0; }
    .hn-toggle-track {
        position:relative; display:inline-block;
        width:44px; height:24px;
        background:#ccc; border-radius:24px; transition:.25s;
        flex-shrink:0;
    }
    .hn-toggle-input:checked + .hn-toggle-track { background:#2271b1; }
    .hn-toggle-track::after {
        content:''; position:absolute;
        width:18px; height:18px;
        left:3px; bottom:3px;
        background:#fff; border-radius:50%; transition:.25s;
    }
    .hn-toggle-input:checked + .hn-toggle-track::after { left:23px; }
    .hn-toggle-input:focus + .hn-toggle-track { box-shadow:0 0 0 2px #2271b1; }
    </style>
    <?php
}

add_action('admin_head-options-discussion.php', 'hn_render_toggle_css');
add_action('admin_head-options-general.php',    'hn_render_toggle_css');
add_action('admin_head-options-media.php',      'hn_render_toggle_css');

/**
 * Render the toggle field for disabling comments.
 */
function hn_render_disable_comments_field(): void {
    $disabled = hn_comments_disabled();
    ?>
    <label class="hn-toggle-wrap" for="hn_disable_comments">
        <input type="hidden" name="hn_disable_comments" value="0">
        <input type="checkbox"
               class="hn-toggle-input"
               name="hn_disable_comments"
               id="hn_disable_comments"
               value="1"
               <?php checked($disabled); ?>>
        <span class="hn-toggle-track"></span>
        <span>Désactiver les commentaires sur tout le site</span>
    </label>
    <p class="description">
        Quand activé, les commentaires et pings sont fermés partout, le menu Commentaires est masqué
        et les réglages natifs ci-dessous sont grisés.
    </p>
    <?php
}

// ─── JS: move section to top + grey out native fields ─────────────────────
add_action('admin_footer-options-discussion.php', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.getElementById('hn_disable_comments');
        if (!toggle) return;

        // Move our section (table) to the very top of the form.
        var table  = toggle.closest('table.form-table');
        var form   = toggle.closest('form');
        if (table && form) {
            var firstTable = form.querySelector('table.form-table');
            if (firstTable && firstTable !== table) {
                form.insertBefore(table, firstTable);
            }
        }

        // Disable / enable native WP fields based on toggle state.
        function setNativeFields(isDisabled) {
            if (!form) return;
            form.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.name === 'hn_disable_comments' || el.type === 'hidden' || el.type === 'submit') return;
                el.disabled = isDisabled;
                el.style.opacity = isDisabled ? '0.4' : '';
            });
        }

        setNativeFields(toggle.checked);
        toggle.addEventListener('change', function () { setNativeFields(this.checked); });
    });
    </script>
    <?php
});

// ─── Front-end & API: close comments and pings ───────────────────────────
add_filter('comments_open', function (bool $open): bool {
    return hn_comments_disabled() ? false : $open;
}, 20);

add_filter('pings_open', function (bool $open): bool {
    return hn_comments_disabled() ? false : $open;
}, 20);

// ─── Admin: zero out comment counts ──────────────────────────────────────
add_filter('wp_count_comments', function ($counts) {
    if (! hn_comments_disabled()) {
        return $counts;
    }
    return (object) [
        'approved'       => 0,
        'moderated'      => 0,
        'awaiting_moderation' => 0,
        'spam'           => 0,
        'trash'          => 0,
        'post-trashed'   => 0,
        'total_comments' => 0,
        'all'            => 0,
    ];
}, 20);

// ─── Admin: remove Comments menu item ────────────────────────────────────
add_action('admin_menu', function () {
    if (hn_comments_disabled()) {
        remove_menu_page('edit-comments.php');
    }
}, 999);

// ─── Admin: remove Comments meta box from all post types ─────────────────
add_action('add_meta_boxes', function () {
    if (! hn_comments_disabled()) {
        return;
    }
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $pt) {
        remove_meta_box('commentsdiv', $pt, 'normal');
        remove_meta_box('commentstatusdiv', $pt, 'normal');
    }
}, 20);

// ─── Author pages module — Disable author archives ──────────────────────

/**
 * Whether author archive pages are disabled.
 */
function hn_author_pages_disabled(): bool {
    return (bool) get_option('hn_disable_author_pages', false);
}

// ─── Settings API — Settings > General ───────────────────────────────────
add_action('admin_init', function () {

    register_setting('general', 'hn_disable_author_pages', [
        'sanitize_callback' => fn($v) => (bool) $v,
    ]);

    add_settings_section('hn_general_section', '', '__return_false', 'general');

    add_settings_field(
        'hn_disable_author_pages',
        'Pages d\'auteurs',
        'hn_render_disable_author_pages_field',
        'general',
        'hn_general_section'
    );
});

/**
 * Render the toggle field for disabling author pages.
 */
function hn_render_disable_author_pages_field(): void {
    $val = hn_author_pages_disabled();
    ?>
    <label class="hn-toggle-wrap" for="hn_disable_author_pages">
        <input type="hidden" name="hn_disable_author_pages" value="0">
        <input type="checkbox" class="hn-toggle-input" name="hn_disable_author_pages" id="hn_disable_author_pages" value="1" <?php checked($val); ?>>
        <span class="hn-toggle-track"></span>
        <span>Désactiver les pages d'auteurs</span>
    </label>
    <p class="description">Redirige les archives auteur vers l'accueil (301).</p>
    <?php
}

// ─── Front-end: redirect author archives to home ────────────────────────
add_action('template_redirect', function () {
    if (hn_author_pages_disabled() && is_author()) {
        wp_redirect(home_url('/'), 301);
        exit;
    }
});

// ─── Config Import/Export — Settings > HN Toolkit ─────────────────────────

/**
 * Sanitize callbacks for all recognised hn_* options.
 * Used both by the Settings API and the import handler.
 *
 * @return array<string, callable>
 */
function hn_config_sanitizers(): array {
    return [
        'hn_img_format'       => fn($v) => in_array($v, ['webp', 'avif'], true) ? $v : 'webp',
        'hn_img_quality'      => fn($v) => max(1, min(100, (int) $v)),
        'hn_img_maxsize'      => fn($v) => max(100, (int) $v),
        'hn_disable_comments'     => fn($v) => (bool) $v,
        'hn_disable_author_pages' => fn($v) => (bool) $v,
        'hn_disable_media_pages'  => fn($v) => (bool) $v,
    ];
}

// ─── Admin menu ───────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    add_options_page(
        'HN Toolkit',
        'HN Toolkit',
        'manage_options',
        'hn-toolkit-config',
        'hn_config_page_render'
    );
});

/**
 * Render the Settings > HN Toolkit page.
 */
function hn_config_page_render(): void {
    // Retrieve messages stored in transient after POST redirect.
    $transient_errors = get_transient('settings_errors');
    if (is_array($transient_errors) && $transient_errors) {
        delete_transient('settings_errors');
        foreach ($transient_errors as $err) {
            add_settings_error($err['setting'], $err['code'], $err['message'], $err['type']);
        }
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <?php settings_errors('hn_toolkit_config'); ?>

        <h2><?php esc_html_e('Exporter la configuration', 'hn-toolkit'); ?></h2>
        <p><?php esc_html_e('Télécharge un fichier JSON contenant tous les réglages du plugin.', 'hn-toolkit'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hn_export_config">
            <?php wp_nonce_field('hn_export_config', '_hn_nonce'); ?>
            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Télécharger la configuration', 'hn-toolkit'); ?>
                </button>
            </p>
        </form>

        <hr>

        <h2><?php esc_html_e('Importer une configuration', 'hn-toolkit'); ?></h2>
        <p><?php esc_html_e('Charge un fichier JSON exporté depuis un autre site pour appliquer les mêmes réglages.', 'hn-toolkit'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="hn_import_config">
            <?php wp_nonce_field('hn_import_config', '_hn_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="hn_config_file"><?php esc_html_e('Fichier de configuration', 'hn-toolkit'); ?></label>
                    </th>
                    <td>
                        <input type="file" name="hn_config_file" id="hn_config_file" accept=".json">
                        <p class="description"><?php esc_html_e('Fichier .json généré par l\'export ci-dessus.', 'hn-toolkit'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-secondary">
                    <?php esc_html_e('Importer', 'hn-toolkit'); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

// ─── Export handler ───────────────────────────────────────────────────────
add_action('admin_post_hn_export_config', function () {

    if (! current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }

    if (! wp_verify_nonce($_POST['_hn_nonce'] ?? '', 'hn_export_config')) {
        wp_die('Nonce invalide.', 403);
    }

    $sanitizers = hn_config_sanitizers();
    $options    = [];

    foreach ($sanitizers as $key => $sanitize) {
        $options[$key] = $sanitize(get_option($key, null));
    }

    $data = [
        'plugin'      => 'hn-wordpress-toolkit',
        'version'     => HN_TOOLKIT_VERSION,
        'exported_at' => gmdate('Y-m-d\TH:i:s+00:00'),
        'options'     => $options,
    ];

    $filename = 'hn-config-' . gmdate('Y-m-d') . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
});

// ─── Import handler ───────────────────────────────────────────────────────
add_action('admin_post_hn_import_config', function () {

    if (! current_user_can('manage_options')) {
        wp_die('Accès refusé.', 403);
    }

    if (! wp_verify_nonce($_POST['_hn_nonce'] ?? '', 'hn_import_config')) {
        wp_die('Nonce invalide.', 403);
    }

    $redirect = admin_url('options-general.php?page=hn-toolkit-config');

    // Validate uploaded file.
    if (empty($_FILES['hn_config_file']['tmp_name']) || $_FILES['hn_config_file']['error'] !== UPLOAD_ERR_OK) {
        add_settings_error('hn_toolkit_config', 'no_file', 'Aucun fichier sélectionné.', 'error');
        set_transient('settings_errors', get_settings_errors('hn_toolkit_config'), 30);
        wp_safe_redirect($redirect);
        exit;
    }

    $raw  = file_get_contents($_FILES['hn_config_file']['tmp_name']);
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        add_settings_error('hn_toolkit_config', 'invalid_json', 'Le fichier n\'est pas un JSON valide.', 'error');
        set_transient('settings_errors', get_settings_errors('hn_toolkit_config'), 30);
        wp_safe_redirect($redirect);
        exit;
    }

    if (($data['plugin'] ?? '') !== 'hn-wordpress-toolkit') {
        add_settings_error('hn_toolkit_config', 'wrong_plugin', 'Ce fichier n\'appartient pas au plugin HN Toolkit.', 'error');
        set_transient('settings_errors', get_settings_errors('hn_toolkit_config'), 30);
        wp_safe_redirect($redirect);
        exit;
    }

    if (! isset($data['options']) || ! is_array($data['options'])) {
        add_settings_error('hn_toolkit_config', 'no_options', 'Le fichier ne contient pas de clé "options" valide.', 'error');
        set_transient('settings_errors', get_settings_errors('hn_toolkit_config'), 30);
        wp_safe_redirect($redirect);
        exit;
    }

    $sanitizers = hn_config_sanitizers();
    $imported   = 0;

    foreach ($data['options'] as $key => $value) {
        if (! isset($sanitizers[$key])) {
            continue; // Option non reconnue — ignorer.
        }
        $clean = $sanitizers[$key]($value);
        update_option($key, $clean);
        $imported++;
    }

    add_settings_error(
        'hn_toolkit_config',
        'import_success',
        sprintf('%d option(s) importée(s) avec succès.', $imported),
        'success'
    );
    set_transient('settings_errors', get_settings_errors('hn_toolkit_config'), 30);
    wp_safe_redirect($redirect);
    exit;
});

// ─── Bulk Regenerate — AJAX handler ───────────────────────────────────────────
//
// Converts existing JPEG/PNG/GIF attachments to the currently configured format.
// Processes images in small batches so the request never times out.
// No offset needed: each successful conversion changes the attachment MIME type
// to WebP/AVIF, so converted images automatically drop out of the query.
// The caller polls until 'done' is true (fewer than batch_size results).
//
// POST params:
//   nonce — wp_create_nonce('hn_bulk_regen')
//
// Response (JSON):
//   { processed: int, done: bool, errors: string[] }

add_action('wp_ajax_hn_bulk_regen', function () {

    check_ajax_referer('hn_bulk_regen', 'nonce');

    if (! current_user_can('manage_options')) {
        wp_send_json_error('Permission refusée.', 403);
    }

    $batch_size = 3;

    // Always query from offset 0: converted images leave the result set
    // automatically because their post_mime_type becomes image/webp or image/avif.
    $ids = get_posts([
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
        'post_status'    => 'inherit',
        'posts_per_page' => $batch_size,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $processed = 0;
    $errors    = [];

    foreach ($ids as $id) {

        $file_path = get_attached_file($id);
        if (! $file_path || ! file_exists($file_path)) {
            $errors[] = "ID $id : fichier introuvable.";
            $processed++;
            continue;
        }

        $ext       = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $real_type = match ($ext) {
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            default       => '',
        };

        if (! $real_type) {
            $processed++;
            continue;
        }

        $format    = hn_img_format();
        $quality   = hn_img_quality();
        $maxsize   = hn_img_maxsize();
        $mime      = 'image/' . $format;
        $dir       = pathinfo($file_path, PATHINFO_DIRNAME);
        $name      = pathinfo($file_path, PATHINFO_FILENAME);
        $new_path  = $dir . '/' . $name . '.' . $format;
        $converted = false;

        if ($format === 'webp') {
            if ($real_type === 'image/png' && function_exists('imagecreatefrompng') && function_exists('imagewebp')) {
                $src = imagecreatefrompng($file_path);
                if ($src) {
                    $src = hn_img_resize_gd($src, $maxsize);
                    imagealphablending($src, false);
                    imagesavealpha($src, true);
                    if (imagewebp($src, $new_path, $quality)) {
                        $converted = true;
                    }
                    imagedestroy($src);
                }
            } else {
                $converted = hn_img_via_editor($file_path, $new_path, $mime, $quality, $maxsize);
            }
        } elseif ($format === 'avif' && class_exists('Imagick')) {
            try {
                $img = new Imagick($file_path);
                if ($img->getImageWidth() > $maxsize || $img->getImageHeight() > $maxsize) {
                    $img->resizeImage($maxsize, $maxsize, Imagick::FILTER_LANCZOS, 1, true);
                }
                $img->setImageFormat('avif');
                $img->setImageCompressionQuality($quality);
                $img->stripImage();
                $img->writeImage($new_path);
                $img->destroy();
                $converted = file_exists($new_path);
            } catch (Exception $e) {
                $errors[] = sprintf('ID %d : %s', $id, $e->getMessage());
            }
        } else {
            // Format AVIF demandé mais Imagick non disponible.
            $errors[] = "ID $id : AVIF impossible (Imagick/libavif non disponible).";
        }

        if ($converted && file_exists($new_path)) {
            // Remove original only if it differs from the output path.
            if ($new_path !== $file_path) {
                @unlink($file_path);
            }
            // Update WP metadata so the media library reflects the new file.
            update_attached_file($id, _wp_relative_upload_path($new_path));
            wp_update_post(['ID' => $id, 'post_mime_type' => $mime]);
            $meta = wp_generate_attachment_metadata($id, $new_path);
            wp_update_attachment_metadata($id, $meta);
        }

        $processed++;
    }

    wp_send_json_success([
        'processed' => $processed,
        'done'      => count($ids) < $batch_size,
        'errors'    => $errors,
    ]);
});

// ─── Bulk Regenerate — Admin UI injected into Settings > Media ────────────────

add_action('admin_footer-options-media.php', function () {

    // Count of attachments that can be converted (original formats only).
    $query = new WP_Query([
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);
    $total = (int) $query->found_posts;

    $nonce   = wp_create_nonce('hn_bulk_regen');
    $ajax    = admin_url('admin-ajax.php');
    $format  = esc_html(strtoupper(hn_img_format()));
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var maxsizeField = document.getElementById('hn_img_maxsize');
        if (! maxsizeField) return;
        var tr = maxsizeField.closest('tr');
        if (! tr) return;

        var total   = <?php echo (int) $total; ?>;
        var nonce   = <?php echo wp_json_encode($nonce); ?>;
        var ajaxUrl = <?php echo wp_json_encode($ajax); ?>;
        var format  = <?php echo wp_json_encode($format); ?>;

        var newTr = document.createElement('tr');
        newTr.innerHTML =
            '<th scope="row">Régénération en masse</th>' +
            '<td id="hn-bulk-regen-cell">' +
                '<p class="description" style="margin-top:0">' +
                    total + ' image(s) JPEG/PNG/GIF dans la bibliothèque — seront converties en ' + format + '.' +
                '</p>' +
                '<button type="button" id="hn-bulk-regen-btn" class="button button-secondary">' +
                    'Lancer la régénération' +
                '</button>' +
                '<span id="hn-bulk-regen-progress" style="margin-left:12px;display:none;vertical-align:middle;">' +
                    '<progress id="hn-bulk-regen-bar" value="0" max="' + total + '" style="width:180px;vertical-align:middle;"></progress>' +
                    '&nbsp;<span id="hn-bulk-regen-count">0&nbsp;/&nbsp;' + total + '</span>' +
                '</span>' +
                '<p id="hn-bulk-regen-done" style="display:none;color:#2e7d32;font-weight:600;margin-top:8px;">✓ Régénération terminée.</p>' +
                '<ul id="hn-bulk-regen-errors" style="color:#b32d2e;margin-top:8px;list-style:disc;padding-left:1.2em;"></ul>' +
            '</td>';
        tr.after(newTr);

        var btn       = document.getElementById('hn-bulk-regen-btn');
        var progress  = document.getElementById('hn-bulk-regen-progress');
        var bar       = document.getElementById('hn-bulk-regen-bar');
        var countEl   = document.getElementById('hn-bulk-regen-count');
        var doneEl    = document.getElementById('hn-bulk-regen-done');
        var errList   = document.getElementById('hn-bulk-regen-errors');
        var processed = 0;

        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            btn.disabled      = true;
            progress.style.display = 'inline';
            doneEl.style.display   = 'none';
            errList.innerHTML      = '';
            processed = 0;
            bar.value = 0;
            runBatch();
        });

        function runBatch() {
            var fd = new FormData();
            fd.append('action', 'hn_bulk_regen');
            fd.append('nonce',  nonce);

            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (! res.success) {
                        errList.innerHTML += '<li>Erreur serveur : ' + (res.data || 'inconnue') + '</li>';
                        btn.disabled = false;
                        return;
                    }
                    var d  = res.data;
                    processed += d.processed;
                    bar.value  = processed;
                    countEl.textContent = processed + '\u00a0/\u00a0' + total;

                    (d.errors || []).forEach(function (e) {
                        errList.innerHTML += '<li>' + e + '</li>';
                    });

                    if (d.done) {
                        doneEl.style.display = 'block';
                        btn.disabled         = false;
                        btn.textContent      = 'Relancer la régénération';
                    } else {
                        runBatch();
                    }
                })
                .catch(function (err) {
                    errList.innerHTML += '<li>Erreur réseau : ' + err + '</li>';
                    btn.disabled = false;
                });
        }
    });
    </script>
    <?php
});

// ─── Posts (blog) module — Global disable ─────────────────────────────────────

/**
 * Whether the blog (posts) functionality is globally disabled.
 */
function hn_posts_disabled(): bool {
    return (bool) get_option('hn_disable_posts', false);
}

// ─── Settings API — Settings > Reading ───────────────────────────────────────
add_action('admin_init', function () {

    register_setting('reading', 'hn_disable_posts', [
        'sanitize_callback' => fn($v) => (bool) $v,
    ]);

    add_settings_section('hn_posts_section', '', '__return_false', 'reading');

    add_settings_field(
        'hn_disable_posts',
        'Articles (blog)',
        'hn_render_disable_posts_field',
        'reading',
        'hn_posts_section'
    );
});

/**
 * Render the toggle field for disabling posts.
 */
function hn_render_disable_posts_field(): void {
    $val = hn_posts_disabled();
    ?>
    <label class="hn-toggle-wrap" for="hn_disable_posts">
        <input type="hidden" name="hn_disable_posts" value="0">
        <input type="checkbox"
               class="hn-toggle-input"
               name="hn_disable_posts"
               id="hn_disable_posts"
               value="1"
               <?php checked($val); ?>>
        <span class="hn-toggle-track"></span>
        <span>Désactiver les articles sur tout le site</span>
    </label>
    <p class="description">
        Quand activé, le menu Articles est masqué, les archives et articles sont
        redirigés vers l'accueil (301), et les réglages natifs ci-dessous sont grisés.
    </p>
    <?php
}

// ─── JS: move section to top + grey out native reading fields ─────────────────
add_action('admin_footer-options-reading.php', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.getElementById('hn_disable_posts');
        if (!toggle) return;

        var table = toggle.closest('table.form-table');
        var form  = toggle.closest('form');
        if (table && form) {
            var firstTable = form.querySelector('table.form-table');
            if (firstTable && firstTable !== table) {
                form.insertBefore(table, firstTable);
            }
        }

        function setNativeFields(isDisabled) {
            if (!form) return;
            form.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.name === 'hn_disable_posts' || el.type === 'hidden' || el.type === 'submit') return;
                el.disabled    = isDisabled;
                el.style.opacity = isDisabled ? '0.4' : '';
            });
        }

        setNativeFields(toggle.checked);
        toggle.addEventListener('change', function () { setNativeFields(this.checked); });
    });
    </script>
    <?php
});

add_action('admin_head-options-reading.php', 'hn_render_toggle_css');

// ─── Admin: remove Posts menu item ────────────────────────────────────────────
add_action('admin_menu', function () {
    if (hn_posts_disabled()) {
        remove_menu_page('edit.php'); // Posts
    }
}, 999);

// ─── Admin: remove "New Post" from the admin bar ──────────────────────────────
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    if (hn_posts_disabled()) {
        $bar->remove_node('new-post');
    }
}, 999);

// ─── Front-end: redirect blog archive and single posts ───────────────────────
add_action('template_redirect', function () {
    if (! hn_posts_disabled()) {
        return;
    }
    if (is_home() || is_singular('post') || is_category() || is_tag() || is_date() || is_author()) {
        wp_redirect(home_url('/'), 301);
        exit;
    }
});
