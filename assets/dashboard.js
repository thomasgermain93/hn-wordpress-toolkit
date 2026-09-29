/* Réglages > HN Toolkit : interrupteurs des modules enregistrés en AJAX.
 * Sans JavaScript, le formulaire de chaque carte est envoyé normalement. */
(function () {
    'use strict';
    if (!window.fetch || !window.FormData) return;

    document.querySelectorAll('.hn-module-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var button = form.querySelector('.hn-switch');
            if (button.getAttribute('aria-busy') === 'true') return;
            button.setAttribute('aria-busy', 'true');

            fetch(hnToolkit.ajax, { method: 'POST', credentials: 'same-origin', body: new FormData(form) })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success) throw new Error(res && res.data ? res.data : '');
                    var active = !!res.data.active;
                    var card = form.closest('.hn-module');
                    var settings = card.querySelector('.hn-module-settings');
                    card.classList.toggle('is-active', active);
                    button.setAttribute('aria-checked', active ? 'true' : 'false');
                    button.querySelector('.hn-switch-label').textContent = active ? hnToolkit.actif : hnToolkit.inactif;
                    form.querySelector('input[name="active"]').value = active ? '0' : '1';
                    if (settings) settings.hidden = !active;
                })
                .catch(function (err) {
                    window.alert((err && err.message) || hnToolkit.erreur);
                })
                .then(function () { button.removeAttribute('aria-busy'); });
        });
    });
})();
