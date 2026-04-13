<?php
/**
 * Plugin Name:  Hungry Nuggets WordPress Toolkit
 * Plugin URI:   https://github.com/thomasgermain93/hn-wordpress-toolkit
 * Description:  Hungry Nuggets internal WordPress toolkit. Modules: image optimization (WebP/AVIF), configurable via Settings > Media. GitHub-based auto-update.
 * Version:      1.0.3
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
 *  - Automatic updates are provided via HN_Toolkit_Updater, which
 *    checks the GitHub Releases API every 12 hours.
 *
 * WordPress options:
 *  - hn_img_format  (string)  'webp' | 'avif'   default: 'webp'
 *  - hn_img_quality (int)     1–100              default: 90
 *  - hn_img_maxsize (int)     pixels             default: 2000
 *
 * Filters used:
 *  - wp_handle_upload              priority 5  — conversion entry point
 *  - intermediate_image_sizes_advanced         — limits to thumbnail only
 *  - jpeg_quality                              — syncs WP editor quality
 *  - wp_editor_set_quality                     — syncs WP editor quality
 *
 * Admin hooks:
 *  - admin_init    — registers settings in the 'media' option group
 *
 * Update flow:
 *  - pre_set_site_transient_update_plugins — injects GitHub release if newer
 *  - plugins_api                           — provides plugin info modal data
 * -----------------------------------------------------------------------------
 */

defined('ABSPATH') || exit;

define('HN_TOOLKIT_VERSION', '1.0.3');
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
           . 'value="' . esc_attr($val) . '" min="100" step="100" class="small-text"> px';
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
