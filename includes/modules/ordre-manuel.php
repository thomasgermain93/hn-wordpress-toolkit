<?php
/**
 * Module « Ordre manuel ».
 *
 * Pour les types de contenu choisis dans Réglages > HN Toolkit > Ordre manuel :
 *  - la liste d'admin est triée par ordre manuel (menu_order ASC, puis date) ;
 *  - sur la vue complète (sans recherche, filtre ni tri de colonne), les lignes
 *    se déplacent par glisser-déposer et toute la liste est renumérotée 1..N.
 *    Sur une vue partielle, le glisser est désactivé : renuméroter une
 *    sélection mélangerait ses numéros à ceux des contenus cachés ;
 *  - option « Appliquer au site » : les requêtes publiques de ce type sans
 *    orderby explicite sont triées par ordre manuel.
 *
 * Tri appliqué (admin et site) : filtre `hn_toolkit_ordre_tri`, par défaut
 * ['menu_order' => 'ASC', 'date' => 'DESC']. Le tri secondaire départage les
 * contenus sans numéro : il doit correspondre à l'ordre actuel du site, sinon
 * le premier glisser-déposer renumérote la liste dans un ordre différent.
 *
 * Après chaque enregistrement :
 *   do_action('hn_toolkit_ordre_enregistre', int[] $ids_dans_l_ordre, string $post_type);
 * pour qu'un site puisse synchroniser son propre champ d'ordre.
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

/** Nombre maximal de lignes affichées sur une page quand le glisser est actif. */
const HN_ORDRE_PAR_PAGE = 1000;

/**
 * @return array{types: string[], front: string[]}
 */
function hn_ordre_config(): array {
    return hn_ordre_manuel_sanitize(get_option('hn_ordre_manuel', []));
}

/**
 * Types that can be sorted: public, with an admin UI, not hierarchical
 * (a hierarchical list is shown as a tree, dragging across levels would be
 * misleading).
 *
 * @return WP_Post_Type[]
 */
function hn_ordre_types_disponibles(): array {
    $types = get_post_types(['public' => true, 'show_ui' => true, 'hierarchical' => false], 'objects');
    unset($types['attachment']);
    return $types;
}

/**
 * Sort order used for a post type, in the admin list and on the site.
 *
 * @return array<string, string> WP_Query orderby array.
 */
function hn_ordre_tri(string $type): array {
    return (array) apply_filters('hn_toolkit_ordre_tri', ['menu_order' => 'ASC', 'date' => 'DESC'], $type);
}

// ─── Onglet de réglages ────────────────────────────────────────────────────

add_action('admin_init', function () {
    register_setting('hn_toolkit_ordre', 'hn_ordre_manuel', ['sanitize_callback' => 'hn_ordre_manuel_sanitize']);
});

add_filter('hn_toolkit_tabs', function (array $tabs): array {
    $tabs['ordre'] = ['label' => 'Ordre manuel', 'render' => 'hn_ordre_render_settings'];
    return $tabs;
});

/**
 * Settings tab: one row per public post type.
 */
function hn_ordre_render_settings(): void {
    $config = hn_ordre_config();
    ?>
    <p>Pour chaque type de contenu coché, la liste d'admin se réordonne par glisser-déposer (poignée <span class="dashicons dashicons-menu" aria-hidden="true"></span> de la colonne « Ordre »).</p>
    <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
        <?php settings_fields('hn_toolkit_ordre'); ?>
        <table class="widefat striped" style="max-width:720px">
            <thead>
                <tr>
                    <th scope="col">Type de contenu</th>
                    <th scope="col">Glisser-déposer en admin</th>
                    <th scope="col">Appliquer l'ordre au site</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (hn_ordre_types_disponibles() as $slug => $type): ?>
                <tr>
                    <th scope="row">
                        <?php echo esc_html($type->labels->name); ?>
                        <code><?php echo esc_html($slug); ?></code>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="hn_ordre_manuel[types][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $config['types'], true)); ?>>
                            Activer
                        </label>
                    </td>
                    <td>
                        <label>
                            <input type="checkbox" name="hn_ordre_manuel[front][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $config['front'], true)); ?>>
                            Trier les listes du site
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="max-width:720px">
            « Appliquer l'ordre au site » trie par ordre manuel les requêtes publiques de ce type qui ne demandent
            pas de tri précis (archives, boucles sans tri choisi). Laisser décoché si le thème ou un code du site
            gère déjà l'ordre. Sans effet si le glisser-déposer n'est pas activé pour ce type.
        </p>
        <?php submit_button(); ?>
    </form>
    <?php
}

// ─── Liste d'admin ─────────────────────────────────────────────────────────

/**
 * Whether the current list shows every item: no search, filter, column sort
 * or status other than « Tous ». Pagination and the post-action messages
 * (trashed=1, ids=…) do not count.
 */
function hn_ordre_vue_complete(): bool {
    $ignorees = ['post_type', 'mode', 'paged', 'filter_action', 'action', 'action2',
                 'ids', 'trashed', 'untrashed', 'deleted', 'locked', 'skipped', 'updated', 'message'];
    foreach ($_GET as $cle => $valeur) {
        if (in_array($cle, $ignorees, true)) {
            continue;
        }
        if (is_string($valeur) && in_array($valeur, ['', '0', '-1', 'all'], true)) {
            continue; // Empty filter left by the « Filtrer » button.
        }
        return false;
    }
    return true;
}

