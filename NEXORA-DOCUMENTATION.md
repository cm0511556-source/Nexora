# Nexora Logistics — Full Project Documentation

> Rewritten after **Phases 2–5**: MapLibre + OpenFreeMap maps, secure customer
> verification, customer-driven movement, customer directory, audit log, backups,
> PWA, live ETA, receipt, QR/replay, chatbot and proof of delivery.
> Companion file: `NEXORA-TEST-REPORT.md` (what was tested and what works).

---

## 0. Quick start

```bash
# From the project root (/workspace/quiet-curie)
./serve.sh
# or, equivalently:
php -S localhost:8000 -t public
```

Then open:

* Public site — <http://localhost:8000/>
* Staff login — <http://localhost:8000/admin/login.php>
* Track a shipment — <http://localhost:8000/track.php>

**Seeded admin:** `admins@logistics.com` (password set at install time).

### ⚠️ Server rule (security)
`public/` is the **only** document root. **Never** run `php -S localhost:8000`
from the project root — that would expose `.env`, `.git` and
`database/database.sqlite` over the network. The old root symlinks have been
deleted; use `./serve.sh`.

**No map API key is needed.** MapLibre GL JS is vendored inside the project and
tiles come from OpenFreeMap / OpenStreetMap, which are free and keyless.

---

## Part A — What every part / file does

### A1. Public (customer-facing) — `public/`

