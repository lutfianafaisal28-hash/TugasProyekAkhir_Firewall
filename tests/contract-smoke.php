<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    '/README.md',
    '/openapi.yaml',
    '/docker-compose.yml',
    '/contracts/events/sale-recorded.v1.json',
    '/app/public/index.php',
    '/services/inventory/consumer.php',
    '/services/procurement/export.php',
];
foreach ($required as $file) {
    if (!is_file($root . $file)) throw new RuntimeException("Missing {$file}");
}
$event = json_decode((string)file_get_contents($root . '/contracts/events/sale-recorded.v1.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['eventId','eventType','schemaVersion','occurredAt','saleId','currency','items'] as $key) {
    if (!in_array($key, $event['required'], true)) throw new RuntimeException("Event contract missing {$key}");
}
foreach (['/app/public/index.php','/services/inventory/consumer.php','/services/procurement/export.php'] as $file) {
    exec('php -l ' . escapeshellarg($root . $file), $output, $code);
    if ($code !== 0) throw new RuntimeException("PHP syntax error in {$file}");
}
echo "Contract smoke checks passed.\n";
