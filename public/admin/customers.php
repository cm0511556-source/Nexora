<?php
declare(strict_types=1);

/**
 * Customer directory. Provides the master list that shipments link to via
 * shipments.customer_id, plus a "copy details" helper used by the shipment form.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once BASE_PATH . '/app/Database.php';
require_once BASE_PATH . '/app/Auth.php';

use VibrantHub\Auth;
use VibrantHub\Database;

Auth::requireAdmin();

$db = Database::connection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token.';
    } else {
        $op = (string) ($_POST['op'] ?? '');
        try {
            if ($op === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $address = trim((string) ($_POST['address'] ?? ''));

                if ($name === '') {
                    $error = 'Customer name is required.';
                } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Email address is not valid.';
                } else {
                    if ($id > 0) {
                        $db->prepare('UPDATE customers SET name=:n, email=:e, phone=:p, address=:a, updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                           ->execute([':n' => $name, ':e' => $email ?: null, ':p' => $phone ?: null, ':a' => $address ?: null, ':id' => $id]);
                        $message = 'Customer updated.';
                    } else {
                        $db->prepare('INSERT INTO customers (name, email, phone, address) VALUES (:n, :e, :p, :a)')
                           ->execute([':n' => $name, ':e' => $email ?: null, ':p' => $phone ?: null, ':a' => $address ?: null]);
                        $id = (int) $db->lastInsertId();
                        $message = 'Customer added.';
                    }
                    nxAudit('customer.save', 'customer', $id, $name);
                }
            } elseif ($op === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $db->prepare('UPDATE shipments SET customer_id = NULL WHERE customer_id = :id')->execute([':id' => $id]);
                $db->prepare('DELETE FROM customers WHERE id=:id')->execute([':id' => $id]);
                nxAudit('customer.delete', 'customer', $id);
                $message = 'Customer deleted. Their shipments were kept and unlinked.';
            }
        } catch (Throwable $e) {
            $error = 'Operation failed: ' . $e->getMessage();
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$edit = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $s = $db->prepare('SELECT * FROM customers WHERE id=:id LIMIT 1');
    $s->execute([':id' => $editId]);
    $edit = $s->fetch() ?: null;
}

if ($search !== '') {
    $stmt = $db->prepare('SELECT * FROM customers WHERE name LIKE :q OR email LIKE :q OR phone LIKE :q ORDER BY name LIMIT 200');
    $stmt->execute([':q' => '%' . $search . '%']);
    $customers = $stmt->fetchAll();
} else {
    $customers = $db->query('SELECT * FROM customers ORDER BY name LIMIT 200')->fetchAll();
}

// Shipment counts per customer
$counts = [];
foreach ($db->query('SELECT customer_id, COUNT(*) AS n FROM shipments WHERE customer_id IS NOT NULL GROUP BY customer_id') as $r) {
    $counts[(int) $r['customer_id']] = (int) $r['n'];
}

$csrf = csrfToken();
function hh(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customers — Nexora Logistics Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<style>
  body { background: #f1f5f9; }
  .wrap { max-width: 1100px; margin: 0 auto; padding: 1rem; }
  .panel { background:#fff; border-radius:14px; padding:1.5rem; box-shadow:0 8px 24px rgba(11,29,58,.08); margin-bottom:1.5rem; }
  .row { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:1rem; }
  label { display:block; font-size:.8rem; color:#475569; font-weight:600; margin-bottom:.35rem; text-transform:uppercase; letter-spacing:.04em; }
  input[type=text],input[type=email],input[type=tel]{ width:100%; padding:.65rem .85rem; border:1px solid #cbd5e1; border-radius:9px; font-family:inherit; font-size:.95rem; box-sizing:border-box; }
  input:focus{ outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.15); }
  .actions { margin-top:1rem; display:flex; gap:.6rem; flex-wrap:wrap; }
  table { width:100%; border-collapse:collapse; font-size:.92rem; }
  th, td { padding:.75rem .6rem; text-align:left; border-bottom:1px solid #e5e7eb; vertical-align:middle; }
  th { font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:#64748b; }
  .msg-ok{ padding:.75rem 1rem; background:rgba(22,163,74,.1); color:#166534; border-radius:10px; margin-bottom:1rem; }
  .msg-err{ padding:.75rem 1rem; background:rgba(220,38,38,.1); color:#991b1b; border-radius:10px; margin-bottom:1rem; }
  .btn-sm { padding:.4rem .75rem; font-size:.82rem; border-radius:8px; border:1px solid #cbd5e1; background:#fff; cursor:pointer; text-decoration:none; color:#0f172a; display:inline-block; }
  .btn-sm:hover{ background:#f1f5f9; }
  .btn-danger{ color:#991b1b; border-color:#fecaca; background:#fef2f2; }
  .badge { background:rgba(37,99,235,.12); color:#1e40af; padding:.15rem .5rem; border-radius:999px; font-size:.72rem; font-weight:700; }
</style>
    <script src="<?= asset('js/nx-labels.js') ?>"></script>
</head>
<body>
<header class="navbar">
  <a href="/admin/index.php" class="logo" style="text-decoration:none;color:inherit;">
    <span class="logo-badge">N</span><span class="logo-text">Nexora Admin</span>
  </a>
  <nav class="nav-links">
    <a href="/admin/index.php">Dashboard</a>
    <a href="/admin/shipments-hub.php">Shipments</a>
    <a href="/admin/people.php" class="active">People</a>
    <a href="/admin/content.php">Content</a>
    <a href="/admin/settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener" style="color:#ffd166 !important;">🌐 View Site</a>
    <a href="/admin/login.php?logout=1">Logout</a>
  </nav>
</header>

<div class="wrap">
  <h1 style="margin:1rem 0;">Customers</h1>
  <p style="color:#475569;margin-top:0;">Reusable customer records. Link a shipment to a customer and their details can be copied in one click.</p>

  <?php if ($message): ?><div class="msg-ok"><?= hh($message) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="msg-err"><?= hh($error) ?></div><?php endif; ?>

  <div class="panel">
    <h2 style="margin-top:0;"><?= $edit ? 'Edit customer' : 'Add customer' ?></h2>
    <form method="post" action="/admin/customers.php">
      <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <div class="row">
        <div><label>Name *</label><input type="text" name="name" required value="<?= hh($edit['name'] ?? '') ?>"></div>
        <div><label>Email</label><input type="email" name="email" value="<?= hh($edit['email'] ?? '') ?>"></div>
        <div><label>Phone</label><input type="tel" name="phone" value="<?= hh($edit['phone'] ?? '') ?>"></div>
        <div><label>Address</label><input type="text" name="address" value="<?= hh($edit['address'] ?? '') ?>"></div>
      </div>
      <div class="actions">
        <button type="submit" class="btn btn-primary"><?= $edit ? 'Save changes' : 'Add customer' ?></button>
        <?php if ($edit): ?><a href="/admin/customers.php" class="btn btn-outline" style="color:#0f172a;border-color:#cbd5e1;">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
      <h2 style="margin:0;">All customers (<?= count($customers) ?>)</h2>
      <form method="get" style="display:flex;gap:.5rem;">
        <input type="text" name="q" placeholder="Search name, email, phone…" value="<?= hh($search) ?>" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:9px;font-family:inherit;">
        <button type="submit" class="btn-sm">Search</button>
        <?php if ($search !== ''): ?><a class="btn-sm" href="/admin/customers.php">Clear</a><?php endif; ?>
      </form>
    </div>

    <?php if (empty($customers)): ?>
      <p style="color:#64748b;margin-top:1rem;">No customers yet. Add the first one above.</p>
    <?php else: ?>
    <div style="overflow-x:auto;margin-top:1rem;">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Shipments</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td><strong><?= hh($c['name']) ?></strong><?php if (!empty($c['address'])): ?><div style="color:#94a3b8;font-size:.8rem;"><?= hh($c['address']) ?></div><?php endif; ?></td>
            <td><?= hh($c['email']) ?></td>
            <td><?= hh($c['phone']) ?></td>
            <td><span class="badge"><?= (int) ($counts[(int) $c['id']] ?? 0) ?></span></td>
            <td style="white-space:nowrap;">
              <a href="/admin/customers.php?edit=<?= (int) $c['id'] ?>" class="btn-sm">Edit</a>
              <form method="post" style="display:inline;" onsubmit="return confirm('Delete this customer? Their shipments stay but become unlinked.');">
                <input type="hidden" name="csrf_token" value="<?= hh($csrf) ?>">
                <input type="hidden" name="op" value="delete">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
