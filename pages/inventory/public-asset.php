<?php
require_once __DIR__ . '/../../config.php';
// Public QR-landing page — no auth.

$parts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$id = (int)($parts[1] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Asset not found.'); }

$asset = dbFetch("SELECT p.*, c.name AS cat_name FROM inv_products p LEFT JOIN inv_categories c ON c.id=p.category_id WHERE p.id=?", [$id]);
if (!$asset) { http_response_code(404); exit('Asset not found.'); }

$cabinets = dbFetchAll("SELECT cb.id,cb.name,cb.description FROM inv_cabinet_items ci JOIN inv_cabinets cb ON cb.id=ci.cabinet_id WHERE ci.product_id=? ORDER BY cb.name", [$id]);

$cfg     = getAppConfig();
$company = $cfg['companyName'] ?? 'FieldPulse';
$primary = $cfg['primaryColor'] ?? '#0ea5e9';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($asset['name']) ?> · <?= htmlspecialchars($company) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--primary:<?= htmlspecialchars($primary) ?>;}
body{font-family:'Inter',sans-serif;background:#f1f5f9;}
.navbar-pub{background:#0f172a;}
.brand-dot{color:var(--primary);}
.uid-badge{background:#0f172a;color:var(--primary);font-weight:700;font-size:.8rem;padding:.3rem .6rem;border-radius:.4rem;font-family:monospace;}
.cab-link{background:#0f172a;color:#fff;border-radius:.5rem;padding:.6rem .9rem;text-decoration:none;display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem;}
.cab-link:hover{background:#1e293b;color:#fff;}
</style>
</head>
<body>
<nav class="navbar navbar-pub py-3"><div class="container"><span class="navbar-brand text-white fw-bold"><i class="bi bi-box-seam brand-dot me-1"></i><?= htmlspecialchars($company) ?></span><span class="text-secondary small text-uppercase" style="letter-spacing:2px">Inventory</span></div></nav>

<div class="container py-4" style="max-width:620px">
  <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <?php if (!empty($asset['image']) && file_exists(INV_UPLOAD_DIR.$asset['image'])): ?>
    <div style="background:#f8fafc"><img src="/uploads/products/<?= htmlspecialchars($asset['image']) ?>" style="width:100%;max-height:280px;object-fit:contain;padding:1rem"></div>
    <?php else: ?>
    <div style="height:170px;background:#f8fafc;display:flex;align-items:center;justify-content:center;font-size:3.5rem;color:#cbd5e1"><i class="bi bi-pc-display"></i></div>
    <?php endif; ?>
    <div class="card-body p-4">
      <div class="d-flex gap-2 mb-2 flex-wrap">
        <?php if ($asset['unique_code']): ?><span class="uid-badge"><i class="bi bi-upc-scan me-1"></i><?= htmlspecialchars($asset['unique_code']) ?></span><?php endif; ?>
        <?php if ($asset['cat_name']): ?><span class="badge text-bg-warning align-self-center"><?= htmlspecialchars($asset['cat_name']) ?></span><?php endif; ?>
      </div>
      <h1 class="fw-bold h3 mb-3"><?= htmlspecialchars($asset['name']) ?></h1>
      <?php if ($asset['description']): ?><p class="text-secondary" style="white-space:pre-wrap"><?= htmlspecialchars($asset['description']) ?></p><?php endif; ?>

      <?php if ($cabinets): ?>
      <div class="small fw-semibold text-uppercase text-muted mt-4 mb-2"><i class="bi bi-archive me-1" style="color:var(--primary)"></i>Located in</div>
      <?php foreach ($cabinets as $cab): ?>
      <a href="/cabinet/<?= $cab['id'] ?>" class="cab-link">
        <i class="bi bi-archive" style="color:var(--primary)"></i>
        <span class="flex-grow-1"><?= htmlspecialchars($cab['name']) ?></span>
        <i class="bi bi-chevron-right small text-secondary"></i>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>

      <div class="row mt-4 pt-3 border-top g-3">
        <div class="col"><div class="small text-muted text-uppercase">Asset ID</div><div class="fw-semibold"><?= $asset['unique_code'] ? htmlspecialchars($asset['unique_code']) : '#'.$id ?></div></div>
        <div class="col"><div class="small text-muted text-uppercase">Added</div><div class="fw-semibold"><?= date('d M Y', strtotime($asset['created_at'])) ?></div></div>
      </div>
    </div>
  </div>

  <?php if (!empty($asset['qr_code']) && file_exists(INV_QR_DIR.$asset['qr_code'])): ?>
  <div class="card border-0 shadow-sm rounded-4 mt-3"><div class="card-body d-flex align-items-center gap-3">
    <img src="/uploads/qrcodes/<?= htmlspecialchars($asset['qr_code']) ?>" style="width:80px;height:80px;border:1px solid #e2e8f0;border-radius:.5rem">
    <div><div class="fw-semibold">QR Code</div><div class="small text-muted mb-2">Scan to return to this page anytime.</div>
      <a href="/uploads/qrcodes/<?= htmlspecialchars($asset['qr_code']) ?>" download class="btn btn-sm btn-primary"><i class="bi bi-download me-1"></i>Download</a></div>
  </div></div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