| File | What it does |
|---|---|
| `index.php` | Homepage. Hero, services, counters, testimonials, newsletter. **All text is read from the `site_content` table** and editable in **Content → Site Content**. Newsletter writes to `subscribers`. Registers the PWA service worker. |
| `track.php` | The tracking page: MapLibre map, marker, route, waypoints, weather HUD, day/night shadow, progress bar, **live ETA**, timeline, media strip, hold banner, **carrier "Handled by"**, **Copy link / QR / Receipt / Replay / Follow / chatbot / proof of delivery**. Polls the API every 8 s. |
| `track-api.php` | JSON feed `track.php` polls. **Requires the verified tracker session** (or admin preview). **Advances the shipment via the engine on every poll** so movement continues with the dashboard closed. Returns shipment, route, waypoints, events, media, holds, carrier, segment messages, positions (for replay), proof and settings. |
| `pre-track.php` | Identity gate. Verifies the submitted **email OR phone** against the shipment's stored contact, records the lookup, issues a 30-day remember-me cookie. Shipments with no contact are allowed and flagged **Unverified**. |
| `receipt.php` | Printable / save-as-PDF shipment receipt (uses the browser's own print-to-PDF; no library). |
| `api/chat.php` | Shipment assistant. Uses the DeepSeek API when `DEEPSEEK_API_KEY` is set in `.env`; otherwise answers from the live database with a built-in responder. |
| `service-worker.js` | Offline app-shell cache for the public site. |
| `manifest.webmanifest` | PWA manifest (installable on phones). |
| `assets/` | CSS, JS, images, uploaded media, signatures, carrier logos, vendored MapLibre, vendored `qrcode.min.js`. |

### A2. Admin pages — `public/admin/`

Navbar sections: **Dashboard · Shipments · People · Content · Settings**.

| File | What it does |
|---|---|
| `login.php` | Admin login / logout, CSRF-protected, writes an audit entry. "Forgot password?" link. |
| `password-reset.php` | Free email reset using PHP `mail()`. If mail is unavailable it shows the one-time link on screen (local fallback) and logs it to `storage/mail.log`. |
| `index.php` | Dashboard with **analytics widget** (total, active, delivered this week, in transit, delayed/held) and navigation hub. |
| `shipments-hub.php` | Shipments section hub. |
| `shipments.php` | **Create a shipment**: customer picker with **📋 Copy Customer Details**, auto-creates/links a `customers` record, computes great-circle distance, seeds `tracking_controls` + "Shipment created" event, attaches pickup media, writes an audit entry. |
| `shipment-management.php` | Search + status filter list. Each row has **Controls**, **👁️ View as Customer** and **🧾 Proof**. |
| `shipment-control.php` | Per-shipment workspace: live map (`nxCreateMap`, with fallback), controls (start/pause/resume/speed/position/event), timeline, holds, media, fullscreen. Header links to **View as Customer** and **Proof of Delivery**. |
| `shipment-route.php` | Multi-modal route editor (waypoints + route line rebuild). |
| `tracking-operations.php` | Daily control overview, with **View as Customer** per row. |
| `fleet.php` | Live fleet map: all active shipments, colour-coded, search + status filter. |
| `customers.php` | **Customer directory** CRUD (the `customers` table). Shows shipment counts. |
| `people.php` | People hub (Customers, Tracking Lookups, Testimonials). |
| `tracking-lookups.php` | Who tracked what + CSV export. |
| `testimonials.php` | Homepage reviews with photo upload. |
| `content.php` | Content hub. |
| `site-content.php` | Homepage text editor (now actually read by `index.php`). |
| `customer-messages.php` | Timeline wording (`event_translations`) and **map segment popups** (`segment_messages`, now shown when a route segment is clicked). |
| `carriers.php` | Partner carriers with logo upload. |
| `settings.php` | **Ambient traffic speed** (Off/Slow/Normal/Fast) plus Customers, Carriers, Audit Log and Database Backup tools. |
| `audit-log.php` | Every recorded admin action (who, what, when, IP). |
| `backup.php` | One-click, consistent SQLite download (`VACUUM INTO`). |
| `proof-of-delivery.php` | Upload a delivery photo and capture a recipient **signature**; optionally mark the shipment delivered. |
| `manual.php` | Built-in admin manual. |
| `_media_panel.php`, `_upload_media_after_create.php` | Shared media partials. |
| `hold-management.php`, `incident-action.php`, `media-action.php`, `route-action.php`, `tracking-action.php`, `tracking-tick.php`, `api/tracking-control.php` | JSON action endpoints (called by JS). |

### A3. Core application logic — `app/`

| File | What it does |
|---|---|
| `Database.php` | Namespaced PDO connection to `database/database.sqlite`. |
| `Auth.php` | Login, logout, `check()`, `requireAdmin()`. |
| `Tracking/Engine.php` | Movement engine. Great-circle steps, distance/ETA, `tracking_positions`, waypoint handling. **Waypoints auto-continue** (no auto-pause). |
| `helpers.php` | `loadEnv()`, `env()`, `e()`, `asset()`, `base_url()`, CSRF, JSON helpers, `nxMapStyleUrl()`, PHP 7.4 polyfills. |
| **`Support.php`** | Shared layer: `nxPdo()`, idempotent `nxEnsureSchema()` migrations, `nxSetting()/nxSettingSet()` (incl. ambient speed), `nxContent()` (site text), `nxAudit()`, `nxTrackerVerified()`/`nxRegisterTracker()`/`nxCheckTrackerIdentity()` (customer verification + remember-me), `nxEtaText()`, `nxSendMail()`, `nxTrackingFromRequest()` (`?id=` / `?tracking=`), `nxTrackUrl()`, `nxAmbientMultiplier()`. |

### A4. Front-end assets — `public/assets/`

| Path | What it does |
|---|---|
| `css/app.css` | Main stylesheet. |
| `js/app.js`, `js/nx-labels.js`, `js/nx-popup.js`, `js/tracking-control.js` | Shared helpers and admin logic. |
| **`js/nx-map.js`** | `nxCreateMap()` — wraps MapLibre with auto-resize, an error banner, and an automatic **free OpenStreetMap raster fallback** if the vector style can't load. Used by every map on the site. |
| **`js/qrcode.min.js`** | Vendored MIT QR generator (offline, no key). |
| `vendor/maplibre/maplibre-gl.{js,css}` | MapLibre GL JS v3.6.2 (vendored). |
| `images/`, `carriers/`, `media/`, `signatures/` | Static images, carrier logos, shipment media, delivery signatures. |

### A5. Configuration

| Path | What it does |
|---|---|
| `.env` | `APP_NAME`, `APP_URL`, `MAP_STYLE_URL`, and optional `DEEPSEEK_API_KEY`, `MAIL_FROM`, `PASSWORD_RESET_SHOW_LINK`. **No Mapbox token.** |
| `config/config.php` | Loads `.env`, sets timezone, defines `BASE_PATH`, `PUBLIC_PATH`, `APP_NAME`, `APP_URL`, `ASSET_URL`, `MAP_STYLE_URL`; loads `helpers.php` + `Support.php`. |
| `database/database.sqlite` | The live database (git-ignored). |
| `database/_migrate_*.php` | Idempotent historical migrations. `Support.php` auto-applies the newer schema on first use. |

---

## Part B — How the database works

**Location:** `database/database.sqlite` (SQLite, WAL mode).

### B1. Core shipment tables

| Table | Purpose | Key columns |
|---|---|---|
| **`shipments`** | One row per shipment — source of truth. | `tracking_number` (unique), `customer_id` → customers, `customer_name/email/phone`, `transport_type`, `vehicle_name/number`, `origin/destination` + `_lat/_lng`, `current_lat/lng`, `speed`, `distance_total/travelled/remaining`, `status`, `eta`, `estimated_delivery`, `last_update`, `carrier_id` |
| **`tracking_controls`** | Movement settings, 1 per shipment. | `is_running`, `control_speed`, `current_waypoint_id`, `next_waypoint_id`, `paused_reason`, `current_route_sequence` |
| **`shipment_waypoints`** | Ordered multi-modal stops. | `sequence`, `name`, `latitude/longitude`, `waypoint_type`, `leg_type`, `status`, `public_label`, `public_note` |
| **`shipment_route_points`** | Pre-computed route polyline. | `sequence`, `latitude/longitude`, `segment_type`, `waypoint_id` |
| **`tracking_positions`** | Position history (also powers **Replay journey**). | `latitude/longitude`, `speed`, `heading`, `recorded_at` |
| **`tracking_events`** | Customer timeline. | `status`, `title`, `description`, `location`, `event_time`, `icon` |
| **`shipment_holds`** | Formal holds. | `hold_type`, `reason`, `details`, `status` (active/cleared), `mandatory` |
| **`shipment_media`** | Public/private photos/videos. | `media_type`, `file_path`, `caption`, `is_public`, `context` |
| **`proof_of_delivery`** | Delivery photo + signature. | `shipment_id` (unique), `photo_path`, `signature_path`, `signed_by`, `note`, `delivered_at` |

### B2. Supporting tables

| Table | Purpose |
|---|---|
| `admins` | Staff logins (bcrypt). |
| `customers` | Customer directory; `shipments.customer_id` links to it. |
| `carriers` | Partner companies with logos. |
| `event_translations` | Customer-facing timeline wording. |
| `segment_messages` | Wording for each map segment type (shown on route click). |
| `testimonials` | Homepage reviews. |
| `tracking_lookups` | Every pre-track submission **plus** the remember-me `session_token`, `token_expires_at`, `verified`, `match_method`. |
| `site_content` | Editable homepage text (now actually rendered). |
| **`settings`** | Key/value settings: `ambient_speed`, `chatbot_enabled`, `map_style_url`, `company_email`. |
| **`audit_log`** | Every admin action: `admin_id`, `admin_name`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`. |
| **`password_resets`** | Hashed, single-use, 1-hour reset tokens. |
| `subscribers` | Newsletter emails. |
| `notifications`, `workspace_stats` | Legacy/unused. |

### B3. Relationships

```
customers (1) ──< shipments.customer_id
carriers  (1) ──< shipments.carrier_id
shipments (1)
 ├── tracking_controls      (1, UNIQUE)
 ├── shipment_waypoints     (many)
 ├── shipment_route_points  (many)
 ├── tracking_positions     (many)
 ├── tracking_events        (many)
 ├── shipment_holds         (many)
 ├── shipment_media         (many)
 └── proof_of_delivery      (1)
admins (1) ──< audit_log.admin_id / holds.created_by / media.uploaded_by
tracking_lookups  → matched by tracking_number (+ remember-me token)
```

### B4. The life of one shipment

1. **Create** (admin) → `shipments` row (+ linked/created `customers`) + `tracking_controls` + "Shipment created" event + audit entry.
2. **Add stops / rebuild route** → `shipment_waypoints`, `shipment_route_points`.
3. **Start tracking** → `tracking_controls.is_running = 1`, `shipments.status = in_transit`.
4. **Engine ticks** → triggered by **every customer poll of `track-api.php`** *and* by the admin control page. Updates position/distances/ETA and appends `tracking_positions`. Waypoints auto-continue.
5. **Customer polls** every 8 s → sees the change in ≤ 8 s.
6. **Hold / incident** → `shipment_holds` + `tracking_events` (mandatory hold auto-pauses).
7. **Delivery** → engine sets `delivered`, or you upload proof and mark delivered.

---

## Part C — Day-to-day admin guide

### C1. Log in
`/admin/login.php`. Forgot the password? Click **Forgot your password?** and enter
the admin email. If the server has a working `mail()` you get an email; if not,
the one-time link is shown on screen.

### C2. Create a shipment (with a real customer)
1. **Shipments → Create a Shipment**.
2. (Optional) Pick an **Existing customer** and click **📋 Copy Customer Details** —
   name, email and phone are filled in. To add a customer first, use
   **People → Customers**.
3. Enter tracking number, origin/destination + coordinates, transport, vehicle.
4. Choose status; **in_transit requires speed > 0**.
5. Attach pickup media and pick a carrier, then submit.
6. The shipment is reusable by the customer **immediately**.

### C3. Add the route (multi-modal)
1. Open the shipment → **Edit route**.
2. **+ Add a stop**, set the name, type and **leg type**, place it on the map.
3. Click **🛠 Rebuild route line** — the customer sees the new line after this.

### C4. Control movement
1. Open the shipment → Controls tab.
2. Set speed and **Save Movement Settings**, then **▶ Start Tracking**.
3. **Pause / Resume** as needed; **📍 Save Position** to place it manually.
4. **The shipment now keeps moving even when you close the dashboard**, because
   the customer's own polling ticks the engine.

### C5. Verify what the customer sees
* On any shipment row click **👁️ View as Customer**, or open the shipment and use
  the header button. As a signed-in admin you bypass the identity gate and see an
  "Admin preview" banner.
* Share the shipped tracking link — it uses `?id=TRACKING` and the customer only
  confirms their details **once** (30-day cookie).

### C6. Holds & incidents
**Holds & Incidents** → record a customs/safety/security/compliance/other hold.
A mandatory hold pauses movement and shows a red banner to the customer. **Clear
hold**, then set speed and resume.

### C7. Media, proof of delivery
* **Photos & Videos** tab: upload, mark public/private.
* **🧾 Proof** (row button or shipment header): upload a delivery photo, capture
  the recipient's **signature**, optionally mark **Delivered**.

### C8. Customer directory
**People → Customers** — add/edit/delete. Deleting a customer keeps their
shipments but unlinks them.

### C9. Customers, content and wording
* **Content → Site Content** — homepage text (now live on the site).
* **Content → Customer Messages** — timeline wording **and** map segment popups
  (click a coloured route segment on the customer map).
* **Content → Testimonials** — homepage reviews.
* **Settings → Partner Carriers** — carriers and logos.

### C10. Ambient map traffic speed
**Settings → 🌍 Ambient map traffic** → **Off / Slow / Normal / Fast**. Applies
globally to all decorative vehicles on all maps.

### C11. Audit log & database backup
* **Settings → Audit Log** — who did what and when.
* **Settings → Database Backup** — downloads a consistent `.sqlite` snapshot.

### C12. Chatbot
* Works out of the box with database-driven answers.
* For AI answers, add `DEEPSEEK_API_KEY=...` to `.env` (never commit it) and
  restart the server. The key is only ever used server-side.

### C13. Change the map style (free, no key)
Edit `.env`:

```env
MAP_STYLE_URL=https://tiles.openfreemap.org/styles/liberty
```

| Style | URL |
|---|---|
| Liberty (default) | `https://tiles.openfreemap.org/styles/liberty` |
| Bright | `https://tiles.openfreemap.org/styles/bright` |
| Positron | `https://tiles.openfreemap.org/styles/positron` |
| Dark | `https://tiles.openfreemap.org/styles/dark` |
| Fiord | `https://tiles.openfreemap.org/styles/fiord` |

> OpenFreeMap is a **vector street-map** service — no satellite imagery. If the
> configured style is unreachable, `nx-map.js` automatically falls back to free
> OpenStreetMap raster tiles so the map is never blank.

---

## Part D — Map system & admin ↔ customer sync

### D1. Library & tiles
* **MapLibre GL JS v3.6.2**, vendored in `public/assets/vendor/maplibre/`.
* **OpenFreeMap** vector tiles via `MAP_STYLE_URL` (OpenStreetMap raster fallback).
* `MAPBOX_TOKEN`, `api.mapbox.com` and `mapbox://` are fully removed.
* Every map is created through `nxCreateMap()` (`assets/js/nx-map.js`), which
  resizes after load (fixes blank maps in hidden tabs), shows an error banner,
  and falls back automatically.

### D2. Why admin and customer always match
Both sides read the **same SQLite database**; there is no second copy or cache.

```
Admin writes → shipments / tracking_controls / waypoints / route_points / events / holds / media / proof
                                                                   │
Customer track.php ◄── every 8 s ── track-api.php ◄───────────────┘
                                      │
                                      └─ ticks the movement Engine on every poll
```

* **New shipment** → visible at once.
* **Route/waypoint change** → visible after **Rebuild route line**.
* **Status / speed / pause / resume / hold** → reflection ≤ 8 s.
* **Movement** → continues **without the admin dashboard open**, because the
  customer's polling advances the engine.

### D3. Customer flow
1. Homepage → enter a tracking number.
2. First visit → `pre-track.php`; must enter the **email OR phone on file**.
   No contact on file? Access is allowed and flagged **Unverified**.
3. Verified → 30-day cookie; `track.php` loads the map and polls every 8 s.
4. `track-api.php` refuses unverified callers with HTTP 403.

---

## Part E — Feature reference (what was added)

| Feature | Where | Notes |
|---|---|---|
| Secure customer verification | `pre-track.php`, `track-api.php` | Email OR phone must match; `track-api` requires the session; no-contact shipments are "Unverified". |
| Remember-me | cookie `nx_t_*` | 30 days; single confirmation per device. |
| Customer-driven movement | `track-api.php` | Shipments move with the dashboard closed. |
| Attractive links | `?id=TRACKING` | `?tracking=` still supported. |
| Follow camera | customer map 🎯 Follow | Keeps the camera on the vehicle. |
| Live ETA | customer page + HUD | Remaining distance ÷ speed. |
| Receipt | `receipt.php` | Print / save as PDF. |
| QR + copy link | customer page | Vendored QR library. |
| Replay journey | customer page | Animates `tracking_positions`. |
| Analytics widget | dashboard | Total, active, delivered this week, delayed/held. |
| Audit log | `audit-log.php` | Every admin action. |
| DB backup | `backup.php` | `VACUUM INTO` download. |
| PWA | `manifest.webmanifest`, `service-worker.js` | Installable, offline shell. |
| Customer directory | `customers.php` | Links `shipments.customer_id`. |
| Proof of delivery | `proof-of-delivery.php` | Photo + signature; shown to customer. |
| Carrier display | customer page | "Handled by …". |
| Segment messages | customer map | Click a route segment. |
| Ambient speed control | Settings | Off/Slow/Normal/Fast, global. |
| Auto-continue waypoints | `Engine.php` | Shipments no longer pause at each stop. |
| Chatbot | `api/chat.php` | DeepSeek if key set, else live-data responder. |

---

## Part F — Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Blank map | `nx-map.js` should fall back automatically. Check the browser console; ensure the phone can reach `tiles.openfreemap.org`. |
| Customer can't see the map | They must pass `pre-track.php` with the shipment's email or phone. Use **View as Customer** to preview as admin. |
| Shipment doesn't move | `tracking_controls.is_running = 0`, speed is 0, or a mandatory hold is active. Movement is now driven by customer polls too, but a held/non-running shipment stays put. |
| `?id=` link shows the form only | That tracking number has no shipment, or the customer needs to confirm details first. |
| Reset email not received | The host has no `mail()` transport; use the on-screen one-time link (or `storage/mail.log`). Set `PASSWORD_RESET_SHOW_LINK=false` once real mail works. |
| Unstyled pages | Start with `./serve.sh` (`-t public`). |
| DB errors | Ensure `database/database.sqlite` exists and `pdo_sqlite` is enabled. |

---

## Part G — Known limitations

* **No satellite imagery** — OpenFreeMap is a vector street map. The automatic
  fallback uses OpenStreetMap raster (also not satellite).
* **Password reset depends on `mail()`** — on hosts without an MTA the one-time
  link is shown on screen / logged locally.
* **Chatbot AI requires a DeepSeek key** — without it, the built-in database
  responder answers status/ETA/route/hold questions.
* **Ambient traffic is decorative** — it is not connected to real vehicles.
* **Single-server SQLite** — intended for one local/Termux instance, not
  multi-server scale.
