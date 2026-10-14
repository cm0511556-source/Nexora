/* Nexora map bootstrap.
 *
 * Wraps MapLibre creation with:
 *   - a guaranteed resize after load (hidden/zero-height containers),
 *   - a visible error banner instead of a silent blank canvas,
 *   - an automatic free raster fallback (OpenStreetMap, no API key) if the
 *     configured vector style cannot be reached.
 *
 * Load AFTER maplibre-gl.js.
 */
(function () {
  'use strict';

  window.NXFallbackStyle = {
    version: 8,
    sources: {
      nxosm: {
        type: 'raster',
        tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
        tileSize: 256,
        attribution: '&copy; OpenStreetMap contributors'
      }
    },
    layers: [{ id: 'nxosm', type: 'raster', source: 'nxosm' }]
  };

  function banner(msg) {
    var bar = document.getElementById('nx-map-status');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'nx-map-status';
      bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#7f1d1d;color:#fff;padding:7px 12px;font-size:.76rem;z-index:99998;font-family:monospace;';
      if (document.body) document.body.appendChild(bar);
    }
    if (bar) bar.textContent = 'Map: ' + msg;
  }

  window.nxCreateMap = function (container, styleUrl, opts) {
    var options = { container: container, style: styleUrl };
    if (opts) {
      for (var k in opts) {
        if (Object.prototype.hasOwnProperty.call(opts, k)) options[k] = opts[k];
      }
    }

    var map = new maplibregl.Map(options);
    var loaded = false;
    map.__nxFallbackTried = false;

    map.on('load', function () {
      loaded = true;
      var status = document.getElementById('nx-map-status');
      if (status) status.style.display = 'none';
      setTimeout(function () { try { map.resize(); } catch (e) {} }, 80);
    });

    map.on('error', function (e) {
      var msg = (e && e.error && e.error.message) ? e.error.message : 'basemap resource could not load';
      // Only swap to the raster fallback while the basemap has not yet loaded.
      if (!loaded && !map.__nxFallbackTried) {
        map.__nxFallbackTried = true;
        try { map.setStyle(window.NXFallbackStyle); } catch (err) {}
        banner('using offline fallback basemap (' + msg + ')');
      }
    });

    // If nothing loaded after 7s, force the fallback so the map is never blank.
    setTimeout(function () {
      if (!loaded && !map.__nxFallbackTried) {
        map.__nxFallbackTried = true;
        try { map.setStyle(window.NXFallbackStyle); } catch (e) {}
        banner('slow basemap — switched to fallback tiles');
      }
    }, 7000);

    // Extra resizes for tabs / accordions that start hidden.
    setTimeout(function () { try { map.resize(); } catch (e) {} }, 500);
    setTimeout(function () { try { map.resize(); } catch (e) {} }, 1800);

    return map;
  };
})();
