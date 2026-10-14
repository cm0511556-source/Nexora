/* Nexora Logistics — validation popup system.
   Used by every admin form. Server + client both call these. */
(function (global) {
  'use strict';

  var host = null;

  function ensureHost() {
    if (host && document.body.contains(host)) return host;
    host = document.createElement('div');
    host.id = 'nxp-host';
    host.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:10px;max-width:360px;pointer-events:none;';
    document.body.appendChild(host);
    return host;
  }

  function build(kind, title, message, opts) {
    opts = opts || {};
    var colors = {
      ok:    { bg: '#166534', border: '#22c55e', icon: '✓' },
      warn:  { bg: '#78350f', border: '#f59e0b', icon: '⚠' },
      err:   { bg: '#7f1d1d', border: '#ef4444', icon: '✕' },
    };
    var c = colors[kind] || colors.ok;

    var el = document.createElement('div');
    el.style.cssText =
      'pointer-events:auto;background:' + c.bg + ';color:#fff;border-left:4px solid ' + c.border + ';' +
      'border-radius:10px;padding:.85rem 1rem .85rem 2.4rem;box-shadow:0 12px 28px rgba(0,0,0,.35);' +
      'font-family:"Plus Jakarta Sans",system-ui,-apple-system,sans-serif;font-size:.9rem;line-height:1.5;' +
      'position:relative;transform:translateX(110%);transition:transform .25s ease;cursor:pointer;';

    var icon = document.createElement('div');
    icon.textContent = c.icon;
    icon.style.cssText =
      'position:absolute;left:.85rem;top:.85rem;width:20px;height:20px;border-radius:50%;background:' + c.border + ';' +
      'display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.75rem;color:' + c.bg + ';';
    el.appendChild(icon);

    if (title) {
      var t = document.createElement('div');
      t.textContent = title;
      t.style.cssText = 'font-weight:800;font-size:.95rem;margin-bottom:.2rem;';
      el.appendChild(t);
    }
    var m = document.createElement('div');
    m.textContent = message || '';
    m.style.cssText = 'opacity:.95;word-break:break-word;';
    el.appendChild(m);

    function dismiss() {
      el.style.transform = 'translateX(110%)';
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 260);
    }
    el.addEventListener('click', dismiss);

    var host = ensureHost();
    host.appendChild(el);
    requestAnimationFrame(function () { el.style.transform = 'translateX(0)'; });

    if (kind === 'ok' && opts.auto !== false) {
      setTimeout(dismiss, opts.duration || 3200);
    }
    return el;
  }

  var NXP = {
    ok:   function (msg, title) { return build('ok',   title || 'Saved',            msg); },
    warn: function (msg, title) { return build('warn', title || 'Check your data',  msg); },
    err:  function (msg, title) { return build('err',  title || 'Error',            msg); },
    raw:  build,
  };

  // Server can pass a redirect flag by including <script>NXP_FLASH = {...};</script>
  global.addEventListener('DOMContentLoaded', function () {
    try {
      if (global.NXP_FLASH && global.NXP_FLASH.type && global.NXP_FLASH.msg) {
        NXP[global.NXP_FLASH.type](global.NXP_FLASH.msg, global.NXP_FLASH.title);
        global.NXP_FLASH = null;
      }
    } catch (e) {}
  });

  global.NXP = NXP;
})(window);
