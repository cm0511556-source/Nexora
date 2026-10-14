# Nexora Logistics — Testing & Verification Report

**Scope:** Mapbox → MapLibre/OpenFreeMap migration, plus an end-to-end
behavioural audit of admin → customer tracking sync.
**Companion file:** `NEXORA-DOCUMENTATION.md`.

---

## 1. Environment & method

| Item | Value |
|---|---|
| Server | PHP built-in server, `php -S 127.0.0.1:8000 -t public` |
| PHP | **7.4.3** (`php -l` on every file) |
| Database | SQLite `database/database.sqlite` (19 live tables) |
| Node | v24.19.0 (used only to syntax-check rendered JavaScript and inspect the MapLibre bundle) |
| Headless browser | **Not available in this sandbox** — see Limitations §8.1 |

Method: static scans, `php -l`, live HTTP requests against a running server,
Node syntax checks, a real admin login session, real POSTs to the admin action
endpoints, and direct SQLite inspection before/after every step. A test shipment
was created and then **removed**, so the database was returned to its original
state.

---

## 2. Migration verification (static)

| Check | Result |
|---|---|
| Mapbox library references in code | **0** (all four map pages now load `maplibregl`) |
| `MAPBOX_TOKEN` / `api.mapbox.com` / `pk.…` in code or `.env` | **0** |
| MapLibre GL JS vendored locally | ✅ `public/assets/vendor/maplibre/maplibre-gl.js` (748 KB) + `.css` (64 KB), **v3.6.2** |
| Global name exposed | ✅ `maplibregl` (UMD) |
| OpenFreeMap style reachable | ✅ `https://tiles.openfreemap.org/styles/liberty` → HTTP 200, valid style v8 JSON (111 layers), `access-control-allow-origin: *` |
| Every map page uses the style from `.env` | ✅ `track.php`, `fleet.php`, `shipment-route.php`, `shipment-control.php` |
| Libraries/APIs used exist in the bundle | ✅ `Map`, `NavigationControl`, `FullscreenControl`, `ScaleControl`, `Marker`, `Popup`, `LngLatBounds` all exported; `getSource/addSource/addLayer/removeLayer/removeSource/fitBounds/resize/isStyleLoaded/addControl/on/easeTo/getLayer`, `Marker.setLngLat/setPopup/getElement/remove/addTo`, `Popup.setHTML`, `GeoJSONSource.setData` all present |
| `.maplibregl-canvas` CSS selector updated | ✅ |
| PHP lint, all **77** PHP files | ✅ `ALL CLEAN` on PHP 7.4.3 |

---

## 3. Page-by-page load test (live server)

### Public pages
| Page | HTTP | PHP errors |
|---|---|---|
| `/` and `/index.php` | 200 | none |
| `/pre-track.php?tracking=TEST-001` | 200 | none |
| `/track.php` (no number) | 200 | none |
| `/track.php?tracking=TEST-001` (after the name/email gate) | 200 | none |
| `/track-api.php?tracking=NX-2026-0001` | 200 (valid JSON) | none |
| `/admin/login.php` | 200 | none |

### Admin pages (authenticated)
All returned **200** with **0** PHP errors:

`index.php`, `fleet.php`, `shipment-control.php?id=1`, `shipment-route.php?id=1`,
`shipment-management.php`, `shipments.php`, `tracking-operations.php`,
`tracking-lookups.php`, `customer-messages.php`, `site-content.php`,
`testimonials.php`, `carriers.php`, `settings.php`, `people.php`, `content.php`,
`shipments-hub.php`, `media-list.php`, `manual.php`,
`hold-management.php?shipment_id=1`.

The server log contained **no** fatal errors, parse errors, warnings or notices.

---

## 4. JavaScript validation

The rendered HTML of the four map pages was parsed and every **inline**
`<script>` block was checked with `node --check`:

| Page | Inline blocks checked | Failures |
|---|---|---|
| `track.php` | 4 | 0 |
| `fleet.php` | 1 | 0 |
| `shipment-route.php` | 3 | 0 |
| `shipment-control.php` | 8 | 0 |

The MapLibre bundle itself was loaded successfully in Node and its API surface
inspected (see §2).

---

## 5. Behavioural audit — admin changes vs. the customer page

