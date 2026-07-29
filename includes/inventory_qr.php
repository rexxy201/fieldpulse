<?php
/**
 * QR code generation for the Inventory module (PDO / FieldPulse edition).
 * Strategy: offline phpqrcode → cURL to qrserver → file_get_contents fallback.
 */

function invGenerateQr(string $url, string $filename): string|false {
    $path = INV_QR_DIR . $filename;
    if (!is_dir(INV_QR_DIR)) @mkdir(INV_QR_DIR, 0775, true);

    // 1) Offline phpqrcode if present
    $lib = __DIR__ . '/../vendor/phpqrcode/qrlib.php';
    if (file_exists($lib)) {
        require_once $lib;
        try {
            \QRcode::png($url, $path, QR_ECLEVEL_M, 7, 2);
            if (file_exists($path) && filesize($path) > 100) return $filename;
        } catch (\Throwable $e) { /* fall through */ }
    }

    // 2) cURL to api.qrserver.com
    $api = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($url);
    if (function_exists('curl_init')) {
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'FieldPulse/1.0',
        ]);
        $img  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($img && $code === 200 && strlen($img) > 100) {
            file_put_contents($path, $img);
            return $filename;
        }
    }

    // 3) file_get_contents fallback
    $img = @file_get_contents($api);
    if ($img !== false && strlen($img) > 100) {
        file_put_contents($path, $img);
        return $filename;
    }

    return false;
}

/** Ensure an asset QR exists; (re)generate if missing. */
function invEnsureAssetQr(int $id): void {
    $row = dbFetch("SELECT qr_code FROM inv_products WHERE id = ?", [$id]);
    if (!$row) return;
    $qf = 'qr_product_' . $id . '.png';
    if (empty($row['qr_code']) || !file_exists(INV_QR_DIR . $row['qr_code'])) {
        if (invGenerateQr(siteBaseUrl() . '/asset/' . $id, $qf)) {
            dbRun("UPDATE inv_products SET qr_code = ? WHERE id = ?", [$qf, $id]);
        }
    }
}
