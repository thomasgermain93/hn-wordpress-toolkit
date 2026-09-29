/* HN Toolkit — module « Ordre manuel » : glisser-déposer dans la liste d'admin.
 * Chargé seulement sur la vue complète d'un type activé. Après chaque
 * déplacement, toute la liste est renumérotée 1..N côté serveur. */
jQuery(function ($) {
    'use strict';
    var conf = window.hnOrdre, liste = $('#the-list'), avant = [];
    var lignes = function () { return liste.children('tr[id^="post-"]'); };
    var ids = function () { return lignes().map(function () { return this.id.replace('post-', ''); }).get(); };

    if (!conf || lignes().length < 2) return;

    liste.sortable({
        items: '> tr[id^="post-"]',
        handle: '.hn-ordre-poignee',
        axis: 'y',
        cursor: 'move',
        placeholder: 'hn-ordre-place',
        helper: function (e, tr) {   // keep cell widths while dragging
            var cellules = tr.children();
            var copie = tr.clone();
            copie.children().each(function (i) { $(this).width(cellules.eq(i).width()); });
            return copie;
        },
        start: function (e, ui) {
            avant = ids();
            ui.placeholder.html('<td colspan="' + ui.item.children(':visible').length + '">&nbsp;</td>');
        },
        update: function () {
            liste.sortable('disable').addClass('hn-ordre-enregistre');
            $.post(conf.ajax, { action: 'hn_ordre_enregistrer', nonce: conf.nonce, type: conf.type, ids: ids() })
                .done(function (r) {
                    if (!r || !r.success) return echec(r && r.data);
                    lignes().each(function (i) { $(this).find('.hn-ordre-num').text(i + 1); });
                })
                .fail(function (x) { echec(x.responseJSON && x.responseJSON.data); })
                .always(function () { liste.sortable('enable').removeClass('hn-ordre-enregistre'); });
        }
    });

    function echec(message) {
        // Put the rows back in their previous order.
        $.each(avant, function (i, id) { liste.append($('#post-' + id)); });
        window.alert(message || conf.erreur);
    }
});
