<?php
/**
 * Page Réglages > HN Toolkit : tableau de bord des modules (cartes avec
 * interrupteur) et onglets de réglages des modules actifs.
 *
 * Les modules ajoutent leur onglet via le filtre `hn_toolkit_tabs` :
 *   $tabs['slug'] = ['label' => 'Libellé', 'render' => callable];
 *
 * L'interrupteur d'un module est un bouton de formulaire envoyé à
 * admin-post.php, puis la page est rechargée : les onglets et menus qui
 * dépendent des modules actifs sont ainsi à jour.
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_options_page('HN Toolkit', 'HN Toolkit', 'manage_options', 'hn-toolkit-config', 'hn_toolkit_page_render');
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'settings_page_hn-toolkit-config') {
        return;
    }
    $url = plugin_dir_url(HN_TOOLKIT_FILE) . 'assets/';
    wp_enqueue_style('hn-toolkit-dashboard', $url . 'dashboard.css', [], HN_TOOLKIT_VERSION);
});

/**
 * Tabs of the page: dashboard first, then the tabs of active modules.
 *
 * @return array<string, array{label: string, render: callable}>
 */
function hn_toolkit_tabs(): array {
    $tabs = apply_filters('hn_toolkit_tabs', []);
    return array_merge(['dashboard' => ['label' => 'Tableau de bord', 'render' => 'hn_toolkit_dashboard_render']], $tabs);
}

/**
 * Render the Settings > HN Toolkit page.
 */
function hn_toolkit_page_render(): void {
    if (! current_user_can('manage_options')) {
        return;
    }

    // Messages stored in a transient before a POST redirect (import, fallback toggle).
    $transient_errors = get_transient('settings_errors');
    if (is_array($transient_errors) && $transient_errors) {
        delete_transient('settings_errors');
        foreach ($transient_errors as $err) {
            add_settings_error($err['setting'], $err['code'], $err['message'], $err['type']);
        }
    }

    $tabs    = hn_toolkit_tabs();
    $current = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
    if (! isset($tabs[$current])) {
        $current = 'dashboard';
    }
    ?>
    <div class="wrap hn-toolkit">
        <h1>HN Toolkit <span class="hn-toolkit-version">v<?php echo esc_html(HN_TOOLKIT_VERSION); ?></span></h1>

        <nav class="nav-tab-wrapper" aria-label="Sections de HN Toolkit">
            <?php foreach ($tabs as $slug => $tab): ?>
                <a href="<?php echo esc_url(hn_toolkit_page_url($slug === 'dashboard' ? '' : $slug)); ?>"
                   class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
                   <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($tab['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php settings_errors('hn_toolkit_config'); ?>

        <?php call_user_func($tabs[$current]['render']); ?>
    </div>
    <?php
}

/**
 * Dashboard tab: one card per module.
 */
function hn_toolkit_dashboard_render(): void {
    ?>
    <p class="hn-toolkit-intro">Activez uniquement les fonctionnalités utiles à ce site. Un module désactivé ne charge aucun code.</p>
    <div class="hn-modules">
        <?php foreach (hn_toolkit_modules() as $slug => $module):
            $active = hn_module_active($slug);
            ?>
            <div class="hn-module<?php echo $active ? ' is-active' : ''; ?>" id="hn-module-<?php echo esc_attr($slug); ?>">
                <div class="hn-module-head">
                    <span class="hn-module-icon dashicons <?php echo esc_attr($module['icon']); ?>" aria-hidden="true"></span>
                    <h2 class="hn-module-name"><?php echo esc_html($module['name']); ?></h2>
                </div>
                <p class="hn-module-desc"><?php echo esc_html($module['description']); ?></p>
                <div class="hn-module-foot">
                    <?php if ($module['settings']): ?>
                        <a class="hn-module-settings" href="<?php echo esc_url($module['settings']); ?>" <?php echo $active ? '' : 'hidden'; ?>>
                            <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> Réglages
                        </a>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hn-module-form">
                        <input type="hidden" name="action" value="hn_toolkit_module">
                        <input type="hidden" name="module" value="<?php echo esc_attr($slug); ?>">
                        <input type="hidden" name="active" value="<?php echo $active ? '0' : '1'; ?>">
                        <?php wp_nonce_field('hn_toolkit_module', '_hn_nonce', false); ?>
                        <button type="submit" class="hn-switch" role="switch"
                                aria-checked="<?php echo $active ? 'true' : 'false'; ?>"
                                aria-label="<?php echo esc_attr($module['name']); ?>">
                            <span class="hn-switch-track" aria-hidden="true"></span>
                            <span class="hn-switch-label"><?php echo $active ? 'Actif' : 'Inactif'; ?></span>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Validate and apply a module toggle request.
 *
 * @return array{slug: string, active: bool}|WP_Error
 */
function hn_toolkit_handle_toggle() {
    if (! current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'Accès refusé.');
    }
    if (! wp_verify_nonce(isset($_POST['_hn_nonce']) ? sanitize_text_field(wp_unslash($_POST['_hn_nonce'])) : '', 'hn_toolkit_module')) {
        return new WP_Error('nonce', 'Nonce invalide, rechargez la page.');
    }
    $slug    = isset($_POST['module']) ? sanitize_key(wp_unslash($_POST['module'])) : '';
    $modules = hn_toolkit_modules();
    if (! isset($modules[$slug])) {
        return new WP_Error('module', 'Module inconnu.');
    }
    $active = ! empty($_POST['active']);
    update_option($modules[$slug]['option'], $active);
    return ['slug' => $slug, 'active' => $active];
}

// Classic form post, then back to the dashboard: the full reload refreshes the tabs and
// menus that depend on the active modules (an AJAX toggle left them stale).
add_action('admin_post_hn_toolkit_module', function () {
    $result = hn_toolkit_handle_toggle();
    if (is_wp_error($result)) {
        wp_die(esc_html($result->get_error_message()), 403);
    }
    $modules = hn_toolkit_modules();
    add_settings_error(
        'hn_toolkit_config',
        'module_saved',
        sprintf('Module « %s » %s.', $modules[$result['slug']]['name'], $result['active'] ? 'activé' : 'désactivé'),
        'success'
    );
    set_transient('settings_errors', get_settings_errors('hn_toolkit_config'), 30);
    wp_safe_redirect(hn_toolkit_page_url() . '#hn-module-' . $result['slug']);
    exit;
});