A fresh test shipment was created through the **real admin form POST**, followed
through every lifecycle step, and each step was read back through the
**customer-facing `track-api.php`**.

| # | Admin action (real request) | Customer result (`track-api.php`) | Pass |
|---|---|---|---|
| 1 | POST create shipment `NX-QA-…`, status `in_transit`, speed 600, LA → Tokyo | `status=success`, correct tracking/status/lat/lng/speed, `control.is_running=true`, 1 event | ✅ |
| 2 | Add 2 waypoints (`route-action.php` add ×2) | after step 3, both appear in `waypoints` with correct names/types/coords/labels | ✅ |
| 3 | **Rebuild route line** | `route` = 22 points, first/last `segment_type=air` | ✅ |
| 4 | Start tracking + engine tick | position advanced, `distance_travelled` increased, `progress_pct` updated | ✅ |
| 5 | **Pause** | customer `status=on_hold`, `control.is_running=false` | ✅ |
| 6 | **Set speed 350** | customer `shipment.speed=350`, `control.control_speed=350` | ✅ |
| 7 | **Resume** | customer `status=in_transit`, `control.is_running=true` | ✅ |
| 8 | **Create mandatory hold** (customs) | customer `holds=1`, `is_held=true`, `mandatory_hold=true`; tracking auto-paused with `paused_reason` | ✅ |
| 9 | **Record event** (checkpoint) | customer's newest timeline event = "Checkpoint reached / QA checkpoint reached / QA Station" | ✅ |
| 10 | **Clear hold** | customer `holds=0`, `mandatory_hold=false` | ✅ |
| 11 | Render the customer page for the new shipment | HTTP 200, 11 `maplibregl` references, OpenFreeMap style, tracking number present, **0 PHP errors** | ✅ |

**Cleanup:** the test shipment and its dependent rows were deleted. Table counts
returned to their pre-test values (`shipments` 2, `tracking_controls` 2,
`shipment_route_points` 5, `tracking_events` 23, `shipment_waypoints` 1,
`tracking_positions` 244, `shipment_holds` 4, `shipment_media` 2,
`tracking_lookups` 6).

---

## 6. Bug found during testing — and fixed

**Symptom:** a running shipment jumped far too fast; at 600 km/h it advanced
**10 km per tick** instead of the real elapsed distance, regardless of how much
time had actually passed.

**Root cause:** `config/config.php` sets PHP's timezone to `Africa/Lagos`, but
SQLite writes `CURRENT_TIMESTAMP` in **UTC**. `app/Tracking/Engine.php` compared
the two directly, so every tick looked ~1 hour late and was clamped to the 60 s
maximum.

**Fix (one line + comment) in `app/Tracking/Engine.php`:**

```php
// Parse DB timestamps explicitly as UTC.
$lastUpdate = $shipment['last_update']
    ? strtotime((string)$shipment['last_update'] . ' UTC')
    : null;
```

**Proof after the fix:** reset to the origin, waited 4 seconds at 600 km/h, one
tick → `distance_travelled = 0.6667 km` (the mathematically correct
600 × 4 ÷ 3600 = 0.667 km). Previously the same tick produced 10 km.

This directly supports requirement 5 (admin/customer sync): what the admin sets
now moves the shipment at the correct rate, so the customer's map matches.

---

## 7. Feature checklist

| Feature requested "keep" | Status | Evidence |
|---|---|---|
| MapLibre GL JS instead of Mapbox | ✅ | vendored v3.6.2, `maplibregl` on all map pages |
| OpenFreeMap tile source, no key | ✅ | style reachable, used by all pages |
| Markers (shipment, origin, destination, waypoints) | ✅ present | `maplibregl.Marker` in all four map pages; rendered marker HTML checked |
| Route lines (colour per transport mode) | ✅ present | route layers + rebuild tested (22 points) |
| Waypoints | ✅ present | add/edit/delete/rebuild endpoints tested |
| Weather HUD (Open-Meteo) | ✅ present | `hud-temp`/Open-Meteo code intact in `track.php` |
| Day/night terminator | ✅ present | `nx-night` source/layer intact (`track.php`, `shipment-control.php`) |
| Ambient vehicle animations | ✅ present | `nx-amb` build/tick/start/stop intact in `track.php` |
| Take control (start / pause / resume / speed / save position) | ✅ present | controls tested end-to-end and reflected for the customer |
| Fullscreen | ✅ present | customer CSS fullscreen (`nx-fs-btn`) + admin CSS fullscreen + fleet native control |
| Remove Mapbox token | ✅ | 0 references in code/`.env`; docs updated |

