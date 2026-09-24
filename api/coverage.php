<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Serve KML file (auth-gated, inline) ──────────────────────────────────────
if ($action === 'serve') {
    requirePermission('map.view');
    $id    = trim($_GET['id'] ?? '');
    $layer = dbFetch("SELECT * FROM coverage_layers WHERE id = ? AND enabled = 1", [$id]);
    if (!$layer) { http_response_code(404); exit; }
    $path = __DIR__ . '/../' . $layer['file_path'];
    if (!file_exists($path)) { http_response_code(404); exit; }
    header('Content-Type: application/vnd.google-earth.kml+xml; charset=UTF-8');
    header('Content-Disposition: inline; filename="coverage.kml"');
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// ── List enabled layers ───────────────────────────────────────────────────────
if ($action === 'list') {
    requirePermission('map.view');
    $layers = dbFetchAll(
        "SELECT cl.id, cl.name, cl.description, cl.color, cl.opacity, cl.hub_id,
                h.name AS hub_name
         FROM   coverage_layers cl
         LEFT JOIN hubs h ON h.id = cl.hub_id
         WHERE  cl.enabled = 1
         ORDER BY cl.name"
    );
    jsonResponse(['layers' => $layers]);
}

// ── Upload KMZ or KML ─────────────────────────────────────────────────────────
if ($action === 'upload') {
    requirePermission('map.coverage');
    if (method() !== 'POST') jsonResponse(['error' => 'POST required'], 405);
    verifyCsrf();

    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $hubId = trim($_POST['hub_id'] ?? '') ?: null;
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#3b82f6';

    if (!$name)                          jsonResponse(['error' => 'Name is required'], 400);
    if (empty($_FILES['kml_file']['tmp_name'])) jsonResponse(['error' => 'File is required'], 400);

    $tmpPath  = $_FILES['kml_file']['tmp_name'];
    $ext      = strtolower(pathinfo($_FILES['kml_file']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['kml', 'kmz'])) {
        jsonResponse(['error' => 'Only .kml and .kmz files are accepted'], 400);
    }

    $uploadDir = __DIR__ . '/../uploads/coverage/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        jsonResponse(['error' => 'Could not create upload directory'], 500);
    }

    $id      = newUuid();
    $outFile = $id . '.kml';
    $outPath = $uploadDir . $outFile;

    if ($ext === 'kmz') {
        if (!class_exists('ZipArchive')) {
            jsonResponse(['error' => 'ZipArchive not available on this server'], 500);
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) !== true) {
            jsonResponse(['error' => 'Could not open KMZ file'], 422);
        }
        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'kml') {
                file_put_contents($outPath, $zip->getFromIndex($i));
                $found = true;
                break;
            }
        }
        $zip->close();
        if (!$found) jsonResponse(['error' => 'No KML file found inside the KMZ'], 422);
    } else {
        if (!move_uploaded_file($tmpPath, $outPath)) {
            jsonResponse(['error' => 'File upload failed'], 500);
        }
    }

    dbRun(
        "INSERT INTO coverage_layers (id, name, description, file_path, hub_id, color, enabled, created_by)
         VALUES (?,?,?,?,?,?,1,?)",
        [$id, $name, $desc ?: null, 'uploads/coverage/' . $outFile, $hubId, $color, currentUser()['id']]
    );
    auditLog('create', 'coverage_layer', $id);
    jsonResponse(['ok' => true, 'id' => $id, 'name' => $name]);
}

// ── Delete layer ──────────────────────────────────────────────────────────────
if ($action === 'delete') {
    requirePermission('map.coverage');
    if (method() !== 'POST') jsonResponse(['error' => 'POST required'], 405);
    verifyCsrf();

    $id    = trim($_POST['id'] ?? '');
    $layer = dbFetch("SELECT * FROM coverage_layers WHERE id = ?", [$id]);
    if (!$layer) jsonResponse(['error' => 'Not found'], 404);

    $path = __DIR__ . '/../' . $layer['file_path'];
    if (file_exists($path)) @unlink($path);
    dbRun("DELETE FROM coverage_layers WHERE id = ?", [$id]);
    auditLog('delete', 'coverage_layer', $id);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unknown action'], 400);