add_action('load-edit.php', function () {
    $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
    if (! in_array($type, hn_ordre_config()['types'], true)) {
        return;
    }
    $triable = hn_ordre_vue_complete();

    // Default order of the list: the manual order.
    add_action('pre_get_posts', function (WP_Query $q) use ($type) {
        if ($q->is_main_query() && ! isset($_GET['orderby'])) {
            $q->set('orderby', hn_ordre_tri($type));
        }
    });

    // « Ordre » column, right after the checkbox.
    add_filter("manage_{$type}_posts_columns", function (array $cols): array {
        $nouvelles = [];
        foreach ($cols as $cle => $libelle) {
            $nouvelles[$cle] = $libelle;
            if ($cle === 'cb') {
                $nouvelles['hn_ordre'] = 'Ordre';
            }
        }
        if (! isset($nouvelles['hn_ordre'])) {
            $nouvelles = ['hn_ordre' => 'Ordre'] + $nouvelles;
        }
        return $nouvelles;
    });
    add_action("manage_{$type}_posts_custom_column", function ($col, $post_id) use ($triable) {
        if ($col !== 'hn_ordre') {
            return;
        }
        $ordre = (int) get_post_field('menu_order', $post_id);
        if ($triable) {
            echo '<span class="hn-ordre-poignee dashicons dashicons-menu" title="Glisser pour déplacer" aria-hidden="true"></span>';
        }
        echo '<span class="hn-ordre-num">' . ($ordre ? (int) $ordre : '–') . '</span>';
    }, 10, 2);

    add_action('admin_enqueue_scripts', function () {
        wp_enqueue_style('hn-toolkit-ordre', plugin_dir_url(HN_TOOLKIT_FILE) . 'assets/ordre-manuel.css', [], HN_TOOLKIT_VERSION);
    });

    if (! $triable) {
        add_action('admin_notices', function () use ($type) {
            printf(
                '<div class="notice notice-info"><p>Le glisser-déposer est désactivé tant qu\'une recherche, un filtre, un statut ou un tri est appliqué : réordonner une partie de la liste mélangerait ses numéros avec ceux des éléments masqués. <a href="%s">Afficher toute la liste</a> pour réordonner.</p></div>',
                esc_url(admin_url('edit.php?post_type=' . $type))
            );
        });
        return;
    }

    // The whole list on one page: a row cannot be dragged to another page.
    add_filter('edit_posts_per_page', function ($par_page, $post_type) use ($type) {
        return $post_type === $type ? HN_ORDRE_PAR_PAGE : $par_page;
    }, 10, 2);

    add_action('admin_enqueue_scripts', function () use ($type) {
        wp_enqueue_script('hn-toolkit-ordre', plugin_dir_url(HN_TOOLKIT_FILE) . 'assets/ordre-manuel.js', ['jquery', 'jquery-ui-sortable'], HN_TOOLKIT_VERSION, true);
        wp_localize_script('hn-toolkit-ordre', 'hnOrdre', [
            'ajax'   => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce('hn_ordre'),
            'type'   => $type,
            'erreur' => 'L\'ordre n\'a pas pu être enregistré. Rechargez la page et réessayez.',
        ]);
    });
});

// ─── Enregistrement (AJAX) ─────────────────────────────────────────────────

add_action('wp_ajax_hn_ordre_enregistrer', function () {
    check_ajax_referer('hn_ordre', 'nonce');

    $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
    $ids  = isset($_POST['ids']) ? array_values(array_unique(array_filter(array_map('absint', (array) wp_unslash($_POST['ids']))))) : [];

    if (! in_array($type, hn_ordre_config()['types'], true) || ! $ids) {
        wp_send_json_error('Requête invalide.', 400);
    }
    foreach ($ids as $id) {
        if (get_post_type($id) !== $type || ! current_user_can('edit_post', $id)) {
            wp_send_json_error('Vous n\'avez pas le droit de modifier l\'ordre de tous ces contenus.', 403);
        }
    }

    global $wpdb;
    foreach ($ids as $i => $id) {
        // Direct update: wp_update_post() would fire save_post for every row.
        $wpdb->update($wpdb->posts, ['menu_order' => $i + 1], ['ID' => $id], ['%d'], ['%d']);
        clean_post_cache($id);
    }

    /**
     * Fires after the manual order of a post type is saved.
     *
     * @param int[]  $ids       Post IDs, in their new order (position 1 first).
     * @param string $post_type Post type.
     */
    do_action('hn_toolkit_ordre_enregistre', $ids, $type);

    do_action('litespeed_purge_all');

    wp_send_json_success(['ids' => $ids]);
});

// ─── Front : appliquer l'ordre au site ─────────────────────────────────────

add_action('pre_get_posts', function (WP_Query $q) {
    if (is_admin()) {
        return;
    }
    $front = hn_ordre_config()['front'];
    if (! $front || $q->get('orderby')) {
        return;
    }
    $types = array_filter((array) $q->get('post_type'));
    if (! $types && $q->is_post_type_archive()) {
        $types = [get_query_var('post_type')];
    } elseif (! $types && ($q->is_home() || $q->is_category() || $q->is_tag())) {
        $types = ['post'];
    }
    if (count($types) === 1 && in_array(reset($types), $front, true)) {
        $q->set('orderby', hn_ordre_tri(reset($types)));
    }
});