---

## 8. What is NOT verified / known limitations

These are the honest gaps and behaviours to be aware of before you rely on the
site. None of them is a regression from the migration.

### 8.1 No browser/WebGL render test in this sandbox
There is no headless browser here, so I could not visually confirm that MapLibre
paints the OpenFreeMap tiles on screen. I verified everything around it: the
bundle and API, the style URL (HTTP 200 + valid JSON + CORS), the rendered HTML,
and the inline JavaScript syntax. **On a real phone you should see the street map
with pins.** If it is blank, check that `tiles.openfreemap.org` is reachable and
that `MAP_STYLE_URL` is correct.

### 8.2 Satellite imagery is gone (inherent to OpenFreeMap)
OpenFreeMap is a **vector street-map** service. It has no satellite style. The old
`satellite-streets-v12` look cannot be reproduced with OpenFreeMap. Choose from
Liberty / Bright / Positron / Dark / Fiord via `MAP_STYLE_URL`. If you want
satellite specifically, you would need a different (licensed) tile provider —
tell me and I can wire one in behind the same `MAP_STYLE_URL` switch.

### 8.3 The "Follow" camera toggle does not exist
There is **no follow/re-center-the-camera feature** anywhere in the code, and it
was not in the GitHub original either (the remote `origin/main` is the same
commit as the baseline). So nothing was lost by the migration — but if you want
a "Follow shipment" button, it needs to be built. I did not add one because it
was not in the original.

### 8.4 "Admin-controlled ambient speeds" does not exist
The moving cars/trucks/bikes/pedestrians/planes/ships on the customer page are
**decorative** and their speeds are fixed constants in `track.php`. There is no
admin screen for them. The admin **does** control the real shipment's speed
(Controls tab), which was tested and works. If you want a settings screen to
tune the ambient traffic, that is a new feature and I can add it.

### 8.5 Movement only advances while a tick-driving page is open
Simulated movement is driven by a page polling `tracking-tick.php` (the admin
`shipment-control.php` does this every 2 s). If the admin closes that page, the
customer's own polling keeps the customer's view current but does **not** advance
the shipment. Everything *static* (new shipments, routes, status, holds, media)
still syncs instantly. If you want movement to keep going while nobody has the
admin page open, I can make `track-api.php` also run a tick on each customer poll
(recommended, small change) — I left it alone to avoid changing the original
behaviour without your say-so.

### 8.6 Other notes
* **Waypoint edits need a rebuild.** Adding/deleting stops does not automatically
  rewrite `shipment_route_points`; the customer's route updates when you click
  **Rebuild route line**.
* **Media upload** was not exercised (no file-upload POST in this audit). Existing
  media rows render, and the upload endpoint was not modified by this migration.
* **Internet required at view time** for tiles from `tiles.openfreemap.org` (the
  MapLibre library itself is now local, so it does not need a CDN).
* The app still stores/displays timestamps as UTC while `config.php` sets the
  *display* timezone to Africa/Lagos, so on-screen clock text may be off by one
  hour. This is cosmetic and pre-existing; the movement calculation (the part
  that mattered) is now correct.

---

## 9. Bottom line

* ✅ The project now uses **MapLibre GL JS + OpenFreeMap** with **no Mapbox token
  and no API key anywhere**.
* ✅ All four map surfaces load, all public and admin pages return 200, PHP 7.4
  lint is clean, and every inline script parses.
* ✅ Admin → customer sync was proven end-to-end: **new shipment, waypoints,
  route rebuild, start/pause/resume, speed change, mandatory hold, event, and
  hold clearance all appear correctly on the customer side**.
* ✅ A real movement-accuracy bug was found and fixed.
* ⚠️ Items §8.2–§8.5 are limitations/decisions, not failures. The two most likely
  to matter to you are **no satellite imagery** (OpenFreeMap limitation) and
  **movement needing an open admin page**. Tell me if you want either changed.
