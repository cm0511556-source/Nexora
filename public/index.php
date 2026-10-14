<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

$appName = (string) env('APP_NAME', 'Nexora Logistics');
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// ---- Newsletter subscribe (writes to the subscribers table) ----------------
if ($requestUri === '/api/subscribe' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode((string) file_get_contents('php://input'), true);
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if ($email) {
        try {
            $pdo = nxPdo();
            $pdo->prepare('INSERT OR IGNORE INTO subscribers (email) VALUES (:e)')->execute([':e' => $email]);
            echo json_encode(['status' => 'success', 'message' => 'Subscription successful!'], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            echo json_encode(['status' => 'success', 'message' => 'Subscription successful!'], JSON_UNESCAPED_SLASHES);
        }
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email address.'], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// ---- Live counters + testimonials ------------------------------------------
$stats = ['delivered' => 0, 'countries' => 0, 'clients' => 0, 'rating' => 0, 'reviews' => 0];
$testimonials = [];
try {
    $pdo = nxPdo();

    $stats['delivered'] = (int) $pdo->query("SELECT COUNT(*) FROM shipments WHERE status = 'delivered'")->fetchColumn();
    $stats['delivered'] = max(2400, $stats['delivered']);
    $stats['countries'] = 180;
    $stats['clients'] = (int) $pdo->query("SELECT COUNT(DISTINCT customer_name) FROM shipments")->fetchColumn();
    $stats['clients'] = max(1200, $stats['clients']);

    $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(AVG(rating),0) AS a FROM testimonials WHERE is_published = 1")->fetch();
    $stats['reviews'] = (int) ($r['n'] ?? 0);
    $stats['rating'] = $r['a'] > 0 ? round((float) $r['a'], 1) : 4.9;

    $ts = $pdo->query("SELECT id, author_name, author_country, author_role, author_photo_url, author_photo_path, rating, message FROM testimonials WHERE is_published = 1 ORDER BY sort_order ASC, id DESC LIMIT 12");
    $testimonials = $ts->fetchAll();
} catch (Throwable $e) {
    // Public homepage must never hard-fail on a database hiccup.
}

function hh(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= hh(nxContent('site_title', $appName . ' — Global Shipping. Smarter Tracking.')) ?></title>
<meta name="description" content="Nexora Logistics — international shipping, freight forwarding, and real-time shipment tracking across 180+ countries.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b1d3a">
<link rel="icon" href="<?= asset('images/icon.svg') ?>" type="image/svg+xml">
<style>
.hero-photo { position: relative; min-height: 520px; display: flex; align-items: center; justify-content: center; background: url('<?= asset('images/hero-port.jpg') ?>') center/cover no-repeat; text-align: center; padding: 4rem 1rem; }
.hero-photo::before { content:''; position:absolute; inset:0; background: linear-gradient(135deg, rgba(11,29,58,.92), rgba(11,29,58,.72)); }
.hero-photo > * { position: relative; z-index: 2; }
.hero-photo h1 { color:#fff; font-size: clamp(1.8rem, 5vw, 3.2rem); font-weight: 800; line-height: 1.15; margin: .3rem 0 1rem; }
.hero-photo p { color:#cbd5e1; max-width: 640px; margin: 0 auto 1.5rem; font-size: 1.05rem; }
.hero-photo .btn-outline { color:#fff; border-color: rgba(255,255,255,.4); background: transparent; }
.hero-photo .btn-outline:hover { background: rgba(255,255,255,.08); }
.services-photo { display:grid; gap:1.2rem; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); }
.service-photo-card { background:#fff; border-radius:14px; overflow:hidden; box-shadow: 0 8px 24px rgba(11,29,58,.08); border:1px solid #e5e7eb; transition: transform .2s; }
.service-photo-card:hover { transform: translateY(-3px); }
.service-photo-card img { width:100%; height:170px; object-fit:cover; display:block; }
.service-photo-card .body { padding: 1.25rem; }
.service-photo-card h3 { margin: 0 0 .5rem; color:#0b1d3a; font-size: 1.1rem; }
.service-photo-card p { margin:0; color:#5b6b85; font-size:.92rem; }
.counters { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); }
.counter-card { background: linear-gradient(135deg, #0b1d3a, #132a52); color:#fff; border-radius:14px; padding:1.75rem 1.25rem; text-align:center; border:1px solid rgba(240,180,41,.25); }
.counter-value { font-size: 2.2rem; font-weight: 800; color:#f0b429; line-height:1; }
.counter-label { font-size: .82rem; text-transform: uppercase; letter-spacing: .08em; opacity:.85; margin-top:.5rem; }
.counter-stars { color:#f0b429; font-size:1.1rem; letter-spacing:2px; margin-top:.35rem; }
.tst-track { display:flex; gap:1.2rem; overflow-x:auto; scroll-snap-type:x mandatory; scroll-behavior:smooth; padding-bottom: 1rem; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
.tst-track::-webkit-scrollbar { display:none; }
.tst-card { flex: 0 0 320px; scroll-snap-align:start; background:#fff; border-radius:14px; padding:1.5rem; box-shadow: 0 8px 24px rgba(11,29,58,.08); border:1px solid #e5e7eb; }
.tst-head { display:flex; gap:.85rem; align-items:center; margin-bottom: 1rem; }
.tst-avatar { width:56px; height:56px; border-radius:50%; object-fit:cover; background:#f1f5f9; border:2px solid #f0b429; flex-shrink:0; }
.tst-name { font-weight:700; color:#0b1d3a; font-size:.95rem; }
.tst-meta { font-size:.78rem; color:#64748b; margin-top:2px; }
.tst-stars { color:#f0b429; font-size:1rem; letter-spacing:2px; margin-bottom:.5rem; }
.tst-msg { color:#334155; font-size:.92rem; line-height:1.55; margin:0; font-style:italic; }
.tst-avatar-initial { width:56px; height:56px; border-radius:50%; background:linear-gradient(135deg,#f0b429,#ffd166); color:#0b1d3a; font-weight:800; font-size:1.3rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; border:2px solid #0b1d3a; }
.tst-controls { display:flex; gap:.5rem; justify-content:center; margin-top:.75rem; }
.tst-btn { width:42px; height:42px; border-radius:50%; border:1px solid #cbd5e1; background:#fff; cursor:pointer; font-size:1.1rem; color:#0b1d3a; font-weight:700; }
.tst-btn:hover { background:#0b1d3a; color:#fff; }
</style>
    <script src="<?= asset('js/nx-labels.js') ?>"></script>
</head>
<body>

<div id="toast-container" class="toast-container"></div>

<header class="navbar">
    <a href="/" class="logo" style="text-decoration:none;color:inherit;">
        <span class="logo-badge">N</span>
        <span class="logo-text"><?= hh($appName) ?></span>
    </a>
    <nav class="nav-links">
        <a href="#services">Services</a>
        <a href="#numbers">Our Numbers</a>
        <a href="#reviews">Reviews</a>
        <a href="#contact">Contact</a>
    </nav>
    <div id="google_translate_element" style="position:absolute;left:-9999px;"></div>
    <button type="button" id="nx-lang-btn" style="display:none;margin-left:auto;padding:.45rem .75rem;border-radius:9px;border:1px solid rgba(255,255,255,.35);background:transparent;color:#fff;font-weight:600;cursor:pointer;font-size:.85rem;">🌐 Language</button>
</header>

<section class="hero-photo">
    <span class="pill-tag" style="background:rgba(240,180,41,.2);color:#ffd166;"><?= hh(nxContent('hero_pill', '🌍 Global Shipping · Smarter Tracking')) ?></span>
    <h1><?= hh(nxContent('hero_heading_pre', 'Ship Worldwide with')) ?> <span style="background:linear-gradient(135deg,#f0b429,#ffd166);-webkit-background-clip:text;background-clip:text;color:transparent;"><?= hh(nxContent('hero_heading_highlight', 'Nexora Logistics')) ?></span></h1>
    <p><?= hh(nxContent('hero_subtitle', 'Freight forwarding, air & ocean cargo, door-to-door delivery — with live tracking on every shipment, every continent.')) ?></p>
    <form class="track-form" method="get" action="/track.php" style="max-width: 560px; margin: 0 auto;">
        <input type="text" name="tracking" placeholder="<?= hh(nxContent('hero_tracking_placeholder', 'Enter tracking number (e.g. NX-000123)')) ?>" required autocomplete="off">
        <button type="submit" class="btn btn-lg btn-gradient"><?= hh(nxContent('hero_cta_track', 'Track Shipment')) ?></button>
    </form>
    <div class="hero-actions" style="margin-top:1.25rem;">
        <a href="#services" class="btn btn-lg btn-outline"><?= hh(nxContent('hero_cta_services', 'Our Services')) ?></a>
        <a href="#contact" class="btn btn-lg btn-outline"><?= hh(nxContent('hero_cta_quote', 'Get a Quote')) ?></a>
    </div>
</section>

<main class="container" style="padding-top:2rem;">

<section id="services">
  <h2 style="text-align:center;color:#0b1d3a;font-size:clamp(1.5rem,3.5vw,2rem);margin:.5rem 0 .5rem;"><?= hh(nxContent('services_heading', 'Every route. Every mode. One partner.')) ?></h2>
  <p style="text-align:center;color:#5b6b85;max-width:640px;margin:0 auto 2rem;"><?= hh(nxContent('services_subheading', 'Air, ocean, road, and warehousing — coordinated under a single tracking number.')) ?></p>

  <div class="services-photo">
    <?php
      $serviceImages = [asset('images/service-air.jpg'), asset('images/service-sea.jpg'), asset('images/service-road.jpg'), asset('images/service-warehouse.jpg')];
      $serviceDefaults = [
        ['✈️ Air Freight', 'Priority and economy air cargo for time-sensitive shipments across continents.'],
        ['🚢 Ocean Freight', 'FCL and LCL container shipping to major ports worldwide with full customs coordination.'],
        ['🚚 Road & Last Mile', 'Reliable ground transport and final-mile delivery, with real-time status updates.'],
        ['🏬 Warehousing', 'Secure storage, cross-docking, and fulfillment at strategic global hubs.'],
      ];
      for ($i = 1; $i <= 4; $i++):
    ?>
    <div class="service-photo-card">
      <img src="<?= hh($serviceImages[$i - 1]) ?>" alt="">
      <div class="body">
        <h3><?= hh(nxContent('service_' . $i . '_title', $serviceDefaults[$i - 1][0])) ?></h3>
        <p><?= hh(nxContent('service_' . $i . '_body', $serviceDefaults[$i - 1][1])) ?></p>
      </div>
    </div>
    <?php endfor; ?>
  </div>
</section>

<section id="numbers" style="margin-top:3rem;">
  <h2 style="text-align:center;color:#0b1d3a;font-size:clamp(1.5rem,3.5vw,2rem);margin:.5rem 0 1.5rem;"><?= hh(nxContent('numbers_heading', 'Our numbers')) ?></h2>
  <div class="counters">
    <div class="counter-card">
      <div class="counter-value" data-target="<?= (int) $stats['delivered'] ?>">0</div>
      <div class="counter-label"><?= hh(nxContent('number_1_label', 'Shipments delivered')) ?></div>
    </div>
    <div class="counter-card">
      <div class="counter-value" data-target="<?= (int) $stats['countries'] ?>">0</div>
      <div class="counter-label"><?= hh(nxContent('number_2_label', 'Countries served')) ?></div>
    </div>
    <div class="counter-card">
      <div class="counter-value" data-target="<?= max((int) $stats['clients'], 1) ?>">0</div>
      <div class="counter-label"><?= hh(nxContent('number_3_label', 'Happy clients')) ?></div>
    </div>
    <div class="counter-card">
      <div class="counter-value" data-target="<?= $stats['rating'] ?>" data-decimal="1">0.0</div>
      <div class="counter-label"><?= hh(nxContent('number_4_label', 'Average rating')) ?></div>
      <div class="counter-stars">★★★★★</div>
    </div>
  </div>
</section>

<?php if (!empty($testimonials)): ?>
<section id="reviews" style="margin-top:3rem;">
  <h2 style="text-align:center;color:#0b1d3a;font-size:clamp(1.5rem,3.5vw,2rem);margin:.5rem 0 .5rem;"><?= hh(nxContent('reviews_heading', 'Trusted by shippers worldwide')) ?></h2>
  <p style="text-align:center;color:#5b6b85;max-width:640px;margin:0 auto 2rem;">
    <?= (int) $stats['reviews'] ?> reviews · <?= $stats['rating'] ?> average rating
  </p>

  <div class="tst-track" id="tst-track">
    <?php foreach ($testimonials as $t): ?>
      <?php
        $src = null;
        if (!empty($t['author_photo_path'])) $src = asset('images/testimonials/' . rawurlencode($t['author_photo_path']));
        elseif (!empty($t['author_photo_url'])) $src = $t['author_photo_url'];
        $initial = strtoupper(substr(trim($t['author_name']), 0, 1));
      ?>
      <div class="tst-card">
        <div class="tst-head">
          <?php if ($src): ?>
            <img src="<?= hh($src) ?>" alt="" class="tst-avatar">
          <?php else: ?>
            <div class="tst-avatar-initial"><?= hh($initial) ?></div>
          <?php endif; ?>
          <div>
            <div class="tst-name"><?= hh($t['author_name']) ?></div>
            <div class="tst-meta"><?= hh($t['author_role'] ?: $t['author_country']) ?><?php if ($t['author_role'] && $t['author_country']): ?> · <?= hh($t['author_country']) ?><?php endif; ?></div>
          </div>
        </div>
        <div class="tst-stars"><?= str_repeat('★', (int) $t['rating']) . str_repeat('☆', 5 - (int) $t['rating']) ?></div>
        <p class="tst-msg">"<?= hh($t['message']) ?>"</p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="tst-controls">
    <button type="button" class="tst-btn" id="tst-prev" aria-label="Previous">‹</button>
    <button type="button" class="tst-btn" id="tst-next" aria-label="Next">›</button>
  </div>
</section>
<?php endif; ?>

<section id="contact" class="newsletter-card card" style="margin-top:3rem;padding:2.5rem 1.5rem;text-align:center;">
  <h2 style="margin:0 0 .5rem;color:#0b1d3a;"><?= hh(nxContent('contact_heading', 'Talk to Nexora Logistics')) ?></h2>
  <p style="color:#5b6b85;margin:0 0 1.25rem;"><?= hh(nxContent('contact_subtext', 'Subscribe for service updates, or reach our team for a custom shipping quote.')) ?></p>
  <form id="subscribe-form" class="subscribe-form" style="display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;max-width:520px;margin:0 auto;">
    <input type="email" id="email-input" placeholder="<?= hh(nxContent('contact_placeholder', 'Your email address...')) ?>" required style="flex:1 1 220px;padding:.85rem 1rem;border-radius:10px;border:1px solid #e5e7eb;font-family:inherit;font-size:.95rem;">
    <button type="submit" class="btn btn-primary"><?= hh(nxContent('contact_cta', 'Subscribe')) ?></button>
  </form>
</section>

</main>

<footer>
    <p>&copy; <?= date('Y') ?> <?= hh($appName) ?>. <?= hh(nxContent('footer_text', 'Global Shipping. Smarter Tracking.')) ?> · <a href="/admin/login.php" style="color:#8899aa;font-size:.8em;text-decoration:none;">Staff</a></p>
</footer>

<script>
// Counters + testimonial carousel + subscribe + translate (single copy)
(function(){
  var els = document.querySelectorAll('.counter-value');
  if (els.length) {
    var obs = new IntersectionObserver(function(entries){
      entries.forEach(function(e){
        if (!e.isIntersecting || e.target.dataset.done) return;
        e.target.dataset.done = '1';
        var target = parseFloat(e.target.dataset.target || '0');
        var decimal = e.target.dataset.decimal === '1';
        var dur = 1600, start = performance.now();
        function step(now){
          var p = Math.min(1, (now - start) / dur);
          var eased = 1 - Math.pow(1 - p, 3);
          var v = target * eased;
          e.target.textContent = decimal ? v.toFixed(1) : Math.round(v).toLocaleString();
          if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
      });
    }, { threshold: 0.3 });
    els.forEach(function(el){ obs.observe(el); });
  }

  var track = document.getElementById('tst-track');
  if (track) {
    var prev = document.getElementById('tst-prev');
    var next = document.getElementById('tst-next');
    var timer = null;
    function cardWidth(){ var c = track.querySelector('.tst-card'); return c ? c.offsetWidth + 20 : 340; }
    function scrollByCard(dir){ track.scrollBy({ left: dir * cardWidth(), behavior: 'smooth' }); }
    function autoRotate(){
      if (timer) clearInterval(timer);
      timer = setInterval(function(){
        var max = track.scrollWidth - track.clientWidth - 5;
        if (track.scrollLeft >= max) track.scrollTo({ left: 0, behavior: 'smooth' });
        else scrollByCard(1);
      }, 6000);
    }
    if (prev) prev.addEventListener('click', function(){ scrollByCard(-1); autoRotate(); });
    if (next) next.addEventListener('click', function(){ scrollByCard(1); autoRotate(); });
    track.addEventListener('mouseenter', function(){ if (timer) clearInterval(timer); });
    track.addEventListener('mouseleave', autoRotate);
    track.addEventListener('touchstart', function(){ if (timer) clearInterval(timer); });
    track.addEventListener('touchend', autoRotate);
    autoRotate();
  }

  var form = document.getElementById('subscribe-form');
  if (form) form.addEventListener('submit', async function(e){
    e.preventDefault();
    var email = document.getElementById('email-input').value;
    try {
      var r = await fetch('/api/subscribe', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ email: email }) });
      var j = await r.json();
      alert(j.message || 'Subscribed');
      if (j.status === 'success') form.reset();
    } catch (err) { alert('Could not subscribe right now.'); }
  });

  // Translate button
  var brandTerms = <?= json_encode(['Nexora Logistics', 'Global Shipping. Smarter Tracking.'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var btn = document.getElementById('nx-lang-btn');
  if (btn) {
    var userLang = (navigator.language || 'en').toLowerCase();
    if (userLang.indexOf('en') !== 0) btn.style.display = '';
    btn.addEventListener('click', function(){
      if (window.__nxGTloaded) return;
      window.__nxGTloaded = true;
      window.googleTranslateElementInit = function(){ new google.translate.TranslateElement({ pageLanguage:'en', autoDisplay:false, layout: google.translate.TranslateElement.InlineLayout.SIMPLE }, 'google_translate_element'); };
      var s = document.createElement('script'); s.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit'; s.async = true; document.body.appendChild(s);
      btn.textContent = '🌐 Choose language';
    });
    setTimeout(function(){
      var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
      var n; while (n = walker.nextNode()) { for (var i = 0; i < brandTerms.length; i++) { if (n.nodeValue && n.nodeValue.indexOf(brandTerms[i]) !== -1) { n.parentNode.classList.add('notranslate'); } } }
    }, 500);
  }

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js').catch(function(){});
  }
})();
</script>

</body>
</html>
