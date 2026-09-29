<?php
/**
 * Module « Désactiver les pages de médias ».
 *
 * Chargé uniquement quand le module est actif (option hn_disable_media_pages).
 * Redirige les pages d'attachments vers l'URL directe du fichier (301), pour
 * éviter l'indexation de pages sans contenu.
 *
 * @package HN_Toolkit
 */

defined('ABSPATH') || exit;

add_action('template_redirect', function () {
    if (is_attachment()) {
        $url = wp_get_attachment_url(get_the_ID());
        wp_redirect($url ?: home_url('/'), 301);
        exit;
    }
});
