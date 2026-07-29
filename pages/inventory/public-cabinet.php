<?php
require_once __DIR__ . '/../../config.php';
// Public QR-landing page — no auth.

$parts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$id = (int)($parts[1] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Cabinet not found.'); }

$cab = dbFetch("SELECT * FROM inv_cabinets WHERE id=?", [$id]);
if (!$cab) { http_response_code(404); exit('Cabinet not found.'); }

$linked = dbFetchAll("SELECT p.id,p.name,p.unique_code,p.image FROM inv_cabinet_items ci JOIN inv_products p ON p.id=ci.product_id WHERE ci.cabinet_id=? ORDER BY p.name", [$id]);
$custom = dbFetchAll("SELECT label FROM inv_cabinet_custom_items WHERE cabinet_id=? ORDER BY created_at", [$id]);

$cfg     = getAppConfig();
$company = $cfg['companyName'] ?? 'FieldPulse';
$primary = $cfg['primaryColor'] ?? '#0ea5e9';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($cab['name']) ?> · <?= htmlspecialchars($company) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--primary:<?= htmlspecialchars($primary) ?>;}
body{font-family:'Inter',sans-serif;background:#f1f5f9;}
.navbar-pub{background:#0f172a;}
.brand-dot{color:var(--primary);}
.asset-link{background:#fff;border:1px solid #e2e8f0;border-radius:.6rem;padding:.7rem .9rem;text-decoration:none;color:inherit;display:flex;align-items:center;gap:.7rem;margin-bottom:.5rem;}
.asset-link:hover{border-color:var(--primary);}
</style>
</head>
<body>
<nav class="navbar navbar-pub py-3"><div class="container"><span class="navbar-brand text-white fw-bold"><i class="bi bi-box-seam brand-dot me-1"></i><?= htmlspecialchars($company) ?></span><span class="text-secondary small text-uppercase" style="letter-spacing:2px">Inventory</span></div></nav>

<div class="container py-4" style="max-width:620px">
  <div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-4">
      <div class="d-flex align-items-center gap-3 mb-3">
        <div style="width:48px;height:48px;border-radius:.7rem;background:rgba(14,165,233,.12);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:1.4rem"><i class="bi bi-archive"></i></div>
        <div><h1 class="fw-bold h4 mb-0"><?= htmlspecialchars($cab['name']) ?></h1><?php if ($cab['description']): ?><div class="text-muted small"><?= htmlspecialchars($cab['description']) ?></div><?php endif; ?></div>
      </div>

      <?php if ($custom): ?>
      <div class="small fw-semibold text-uppercase text-muted mt-4 mb-2">Items</div>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($custom as $ci): ?><span class="badge text-bg-light border fs-6 fw-normal py-2"><?= htmlspecialchars($ci['label']) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($linked): ?>
      <div class="small fw-semibold text-uppercase text-muted mt-4 mb-2">Linked Assets</div>
      <?php foreach ($linked as $a): ?>
      <a href="/asset/<?= $a['id'] ?>" class="asset-link">
        <?php if (!empty($a['image']) && file_exists(INV_UPLOAD_DIR.$a['image'])): ?>
        <img src="/uploads/products/<?= htmlspecialchars($a['image']) ?>" style="width:38px;height:38px;border-radius:.4rem;object-fit:cover">
        <?php else: ?>
        <div style="width:38px;height:38px;border-radius:.4rem;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8"><i class="bi bi-pc-display"></i></div>
        <?php endif; ?>
        <span class="flex-grow-1 fw-semibold"><?= htmlspecialchars($a['name']) ?></span>
        <?php if ($a['unique_code']): ?><span class="small font-monospace text-muted"><?= htmlspecialchars($a['unique_code']) ?></span><?php endif; ?>
        <i class="bi bi-chevron-right small text-secondary"></i>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if (!$linked && !$custom): ?>
      <div class="text-center text-muted py-4"><i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>This cabinet is empty.</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($cab['qr_code']) && file_exists(INV_QR_DIR.$cab['qr_code'])): ?>
  <div class="card border-0 shadow-sm rounded-4 mt-3"><div class="card-body d-flex align-items-center gap-3">
    <img src="/uploads/qrcodes/<?= htmlspecialchars($cab['qr_code']) ?>" style="width:80px;height:80px;border:1px solid #e2e8f0;border-radius:.5rem">
    <div><div class="fw-semibold">Cabinet QR</div><div class="small text-muted mb-2">Scan to view this cabinet's contents.</div>
      <a href="/uploads/qrcodes/<?= htmlspecialchars($cab['qr_code']) ?>" download class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i>Download</a></div>
  </div></div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
