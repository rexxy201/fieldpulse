<?php
require_once __DIR__ . '/../config.php';
requireAuth();
requirePermission('inventory.zoho.export');

$format = $_GET['format'] ?? 'zoho';

if ($format === 'zoho') {
    // Zoho Inventory CSV import format
    // Reference: https://www.zoho.com/inventory/help/items/import-items.html
    $items = dbFetchAll(
        "SELECT i.*, c.name AS category_name, v.name AS vendor_name
         FROM inv_items i
         LEFT JOIN inv_categories c ON c.id = i.category_id
         LEFT JOIN vendors v ON v.id = i.vendor_id
         ORDER BY i.name"
    );

    $filename = 'zoho-inventory-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');

    // Zoho Inventory item import columns
    fputcsv($out, [
        'Item Name',
        'SKU',
        'Item Type',
        'Unit',
        'Category',
        'Description',
        'Purchase Price',
        'Sales Price',
        'Preferred Vendor',
        'Reorder Point',
        'Initial Stock',
        'Zoho Item ID',
    ]);

    foreach ($items as $item) {
        $itemType = match($item['item_type'] ?? 'inventory') {
            'service'       => 'Service',
            'non_inventory' => 'Non-Inventory',
            default         => 'Inventory',
        };
        fputcsv($out, [
            $item['name'],
            $item['sku'] ?? $item['unique_code'] ?? '',
            $itemType,
            $item['unit'] ?? 'Pcs',
            $item['category_name'] ?? '',
            $item['description'] ?? '',
            $item['purchase_price'] !== null ? number_format((float)$item['purchase_price'], 2, '.', '') : '',
            $item['selling_price']  !== null ? number_format((float)$item['selling_price'],  2, '.', '') : '',
            $item['vendor_name'] ?? '',
            $item['reorder_threshold'] ?? '',
            $item['quantity'] ?? 0,
            $item['zoho_item_id'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}

jsonResponse(['error' => 'Unknown format'], 400);
