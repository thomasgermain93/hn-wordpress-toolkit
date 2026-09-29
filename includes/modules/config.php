<?php
/**
 * Module « Import / export de la configuration ».
 *
 * Chargé par hn-wordpress-toolkit.php uniquement quand le module est actif
 * (Réglages > HN Toolkit > Tableau de bord).
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

add_filter('hn_toolkit_tabs', function (array $tabs): array {
    $tabs['config'] = ['label' => 'Import / export', 'render' => 'hn_config_tab_render'];
    return $tabs;
});

/**
 * Onglet « Import / export » de Réglages > HN Toolkit.
 */
function hn_config_tab_render(): void {
    ?>
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

    $options = hn_config_current_values();

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

    $redirect = hn_toolkit_page_url('config');

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
