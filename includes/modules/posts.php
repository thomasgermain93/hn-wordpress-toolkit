<?php
/**
 * Module « Désactiver les articles (blog) ».
 *
 * Chargé uniquement quand le module est actif (option hn_disable_posts).
 * Masque le menu Articles et « Nouvel article », redirige (301) les archives
 * et articles vers l'accueil, grise les réglages natifs liés au blog dans
 * Réglages > Lecture.
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

// ─── Réglages > Lecture : avertir et griser les réglages propres au blog ──────
add_action('admin_notices', function () {
    if (($GLOBALS['pagenow'] ?? '') === 'options-reading.php') {
        hn_toolkit_module_notice('Les articles (blog) sont désactivés par HN Toolkit. Les réglages grisés ci-dessous n\'ont pas d\'effet.');
    }
});

add_action('admin_footer-options-reading.php', function () {
    ?>
    <script>
    (function () {
        var form = document.querySelector('form[action="options.php"]');
        if (!form) return;

        // Only fields 100% related to the blog. show_on_front="page" and
        // page_on_front stay active. NB: posts_per_page is NOT greyed — it
        // controls every archive (custom post types, taxonomies, search, ...).
        ['posts_per_rss', 'rss_use_excerpt'].forEach(function (name) {
            var el = form.querySelector('[name="' + name + '"]');
            if (!el) return;
            el.disabled = true;
            var row = el.closest('tr');
            if (row) row.style.opacity = '0.4';
        });

        // page_for_posts shares its <tr> with page_on_front: grey its label only.
        var pageForPosts = form.querySelector('[name="page_for_posts"]');
        if (pageForPosts) {
            pageForPosts.disabled = true;
            (pageForPosts.closest('label') || pageForPosts).style.opacity = '0.4';
        }

        // show_on_front="posts" radio: grey its label only.
        var postsRadio = form.querySelector('input[name="show_on_front"][value="posts"]');
        if (postsRadio) {
            postsRadio.disabled = true;
            var lbl = postsRadio.closest('label');
            if (lbl) lbl.style.opacity = '0.4';
        }
    })();
    </script>
    <?php
});

// ─── Admin: remove Posts menu item ────────────────────────────────────────────
add_action('admin_menu', function () {
    remove_menu_page('edit.php'); // Posts
}, 999);

// ─── Admin: remove "New Post" from the admin bar ──────────────────────────────
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    $bar->remove_node('new-post');
}, 999);

// ─── Front-end: redirect blog archive and single posts ───────────────────────
add_action('template_redirect', function () {
    // is_home() && is_front_page() = root URL with show_on_front='posts' → don't redirect or it loops.
    if ((is_home() && ! is_front_page()) || is_singular('post') || is_category() || is_tag() || is_date() || is_author()) {
        wp_redirect(home_url('/'), 301);
        exit;
    }
});
