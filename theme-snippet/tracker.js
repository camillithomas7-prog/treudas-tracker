/**
 * TREUDAS Tracker — snippet client-side
 *
 * Da copiare in `assets/tracker.js` del tema Shopify e includere in `theme.liquid`:
 *   <script src="{{ 'tracker.js' | asset_url }}" defer></script>
 *
 * Configurazione: aggiornare TREUDAS_TRACKER_URL con il dominio reale del server.
 *
 * Comportamento:
 *   • Riusa il session id già gestito da session.js (localStorage "treudas_session_v1")
 *   • Spedisce eventi via navigator.sendBeacon (non blocking, sopravvive a unload)
 *   • Auto-rileva il tipo di pagina e spara l'evento giusto
 *   • Aggancia listener globali per click su .cta e submit checkout
 */
(function () {
    'use strict';

    // ⚠ MODIFICARE con il dominio del tracker prima del deploy in produzione
    var TREUDAS_TRACKER_URL = 'https://track.treudasofficial.com/api/track.php';

    var SESSION_STORAGE_KEY = 'treudas_session_v1';
    var UTM_KEYS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','fbclid','gclid','ttclid'];

    // ─── Helper ──────────────────────────────────────────────────
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function getSession() {
        try {
            var raw = localStorage.getItem(SESSION_STORAGE_KEY);
            if (raw) {
                var d = JSON.parse(raw);
                if (d && d.id) return d;
            }
        } catch(e) {}

        // crea nuova sessione se non esiste (compatibile con session.js esistente)
        var q = new URLSearchParams(window.location.search);
        var utm = {};
        UTM_KEYS.forEach(function(k) { var v = q.get(k); if (v) utm[k] = v; });

        var s = {
            id: uuid(),
            created_at: Date.now(),
            landing_page: window.location.pathname + window.location.search,
            referrer: document.referrer || '',
            utm: utm,
            page_views: 1
        };
        try { localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(s)); } catch(e) {}
        return s;
    }

    function detectPageType() {
        var path = window.location.pathname.toLowerCase();
        // Verifica pagine speciali (più lenient: !== -1 invece di === 0,
        // così matcha anche prefix locale tipo /it-it/products/)
        if (path.indexOf('/products/') !== -1) return 'product_view';
        if (path.indexOf('/cart') !== -1)      return 'add_to_cart';
        if (path.indexOf('/checkouts') !== -1 || path.indexOf('/checkout') !== -1) return 'checkout_start';
        if (path.indexOf('/thank_you') !== -1) return 'thank_you_view';
        // Default: homepage / pages / qualunque altra pagina = advertorial_view
        // (TREUDAS è un funnel single-page, tutto fuori da /products /cart /checkout è advertorial)
        return 'advertorial_view';
    }

    function send(eventType, meta) {
        if (!eventType) return;
        var session = getSession();

        var payload = {
            session_id: session.id,
            event_type: eventType,
            client_ts: Math.floor(Date.now() / 1000),
            url: window.location.href,
            referrer: document.referrer || '',
            utm: session.utm || {},
            meta: meta || {}
        };

        try {
            var blob = new Blob([JSON.stringify(payload)], { type: 'text/plain' });
            if (navigator.sendBeacon) {
                navigator.sendBeacon(TREUDAS_TRACKER_URL, blob);
                return;
            }
            // Fallback fetch keepalive (Safari < 13)
            fetch(TREUDAS_TRACKER_URL, {
                method: 'POST',
                body: blob,
                keepalive: true,
                mode: 'no-cors'
            }).catch(function(){});
        } catch (e) {
            // silenzioso — il tracking non deve mai rompere UX
        }
    }

    // ─── Eventi automatici ───────────────────────────────────────
    function init() {
        getSession();

        // session_start (1 sola volta per sessione) + page event
        send('session_start');
        var pageType = detectPageType();
        if (pageType) send(pageType);
    }

    // CTA click tracking — qualunque <a class="cta…"> che porta a /products/
    document.addEventListener('click', function(e) {
        var t = e.target.closest && e.target.closest('a.cta, .cta-primary, .cta-mega, .sticky-cta, [data-treudas-cta]');
        if (!t) return;
        if (!t.href) return;
        if (t.href.indexOf('/products/') === -1 && !t.dataset.treudasCta) return;

        // estrai posizione/etichetta CTA se data attribute
        var meta = {
            href: t.href,
            cta_position: t.dataset.ctaPosition || null,
            cta_label: (t.textContent || '').trim().slice(0, 60)
        };
        send('cta_click', meta);
    }, { capture: true, passive: true });

    // Add-to-cart tracking — submit del form #treudas-form o /cart/add
    document.addEventListener('submit', function(e) {
        var f = e.target;
        if (!f || f.tagName !== 'FORM') return;
        var action = (f.getAttribute('action') || '').toLowerCase();
        if (action.indexOf('/cart/add') !== -1 || f.id === 'treudas-form') {
            var qty = f.querySelector('[name="quantity"]');
            var id  = f.querySelector('[name="id"]');
            send('add_to_cart', {
                variant_id: id ? id.value : null,
                quantity: qty ? qty.value : 1
            });
        }
    }, { capture: true, passive: true });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // espone API minimal per uso manuale dal tema
    window.TreudasTracker = {
        track: send,
        session: getSession
    };
})();
