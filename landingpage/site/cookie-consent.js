/* Benzaiten LMS — cookie consent banner (Accept all / Reject all).
   Self-contained: injects its own styles + markup, remembers the choice
   in a first-party cookie, and updates Google Consent Mode.
   The "default = denied" and returning-visitor re-apply are set INLINE
   in each page's <head> before GTM/GA4; this file handles the banner UI. */
(function () {
  var COOKIE = 'benzaiten_consent';
  var DAYS = 180;
  var BRAND = '#520d4d';
  var BRAND_DARK = '#3f0a3b';

  function readCookie() {
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + COOKIE + '=([^;]+)'));
    if (!m) return null;
    try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
  }
  function writeCookie(obj) {
    var d = new Date();
    d.setTime(d.getTime() + DAYS * 24 * 60 * 60 * 1000);
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(obj)) +
      '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + secure;
  }
  function applyConsent(accepted) {
    if (typeof window.gtag === 'function') {
      var g = accepted ? 'granted' : 'denied';
      window.gtag('consent', 'update', {
        'ad_storage': g, 'ad_user_data': g, 'ad_personalization': g, 'analytics_storage': g
      });
    }
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event: 'benzaiten_consent_update', accepted: !!accepted });
  }

  var css = ''
    + '#bz-cookie-banner,#bz-cookie-reopen{box-sizing:border-box}'
    + '#bz-cookie-banner *{box-sizing:border-box}'
    + '#bz-cookie-banner{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;background:#fff;'
    + 'border-top:1px solid #e6e6e6;box-shadow:0 -4px 24px rgba(0,0,0,.10);padding:18px 20px;'
    + 'font-family:"Montserrat","Poppins",-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;'
    + 'color:#333;font-size:14px;line-height:1.5;display:none}'
    + '#bz-cookie-banner.bz-show{display:block}'
    + '.bz-banner-inner{max-width:1120px;margin:0 auto;display:flex;align-items:center;gap:20px;flex-wrap:wrap}'
    + '.bz-banner-text{flex:1 1 340px;min-width:260px}'
    + '.bz-banner-text strong{color:' + BRAND + '}'
    + '.bz-banner-text a{color:' + BRAND + ';text-decoration:underline}'
    + '.bz-banner-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}'
    + '.bz-btn{font:inherit;font-weight:600;cursor:pointer;border-radius:8px;padding:10px 20px;'
    + 'border:1.5px solid ' + BRAND + ';transition:background .15s,color .15s}'
    + '.bz-btn-primary{background:' + BRAND + ';color:#fff}'
    + '.bz-btn-primary:hover{background:' + BRAND_DARK + ';border-color:' + BRAND_DARK + '}'
    + '.bz-btn-ghost{background:#fff;color:' + BRAND + '}'
    + '.bz-btn-ghost:hover{background:#f5eef4}'
    + '#bz-cookie-reopen{position:fixed;left:16px;bottom:16px;z-index:2147483000;background:#fff;'
    + 'border:1px solid #e0e0e0;border-radius:30px;padding:8px 14px;font-size:13px;color:' + BRAND + ';'
    + 'cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.12);'
    + 'font-family:"Montserrat","Poppins",-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;display:none}'
    + '#bz-cookie-reopen.bz-show{display:inline-block}'
    + '@media (max-width:640px){.bz-banner-actions{width:100%}.bz-banner-actions .bz-btn{flex:1 1 auto;text-align:center}}';

  var html = ''
    + '<div id="bz-cookie-banner" role="dialog" aria-live="polite" aria-label="Cookie consent">'
    +   '<div class="bz-banner-inner">'
    +     '<div class="bz-banner-text"><strong>We value your privacy.</strong> '
    +       'We use cookies to run this site and, with your consent, to understand how it’s used so we can improve it. '
    +       'See our <a href="/privacy-policy/">Privacy&nbsp;Policy</a>.</div>'
    +     '<div class="bz-banner-actions">'
    +       '<button type="button" class="bz-btn bz-btn-ghost" id="bz-reject">Reject all</button>'
    +       '<button type="button" class="bz-btn bz-btn-primary" id="bz-accept">Accept all</button>'
    +     '</div>'
    +   '</div>'
    + '</div>'
    + '<button type="button" id="bz-cookie-reopen" aria-label="Open cookie settings">🍪 Cookie settings</button>';

  function init() {
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    while (wrap.firstChild) { document.body.appendChild(wrap.firstChild); }

    var banner = document.getElementById('bz-cookie-banner');
    var reopen = document.getElementById('bz-cookie-reopen');

    function showBanner() { banner.classList.add('bz-show'); reopen.classList.remove('bz-show'); }
    function hideBanner() { banner.classList.remove('bz-show'); reopen.classList.add('bz-show'); }
    function save(accepted) {
      writeCookie({ accepted: accepted, ts: new Date().toISOString(), v: 1 });
      applyConsent(accepted);
      hideBanner();
    }

    document.getElementById('bz-accept').addEventListener('click', function () { save(true); });
    document.getElementById('bz-reject').addEventListener('click', function () { save(false); });
    reopen.addEventListener('click', showBanner);
    window.benzaitenCookieSettings = showBanner;

    if (readCookie()) { hideBanner(); } else { showBanner(); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();