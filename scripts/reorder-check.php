<?php
/**
 * Reorder Point Alert — run daily via cPanel cron
 * 0 7 * * * /usr/local/bin/php /home/<user>/fieldpulse/scripts/reorder-check.php
 *
 * For each item where qty_available <= reorder_threshold (and threshold > 0),
 * creates a draft Purchase Order if no open PO already covers that item.
 * Sends an SMS alert if SMS is enabled.
 */

define('CLI_MODE', true);
require_once __DIR__ . '/../config.php';

$now  = date('Y-m-d H:i:s');
$date = date('Y-m-d');

echo "[{$now}] Reorder check started\n";

// Items below reorder threshold
$lowStock = dbFetchAll(
    "SELECT i.id, i.name, i.unique_code, i.qty_available, i.reorder_threshold, i.reorder_qty,
            i.vendor_id, i.purchase_price, i.unit
     FROM   inv_items i
     WHERE  i.reorder_threshold > 0
       AND  i.qty_available <= i.reorder_threshold
     ORDER BY i.name"
);

if (!$lowStock) {
    echo "[{$now}] No items below reorder threshold.\n";
    exit(0);
}

echo "[{$now}] " . count($lowStock) . " item(s) below threshold\n";

// Next PO number
function nextPoNumber(): string {
    $last = dbFetch("SELECT po_number FROM inv_purchase_orders ORDER BY created_at DESC LIMIT 1");
    if ($last) {
        preg_match('/PO-(\d{6})-(\d+)/', $last['po_number'], $m);
        $ym  = date('Ym');
        $seq = (isset($m[1]) && $m[1] === $ym) ? ((int)$m[2] + 1) : 1;
    } else {
        $ym  = date('Ym');
        $seq = 1;
    }
    return "PO-{$ym}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

$created = [];

foreach ($lowStock as $item) {
    // Check for existing open PO covering this item
    $openPo = dbFetch(
        "SELECT po.id, po.po_number FROM inv_purchase_orders po
         JOIN inv_po_items poi ON poi.po_id = po.id
         WHERE poi.item_id = ?
           AND po.status IN ('draft','sent','partial')",
        [$item['id']]
    );

    if ($openPo) {
        echo "  SKIP  {$item['name']} — already on open PO {$openPo['po_number']}\n";
        continue;
    }

    $poId  = newUuid();
    $poNum = nextPoNumber();
    $qty   = max(1, (int)$item['reorder_qty']);

    dbRun(
        "INSERT INTO inv_purchase_orders (id, po_number, vendor_id, status, ordered_at, notes, created_by, created_at)
         VALUES (?, ?, ?, 'draft', ?, ?, NULL, NOW())",
        [$poId, $poNum, $item['vendor_id'], $date,
         "Auto-created: {$item['name']} stock ({$item['qty_available']}) at or below reorder threshold ({$item['reorder_threshold']})"]
    );

    dbRun(
        "INSERT INTO inv_po_items (po_id, item_id, qty_ordered, qty_received, unit_price)
         VALUES (?, ?, ?, 0, ?)",
        [$poId, $item['id'], $qty, $item['purchase_price']]
    );

    $created[] = "{$item['name']} (stock: {$item['qty_available']}, PO qty: {$qty})";
    echo "  PO    {$poNum} created for {$item['name']}\n";
}

// SMS alert to admin if any POs were created
if ($created) {
    $cfg = getAppConfig();
    $adminPhone = $cfg['adminPhone'] ?? '';

    $msg = count($created) . ' low-stock alert(s) — draft POs created: ' . implode('; ', $created);
    if ($adminPhone) {
        $result = sendSms($adminPhone, $msg, 'sms');
        echo "[{$now}] SMS to admin: " . ($result['ok'] ? 'sent' : 'failed — ' . ($result['error'] ?? '')) . "\n";
    } else {
        echo "[{$now}] No adminPhone configured — skipping SMS.\n";
    }
}

echo "[{$now}] Reorder check complete. " . count($created) . " PO(s) created.\n";
