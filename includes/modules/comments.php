<?php
/**
 * Module « Désactiver les commentaires ».
 *
 * Chargé uniquement quand le module est actif (option hn_disable_comments).
 * Ferme commentaires et pings partout, masque le menu Commentaires et la boîte
 * « Discussion » des contenus, et grise les réglages natifs de
 * Réglages > Discussion.
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

// ─── Front-end & API: close comments and pings ───────────────────────────
add_filter('comments_open', '__return_false', 20);
add_filter('pings_open', '__return_false', 20);

// ─── Admin: zero out comment counts ──────────────────────────────────────
add_filter('wp_count_comments', function () {
    return (object) [
        'approved'            => 0,
        'moderated'           => 0,
        'awaiting_moderation' => 0,
        'spam'                => 0,
        'trash'               => 0,
        'post-trashed'        => 0,
        'total_comments'      => 0,
        'all'                 => 0,
    ];
}, 20);

// ─── Admin: remove Comments menu item ────────────────────────────────────
add_action('admin_menu', function () {
    remove_menu_page('edit-comments.php');
}, 999);

// ─── Admin: remove Comments meta boxes from all post types ───────────────
add_action('add_meta_boxes', function () {
    foreach (get_post_types(['public' => true]) as $pt) {
        remove_meta_box('commentsdiv', $pt, 'normal');
        remove_meta_box('commentstatusdiv', $pt, 'normal');
    }
}, 20);

// ─── Réglages > Discussion : avertir et griser les réglages natifs ───────
add_action('admin_notices', function () {
    if (($GLOBALS['pagenow'] ?? '') === 'options-discussion.php') {
        hn_toolkit_module_notice('Les commentaires sont désactivés sur tout le site par HN Toolkit. Les réglages ci-dessous n\'ont pas d\'effet.');
    }
});

add_action('admin_footer-options-discussion.php', function () {
    ?>
    <script>
    document.querySelectorAll('form[action="options.php"] input, form[action="options.php"] select, form[action="options.php"] textarea').forEach(function (el) {
        if (el.type === 'hidden' || el.type === 'submit') return;
        el.disabled = true;
        el.style.opacity = '0.4';
    });
    </script>
    <?php
});
