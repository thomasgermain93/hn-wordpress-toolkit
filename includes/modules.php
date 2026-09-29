<?php
/**
 * Registre des modules du Toolkit.
 *
 * Chaque module est une option booléenne. Les modules qui existaient avant la
 * 1.4.0 gardent leur option d'origine et leur valeur par défaut d'origine, si
 * bien qu'une mise à jour depuis la 1.3.1 ne change le comportement d'aucun
 * site : la conversion d'images et l'import/export restent actifs, les
 * « désactivations » restent dans l'état où elles étaient, le nouveau module
 * « Ordre manuel » est inactif.
 *
 * Un module inactif ne charge pas son fichier : aucun de ses hooks n'existe.
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

/**
 * Définition des modules, dans l'ordre d'affichage du tableau de bord.
 *
 * - option   : option WordPress qui stocke l'état (bool)
 * - default  : état quand l'option n'existe pas encore
 * - file     : fichier dans includes/modules/
 * - settings : URL admin des réglages du module ('' si aucun)
 *
 * @return array<string, array{name: string, description: string, icon: string, option: string, default: bool, file: string, settings: string}>
 */
function hn_toolkit_modules(): array {
    return [
        'image_optimizer' => [
            'name'        => 'Conversion WebP / AVIF',
            'description' => 'Convertit les images JPEG, PNG et GIF en WebP ou AVIF à l\'envoi, les redimensionne et ne génère que la miniature.',
            'icon'        => 'dashicons-format-image',
            'option'      => 'hn_module_image_optimizer',
            'default'     => true,
            'file'        => 'image-optimizer.php',
            'settings'    => admin_url('options-media.php#hn_img_format'),
        ],
        'comments' => [
            'name'        => 'Désactiver les commentaires',
            'description' => 'Ferme les commentaires et les pings sur tout le site et masque le menu Commentaires.',
            'icon'        => 'dashicons-admin-comments',
            'option'      => 'hn_disable_comments',
            'default'     => false,
            'file'        => 'comments.php',
            'settings'    => '',
        ],
        'posts' => [
            'name'        => 'Désactiver les articles (blog)',
            'description' => 'Masque le menu Articles et redirige les articles et leurs archives vers l\'accueil (301).',
            'icon'        => 'dashicons-admin-post',
            'option'      => 'hn_disable_posts',
            'default'     => false,
            'file'        => 'posts.php',
            'settings'    => '',
        ],
        'author_pages' => [
            'name'        => 'Désactiver les pages d\'auteurs',
            'description' => 'Redirige les archives auteur vers l\'accueil (301).',
            'icon'        => 'dashicons-admin-users',
            'option'      => 'hn_disable_author_pages',
            'default'     => false,
            'file'        => 'author-pages.php',
            'settings'    => '',
        ],
        'media_pages' => [
            'name'        => 'Désactiver les pages de médias',
            'description' => 'Redirige les pages de fichiers joints vers l\'URL directe du fichier (301).',
            'icon'        => 'dashicons-admin-media',
            'option'      => 'hn_disable_media_pages',
            'default'     => false,
            'file'        => 'media-pages.php',
            'settings'    => '',
        ],
        'config' => [
            'name'        => 'Import / export de la configuration',
            'description' => 'Exporte les réglages du Toolkit dans un fichier JSON et les importe sur un autre site.',
            'icon'        => 'dashicons-migrate',
            'option'      => 'hn_module_config',
            'default'     => true,
            'file'        => 'config.php',
            'settings'    => hn_toolkit_page_url('config'),
        ],
    ];
}

/**
 * Whether a module is active.
 */
function hn_module_active(string $slug): bool {
    $modules = hn_toolkit_modules();
    if (! isset($modules[$slug])) {
        return false;
    }
    return (bool) get_option($modules[$slug]['option'], $modules[$slug]['default']);
}

/**
 * URL of the Settings > HN Toolkit page, optionally on a given tab.
 */
function hn_toolkit_page_url(string $tab = ''): string {
    $url = admin_url('options-general.php?page=hn-toolkit-config');
    return $tab ? add_query_arg('tab', $tab, $url) : $url;
}

/**
 * Admin notice shown on a native settings screen that a module overrides.
 */
function hn_toolkit_module_notice(string $message): void {
    printf(
        '<div class="notice notice-info"><p>%s <a href="%s">Gérer les modules HN Toolkit</a></p></div>',
        esc_html($message),
        esc_url(hn_toolkit_page_url())
    );
}

// Kept for backward compatibility (public helpers of the 1.3.x series).
function hn_comments_disabled(): bool     { return hn_module_active('comments'); }
function hn_posts_disabled(): bool        { return hn_module_active('posts'); }
function hn_author_pages_disabled(): bool { return hn_module_active('author_pages'); }
function hn_media_pages_disabled(): bool  { return hn_module_active('media_pages'); }

// ─── Configuration (import / export) ──────────────────────────────────────

/**
 * Sanitize callbacks for all recognised options, with their default values.
 * Used by the import/export module.
 *
 * @return array<string, array{0: mixed, 1: callable}> option => [default, sanitize]
 */
function hn_config_options(): array {
    $bool    = function ($v) { return (bool) $v; };
    $options = [
        'hn_img_format'   => ['webp', function ($v) { return in_array($v, ['webp', 'avif'], true) ? $v : 'webp'; }],
        'hn_img_quality'  => [90,     function ($v) { return max(1, min(100, (int) $v)); }],
        'hn_img_maxsize'  => [2000,   function ($v) { return max(100, (int) $v); }],
    ];
    foreach (hn_toolkit_modules() as $module) {
        $options[$module['option']] = [$module['default'], $bool];
    }
    return $options;
}

/**
 * Sanitize callbacks for all recognised hn_* options.
 *
 * @return array<string, callable>
 */
function hn_config_sanitizers(): array {
    return array_map(function ($o) { return $o[1]; }, hn_config_options());
}

/**
 * Current value of every recognised option (defaults applied), for export.
 *
 * @return array<string, mixed>
 */
function hn_config_current_values(): array {
    $values = [];
    foreach (hn_config_options() as $key => $o) {
        $values[$key] = call_user_func($o[1], get_option($key, $o[0]));
    }
    return $values;
}
