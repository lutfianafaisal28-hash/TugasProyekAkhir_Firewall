<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/..';
$dataDir = $root . '/app/storage';
$eventsFile = $dataDir . '/events.ndjson';
$inventoryFile = $dataDir . '/inventory.json';
$processedFile = $dataDir . '/processed-events.json';

if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);
$inventory = is_file($inventoryFile) ? (json_decode((string)file_get_contents($inventoryFile), true) ?: []) : [];
$processed = is_file($processedFile) ? (json_decode((string)file_get_contents($processedFile), true) ?: []) : [];
if (!is_file($eventsFile)) { echo "No events to process.\n"; exit(0); }

$lines = file($eventsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$count = 0;
foreach ($lines as $line) {
    $event = json_decode($line, true);
    if (!is_array($event) || ($event['eventType'] ?? '') !== 'SaleRecorded') continue;
    $eventId = $event['eventId'] ?? '';
    if ($eventId === '' || isset($processed[$eventId])) continue;
    foreach ($event['items'] ?? [] as $item) {
        $productId = (string)$item['productId'];
        $inventory[$productId] = ($inventory[$productId] ?? 0) - (int)$item['quantity'];
    }
    $processed[$eventId] = ['saleId'=>$event['saleId'] ?? null, 'processedAt'=>gmdate('c')];
    $count++;
}
file_put_contents($inventoryFile, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($processedFile, json_encode($processed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
echo "Processed {$count} event(s).\n";
foreach ($inventory as $product => $stock) echo "{$product}: {$stock}\n";
