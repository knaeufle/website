/**
 * freund-hase Cookie Consent
 * DSGVO-konformes Consent-Management
 * Google Analytics wird nur geladen wenn Nutzer zustimmt
 */

(function () {
    'use strict';

    /* ── Konfiguration ── */
    const GA_ID    = 'G-XXXXXXXXXX'; // ← Hier deine GA Measurement-ID eintragen
    const LS_KEY   = 'freundhase_cookie_consent';

    /* ── Consent aus localStorage lesen ── */
    function getConsent() {
        try { return JSON.parse(localStorage.getItem(LS_KEY)); } catch (e) { return null; }
    }

    function saveConsent(analytics) {
        localStorage.setItem(LS_KEY, JSON.stringify({ decided: true, analytics: !!analytics }));
    }

    /* ── Google Analytics laden ── */
    function loadGA() {
        if (document.getElementById('cc-ga-script')) return; // already loaded
        const s = document.createElement('script');
        s.id    = 'cc-ga-script';
        s.async = true;
        s.src   = 'https://www.googletagmanager.com/gtag/js?id=' + GA_ID;
        document.head.appendChild(s);
        window.dataLayer = window.dataLayer || [];
        window.gtag = function () { window.dataLayer.push(arguments); };
        gtag('js', new Date());
        gtag('config', GA_ID, { anonymize_ip: true });
    }

    function disableGA() {
        window['ga-disable-' + GA_ID] = true;
    }

    /* ── CSS injizieren ── */
    function injectStyles() {
        if (document.getElementById('cc-styles')) return;
        const style = document.createElement('style');
        style.id = 'cc-styles';
        style.textContent = `
/* ── Cookie Banner ── */
#cc-banner {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 9000;
    background: #1a1a1a; border-top: 1px solid rgba(255,255,255,0.08);
    padding: 16px clamp(16px,5vw,60px);
    font-family: 'Outfit', 'Segoe UI', sans-serif;
    transform: translateY(100%); opacity: 0;
    transition: transform 0.35s cubic-bezier(.4,0,.2,1), opacity 0.35s;
    box-sizing: border-box;
}
#cc-banner.show { transform: translateY(0); opacity: 1; }
#cc-banner-inner {
    max-width: 1200px; margin: 0 auto;
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
#cc-banner p {
    flex: 1; min-width: 0;
    font-size: 0.82rem; line-height: 1.6; color: rgba(255,255,255,0.75);
    margin: 0;
}
#cc-banner p strong { color: #fff; font-weight: 600; }
#cc-banner-btns {
    display: flex; align-items: center; gap: 8px; flex-shrink: 0; flex-wrap: wrap;
    width: 100%;
}
.cc-btn {
    font-family: 'Outfit', sans-serif; font-size: 0.75rem;
    font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
    padding: 9px 18px; border-radius: 2px; cursor: pointer;
    transition: opacity 0.2s, background 0.2s; border: none; white-space: nowrap;
    flex: 1; text-align: center;
}
.cc-btn-ghost {
    background: transparent; color: rgba(255,255,255,0.5);
    border: 1px solid rgba(255,255,255,0.15) !important;
    border-radius: 2px;
}
.cc-btn-ghost:hover { color: #fff; border-color: rgba(255,255,255,0.4) !important; }
.cc-btn-reject { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.7); }
.cc-btn-reject:hover { background: rgba(255,255,255,0.14); }
.cc-btn-accept { background: #d4177a; color: #fff; }
.cc-btn-accept:hover { opacity: 0.88; }
@media (min-width: 600px) {
    #cc-banner-btns { width: auto; }
    .cc-btn { flex: none; }
}

/* ── Cookie Modal ── */
#cc-overlay {
    position: fixed; inset: 0; z-index: 9100;
    background: rgba(0,0,0,0.72); backdrop-filter: blur(4px);
    display: flex; align-items: flex-end; justify-content: center;
    opacity: 0; pointer-events: none;
    transition: opacity 0.25s;
    padding: 0;
}
#cc-overlay.show { opacity: 1; pointer-events: all; }
#cc-modal {
    background: #1e1e1e; border: 1px solid rgba(255,255,255,0.1);
    border-radius: 4px 4px 0 0; width: 100%; max-width: 100%;
    padding: 24px 20px 32px; position: relative;
    transform: translateY(20px); transition: transform 0.25s;
    font-family: 'Outfit', 'Segoe UI', sans-serif;
    max-height: 90vh; overflow-y: auto; box-sizing: border-box;
}
@media (min-width: 600px) {
    #cc-overlay { align-items: center; padding: 20px; }
    #cc-modal {
        border-radius: 4px; max-width: 480px;
        padding: 32px; max-height: none; overflow-y: visible;
    }
}
#cc-overlay.show #cc-modal { transform: translateY(0); }
#cc-modal-close {
    position: absolute; top: 16px; right: 16px;
    background: none; border: none; cursor: pointer;
    color: rgba(255,255,255,0.4); font-size: 1.2rem; line-height: 1;
    padding: 4px; transition: color 0.2s;
}
#cc-modal-close:hover { color: #fff; }
#cc-modal h2 {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 1.25rem; font-weight: 700; color: #fff;
    margin: 0 0 10px;
}
#cc-modal .cc-modal-sub {
    font-size: 0.8rem; color: rgba(255,255,255,0.55); line-height: 1.65;
    margin: 0 0 24px;
}
#cc-modal .cc-modal-sub a {
    color: #d4177a; text-decoration: none;
}
#cc-modal .cc-modal-sub a:hover { text-decoration: underline; }
.cc-service {
    background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
    border-radius: 3px; padding: 14px 16px;
    display: flex; align-items: flex-start; gap: 14px;
    margin-bottom: 10px;
}
.cc-service-info { flex: 1; }
.cc-service-name {
    font-size: 0.85rem; font-weight: 600; color: #fff; margin-bottom: 4px;
}
.cc-service-desc { font-size: 0.72rem; color: rgba(255,255,255,0.45); line-height: 1.5; }
.cc-service-purpose {
    font-size: 0.68rem; color: rgba(255,255,255,0.3);
    margin-top: 3px; letter-spacing: 0.04em;
}

/* Toggle Switch */
.cc-toggle { position: relative; width: 40px; height: 22px; flex-shrink: 0; margin-top: 2px; }
.cc-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.cc-toggle-slider {
    position: absolute; inset: 0; border-radius: 22px;
    background: rgba(255,255,255,0.15); cursor: pointer;
    transition: background 0.2s;
}
.cc-toggle-slider::before {
    content: ''; position: absolute;
    width: 16px; height: 16px; border-radius: 50%;
    left: 3px; top: 3px; background: #fff;
    transition: transform 0.2s;
}
.cc-toggle input:checked + .cc-toggle-slider { background: #d4177a; }
.cc-toggle input:checked + .cc-toggle-slider::before { transform: translateX(18px); }

/* Necessary badge */
.cc-badge {
    font-size: 0.62rem; font-weight: 700; letter-spacing: 0.08em;
    text-transform: uppercase; color: rgba(255,255,255,0.35);
    background: rgba(255,255,255,0.06); padding: 3px 7px;
    border-radius: 2px; white-space: nowrap; margin-top: 2px; flex-shrink: 0;
}

#cc-modal-save {
    width: 100%; margin-top: 20px; padding: 12px;
    background: #d4177a; color: #fff; border: none; border-radius: 2px;
    font-family: 'Outfit', sans-serif; font-size: 0.8rem;
    font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
    cursor: pointer; transition: opacity 0.2s;
}
#cc-modal-save:hover { opacity: 0.88; }

/* ── Gear Icon im Footer ── */
.cc-gear-btn {
    background: none; border: none; cursor: pointer;
    color: rgba(255,255,255,0.3); padding: 0 0 0 4px;
    line-height: 1; transition: color 0.2s;
    display: inline-flex; align-items: center;
    vertical-align: middle;
}
.cc-gear-btn:hover { color: rgba(255,255,255,0.7); }
.cc-gear-btn svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; }
`;
        document.head.appendChild(style);
    }

    /* ── Banner HTML erstellen ── */
    function createBanner() {
        const el = document.createElement('div');
        el.id = 'cc-banner';
        el.innerHTML = `
<div id="cc-banner-inner">
    <p>Hallo! Könnten wir bitte einige zusätzliche Dienste für <strong>Besucher-Statistiken</strong> aktivieren? Sie können Ihre Zustimmung später jederzeit ändern oder zurückziehen.</p>
    <div id="cc-banner-btns">
        <button class="cc-btn cc-btn-ghost" id="cc-btn-choose">Lassen Sie mich wählen</button>
        <button class="cc-btn cc-btn-reject" id="cc-btn-reject">Ich lehne ab</button>
        <button class="cc-btn cc-btn-accept" id="cc-btn-accept">Das ist ok</button>
    </div>
</div>`;
        document.body.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));

        el.querySelector('#cc-btn-accept').addEventListener('click', function () {
            acceptAll();
        });
        el.querySelector('#cc-btn-reject').addEventListener('click', function () {
            rejectAll();
        });
        el.querySelector('#cc-btn-choose').addEventListener('click', function () {
            hideBanner();
            openModal();
        });
    }

    /* ── Modal HTML erstellen ── */
    function createModal() {
        const el = document.createElement('div');
        el.id = 'cc-overlay';
        el.innerHTML = `
<div id="cc-modal" role="dialog" aria-modal="true" aria-labelledby="cc-modal-title">
    <button id="cc-modal-close" aria-label="Schließen">✕</button>
    <h2 id="cc-modal-title">Dienste, die wir nutzen möchten</h2>
    <p class="cc-modal-sub">
        Hier können Sie einsehen und anpassen, welche Informationen wir über Sie sammeln.
        Um mehr zu erfahren, lesen Sie bitte unsere
        <a href="datenschutz.html">Datenschutzerklärung</a>.
    </p>

    <div class="cc-service">
        <div class="cc-service-info">
            <div class="cc-service-name">Notwendig</div>
            <div class="cc-service-desc">Grundlegende Funktionen der Website (keine Tracking-Daten).</div>
            <div class="cc-service-purpose">Zweck: Technisch erforderlich</div>
        </div>
        <span class="cc-badge">Immer aktiv</span>
    </div>

    <div class="cc-service">
        <div class="cc-service-info">
            <div class="cc-service-name">Google Analytics</div>
            <div class="cc-service-desc">Erfassen von Besucherstatistiken. IP-Adressen werden anonymisiert.</div>
            <div class="cc-service-purpose">Zweck: Besucher-Statistiken</div>
        </div>
        <label class="cc-toggle">
            <input type="checkbox" id="cc-toggle-ga">
            <span class="cc-toggle-slider"></span>
        </label>
    </div>

    <button id="cc-modal-save">Speichern</button>
</div>`;
        document.body.appendChild(el);

        el.querySelector('#cc-modal-close').addEventListener('click', closeModal);
        el.addEventListener('click', function (e) {
            if (e.target === el) closeModal();
        });
        el.querySelector('#cc-modal-save').addEventListener('click', function () {
            const analytics = document.getElementById('cc-toggle-ga').checked;
            applyConsent(analytics);
            closeModal();
        });
    }

    /* ── Gear-Button in alle Footer einfügen ── */
    function injectGearButtons() {
        document.querySelectorAll('.footer-copy').forEach(function (el) {
            if (el.querySelector('.cc-gear-btn')) return;
            const btn = document.createElement('button');
            btn.className = 'cc-gear-btn';
            btn.setAttribute('aria-label', 'Cookie-Einstellungen');
            btn.setAttribute('title', 'Cookie-Einstellungen');
            btn.innerHTML = `<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>`;
            btn.addEventListener('click', openModal);
            el.appendChild(btn);
        });
    }

    /* ── Banner / Modal Steuerung ── */
    function hideBanner() {
        const banner = document.getElementById('cc-banner');
        if (banner) {
            banner.classList.remove('show');
            setTimeout(function () { banner.remove(); }, 400);
        }
    }

    function openModal() {
        const overlay = document.getElementById('cc-overlay');
        if (!overlay) return;
        const consent = getConsent();
        const toggle  = document.getElementById('cc-toggle-ga');
        if (toggle) toggle.checked = consent ? !!consent.analytics : true;
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        const overlay = document.getElementById('cc-overlay');
        if (overlay) overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    /* ── Consent anwenden ── */
    function applyConsent(analytics) {
        saveConsent(analytics);
        if (analytics) {
            loadGA();
        } else {
            disableGA();
        }
        hideBanner();
    }

    function acceptAll() { applyConsent(true); }
    function rejectAll() { applyConsent(false); }

    /* ── Init ── */
    function init() {
        injectStyles();
        createModal();
        injectGearButtons();

        const consent = getConsent();
        if (!consent || !consent.decided) {
            createBanner();
        } else {
            if (consent.analytics) loadGA();
            else disableGA();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
