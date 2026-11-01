<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/..';
$file = $root . '/app/storage/inventory.json';
$inventory = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
$minimum = ['prod-beras-5kg'=>5,'prod-gula-1kg'=>5,'prod-minyak-1l'=>3];
$createdAt = gmdate('c');
$out = "product_id,current_stock,minimum_stock,suggested_quantity,created_at\n";
foreach ($minimum as $product => $min) {
    $stock = (int)($inventory[$product] ?? 0);
    if ($stock < $min) $out .= implode(',', [$product, $stock, $min, $min - $stock, $createdAt]) . "\n";
}
$dir = $root . '/app/storage/exports';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$path = $dir . '/procurement-' . gmdate('Ymd-His') . '.csv';
file_put_contents($path, $out, LOCK_EX);
echo "Procurement draft created: {$path}\n";
echo $out;
