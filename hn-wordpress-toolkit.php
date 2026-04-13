<?php
/**
 * Plugin Name:  Hungry Nuggets WordPress Toolkit
 * Plugin URI:   https://github.com/thomasgermain93/hn-wordpress-toolkit
 * Description:  Hungry Nuggets internal WordPress toolkit. Modules: image optimization (WebP/AVIF), global comments disabler, config import/export. GitHub-based auto-update.
 * Version:      1.1.2
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
 *  - hn_img_format       (string)  'webp' | 'avif'   default: 'webp'
 *  - hn_img_quality      (int)     1–100              default: 90
 *  - hn_img_maxsize      (int)     pixels             default: 2000
 *  - hn_disable_comments (bool)    true | false       default: false
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
 * -----------------------------------------------------------------------------
 */

defined('ABSPATH') || exit;

define('HN_TOOLKIT_VERSION', '1.1.2');
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
});

// ─── Quality sync ──────────────────────────────────────────────────────────
add_filter('jpeg_quality',          fn() => hn_img_quality());
add_filter('wp_editor_set_quality', fn() => hn_img_quality());

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
add_action('admin_head-options-discussion.php', function () {
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
});

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
        'hn_disable_comments' => fn($v) => (bool) $v,
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
    ?>
    <div class="wrap">
        <h1>HN Toolkit — Configuration</h1>

        <?php
        // Retrieve settings errors stored in transient after redirect.
        $transient_errors = get_transient('settings_errors');
        if (is_array($transient_errors) && $transient_errors) {
            delete_transient('settings_errors');
            foreach ($transient_errors as $err) {
                add_settings_error($err['setting'], $err['code'], $err['message'], $err['type']);
            }
        }
        settings_errors('hn_toolkit_config');
        ?>

        <div class="card">
            <h2>Exporter la configuration</h2>
            <p>Télécharge un fichier JSON contenant tous les réglages du plugin.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hn_export_config">
                <?php wp_nonce_field('hn_export_config', '_hn_nonce'); ?>
                <?php submit_button('Télécharger la configuration', 'primary', 'submit', false); ?>
            </form>
        </div>

        <div class="card" style="margin-top:20px;">
            <h2>Importer une configuration</h2>
            <p>Charge un fichier JSON exporté depuis un autre site pour appliquer les mêmes réglages.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="hn_import_config">
                <?php wp_nonce_field('hn_import_config', '_hn_nonce'); ?>
                <p>
                    <input type="file" name="hn_config_file" accept=".json">
                </p>
                <?php submit_button('Importer', 'secondary', 'submit', false); ?>
            </form>
        </div>
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
