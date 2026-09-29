<?php
/**
 * Module « Conversion WebP / AVIF ».
 *
 * Chargé par hn-wordpress-toolkit.php uniquement quand le module est actif
 * (Réglages > HN Toolkit > Tableau de bord).
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

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
        'sanitize_callback' => function ($v) { return in_array($v, ['webp', 'avif'], true) ? $v : 'webp'; },
    ]);
    register_setting('media', 'hn_img_quality', [
        'sanitize_callback' => function ($v) { return max(1, min(100, (int) $v)); },
    ]);
    register_setting('media', 'hn_img_maxsize', [
        'sanitize_callback' => function ($v) { return max(100, (int) $v); },
    ]);

    add_settings_section('hn_img_section', 'Optimisation des images', '__return_false', 'media');

    add_settings_field('hn_img_format', 'Format de conversion', function () {
        $val     = hn_img_format();
        $avif_ok = hn_avif_supported();
        ?>
        <select name="hn_img_format" id="hn_img_format">
            <option value="webp" <?php selected($val, 'webp'); ?>>WebP</option>
            <option value="avif" <?php selected($val, 'avif'); ?> <?php disabled(! $avif_ok); ?>>
                AVIF<?php echo $avif_ok ? '' : ' (libavif non disponible)'; ?>
            </option>
        </select>
        <p class="description">
            WebP fonctionne via GD (toujours disponible). AVIF nécessite GD ou Imagick compilé avec libavif.
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
add_filter('jpeg_quality',          function () { return hn_img_quality(); });
add_filter('wp_editor_set_quality', function () { return hn_img_quality(); });

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
                // Palette (indexed-color) PNGs must be converted to truecolor first —
                // imagewebp() silently writes a 0-byte file on palette images.
                if (! imageistruecolor($src)) {
                    imagepalettetotruecolor($src);
                }
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

    } elseif ($format === 'avif' && hn_avif_supported()) {
        $converted = hn_convert_to_avif($file_path, $new_path, $real_type, $quality, $maxsize);
    }

    if ($converted && file_exists($new_path) && filesize($new_path) > 0) {
        @unlink($file_path);
        $upload['file'] = $new_path;
        $upload['url']  = str_replace(basename($upload['url']), basename($new_path), $upload['url']);
        $upload['type'] = $mime;
    } elseif (file_exists($new_path) && $new_path !== $file_path) {
        // Conversion produced a 0-byte output file — remove it and keep the original.
        @unlink($new_path);
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
 * Whether AVIF encoding is available, via GD or Imagick.
 */
function hn_avif_supported(): bool {
    if (function_exists('imageavif')) {
        $gd = function_exists('gd_info') ? gd_info() : [];
        if (! empty($gd['AVIF Support'])) {
            return true;
        }
    }
    if (class_exists('Imagick') && in_array('AVIF', Imagick::queryFormats(), true)) {
        return true;
    }
    return false;
}

/**
 * Convert an image to AVIF. Tries GD first (lighter, faster on most setups);
 * falls back to Imagick when GD AVIF support is missing.
 *
 * @param  string $source     Absolute path to source file.
 * @param  string $dest       Absolute path for output file.
 * @param  string $real_type  Source MIME type (image/jpeg, image/png, image/gif).
 * @param  int    $quality    Compression quality (1–100).
 * @param  int    $maxsize    Max width/height in pixels.
 * @return bool               True if conversion produced a non-empty file.
 */
function hn_convert_to_avif(string $source, string $dest, string $real_type, int $quality, int $maxsize): bool {

    // ── GD path ─────────────────────────────────────────────────────────
    if (function_exists('imageavif')) {
        $gd = function_exists('gd_info') ? gd_info() : [];
        if (! empty($gd['AVIF Support'])) {
            $src = null;
            switch ($real_type) {
                case 'image/jpeg': $src = @imagecreatefromjpeg($source); break;
                case 'image/png':  $src = @imagecreatefrompng($source);  break;
                case 'image/gif':  $src = @imagecreatefromgif($source);  break;
            }
            if ($src) {
                if (! imageistruecolor($src)) {
                    imagepalettetotruecolor($src);
                }
                $src = hn_img_resize_gd($src, $maxsize);
                imagealphablending($src, false);
                imagesavealpha($src, true);
                $ok = @imageavif($src, $dest, $quality);
                imagedestroy($src);
                if ($ok && file_exists($dest) && filesize($dest) > 0) {
                    return true;
                }
                if (file_exists($dest)) {
                    @unlink($dest);
                }
            }
        }
    }

    // ── Imagick fallback ────────────────────────────────────────────────
    if (class_exists('Imagick') && in_array('AVIF', Imagick::queryFormats(), true)) {
        try {
            $img = new Imagick($source);
            if ($img->getImageWidth() > $maxsize || $img->getImageHeight() > $maxsize) {
                $img->resizeImage($maxsize, $maxsize, Imagick::FILTER_LANCZOS, 1, true);
            }
            $img->setImageFormat('avif');
            $img->setImageCompressionQuality($quality);
            $img->stripImage();
            $img->writeImage($dest);
            $img->destroy();
            return file_exists($dest) && filesize($dest) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    return false;
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
        switch ($ext) {
            case 'png':
                $real_type = 'image/png';
                break;
            case 'jpg':
            case 'jpeg':
                $real_type = 'image/jpeg';
                break;
            case 'gif':
                $real_type = 'image/gif';
                break;
            default:
                $real_type = '';
        }

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
                    // Palette (indexed-color) PNGs must be converted to truecolor first.
                    if (! imageistruecolor($src)) {
                        imagepalettetotruecolor($src);
                    }
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
        } elseif ($format === 'avif' && hn_avif_supported()) {
            $converted = hn_convert_to_avif($file_path, $new_path, $real_type, $quality, $maxsize);
            if (! $converted) {
                $errors[] = "ID $id : conversion AVIF échouée.";
            }
        } else {
            $errors[] = "ID $id : AVIF impossible (libavif non disponible).";
        }

        if ($converted && file_exists($new_path) && filesize($new_path) > 0) {
            // Remove original only if it differs from the output path.
            if ($new_path !== $file_path) {
                @unlink($file_path);
            }
            // Update WP metadata so the media library reflects the new file.
            update_attached_file($id, _wp_relative_upload_path($new_path));
            wp_update_post(['ID' => $id, 'post_mime_type' => $mime]);
            $meta = wp_generate_attachment_metadata($id, $new_path);
            wp_update_attachment_metadata($id, $meta);
        } elseif (file_exists($new_path) && $new_path !== $file_path) {
            // Conversion produced a 0-byte output file — remove it and keep the original.
            @unlink($new_path);
            $errors[] = "ID $id : conversion échouée (fichier vide) — original conservé.";
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
