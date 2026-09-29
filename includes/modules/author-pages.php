<?php
/**
 * Module « Désactiver les pages d'auteurs ».
 *
 * Chargé uniquement quand le module est actif (option hn_disable_author_pages).
 * Redirige les archives auteur vers l'accueil (301).
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

add_action('template_redirect', function () {
    if (is_author()) {
        wp_redirect(home_url('/'), 301);
        exit;
    }
});
