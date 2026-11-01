<?php
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../storage';
const SALES_FILE = DATA_DIR . '/sales.json';
const ORDERS_FILE = DATA_DIR . '/orders.json';
const EVENTS_FILE = DATA_DIR . '/events.ndjson';

function jsonResponse(array $body, int $status = 200, string $type = 'application/json'): never {
    http_response_code($status);
    header('Content-Type: ' . $type);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
function problem(string $title, int $status, string $detail, array $errors = []): never {
    $body = ['type'=>'https://example.invalid/problems/validation-error','title'=>$title,'status'=>$status,'detail'=>$detail,'instance'=>parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),'code'=>$status === 409 ? 'conflict' : 'validation-error'];
    if ($errors) $body['errors'] = $errors;
    jsonResponse($body, $status, 'application/problem+json');
}
function readJson(string $file): array {
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
function writeJson(string $file, array $data): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function input(): array {
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}
function id(string $prefix): string { return $prefix . '-' . bin2hex(random_bytes(8)); }
function validDate(mixed $value): bool { return is_string($value) && strtotime($value) !== false; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/api/v1/health') jsonResponse(['status'=>'ok','service'=>'pos-order']);

if ($method === 'POST' && $path === '/api/v1/sales') {
    $body = input(); $errors = [];
    if (!validDate($body['occurredAt'] ?? null)) $errors['occurredAt'] = ['Must be an ISO 8601 date-time.'];
    if (!is_array($body['items'] ?? null) || count($body['items']) < 1) $errors['items'] = ['At least one item is required.'];
    foreach (($body['items'] ?? []) as $i => $item) {
        if (!is_array($item) || !isset($item['productId'])) $errors["items.$i.productId"] = ['Product id is required.'];
        if (!is_int($item['quantity'] ?? null) || $item['quantity'] < 1) $errors["items.$i.quantity"] = ['Nilai harus lebih besar dari 0.'];
        if (!is_int($item['unitPrice'] ?? null) || $item['unitPrice'] < 0) $errors["items.$i.unitPrice"] = ['Must be a non-negative integer.'];
    }
    if (!is_array($body['payment'] ?? null) || !in_array($body['payment']['method'] ?? null, ['cash','credit'], true)) $errors['payment.method'] = ['Method must be cash or credit.'];
    if ($errors) problem('Permintaan tidak valid', 422, 'Request validation failed.', $errors);
    $total = array_sum(array_map(fn($x) => $x['quantity'] * $x['unitPrice'], $body['items']));
    $sale = ['saleId'=>id('sale'),'status'=>'recorded','totalAmount'=>$total,'createdAt'=>gmdate('c'),'occurredAt'=>$body['occurredAt'],'items'=>$body['items'],'payment'=>$body['payment']];
    $sales = readJson(SALES_FILE); $sales[] = $sale; writeJson(SALES_FILE, $sales);
    $event = ['eventId'=>id('event'),'eventType'=>'SaleRecorded','schemaVersion'=>1,'occurredAt'=>gmdate('c'),'saleId'=>$sale['saleId'],'currency'=>'IDR','items'=>$body['items']];
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true); file_put_contents(EVENTS_FILE, json_encode($event, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    jsonResponse($sale, 201);
}

if ($method === 'GET' && preg_match('#^/api/v1/sales/([^/]+)$#', $path, $m)) {
    foreach (readJson(SALES_FILE) as $sale) if ($sale['saleId'] === $m[1]) jsonResponse($sale);
    problem('Resource tidak ditemukan', 404, 'Sale was not found.');
}

if ($method === 'POST' && $path === '/api/v1/orders/whatsapp') {
    $body = input(); $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($body['externalMessageId'] ?? '');
    if ($key === '') problem('Permintaan tidak valid', 422, 'Idempotency-Key or externalMessageId is required.');
    $orders = readJson(ORDERS_FILE);
    foreach ($orders as $order) if ($order['idempotencyKey'] === $key) jsonResponse($order, 202);
    if (!validDate($body['receivedAt'] ?? null) || !is_array($body['items'] ?? null) || count($body['items']) < 1) problem('Permintaan tidak valid', 422, 'receivedAt and at least one item are required.');
    foreach ($body['items'] as $i => $item) if (!is_int($item['quantity'] ?? null) || $item['quantity'] < 1) problem('Permintaan tidak valid', 422, 'Quantity must be positive.', ["items.$i.quantity"=>['Must be greater than zero.']]);
    $order = ['orderId'=>id('order'),'status'=>'accepted','acceptedAt'=>gmdate('c'),'idempotencyKey'=>$key,'externalMessageId'=>$body['externalMessageId'] ?? $key,'receivedAt'=>$body['receivedAt'],'customerId'=>$body['customerId'] ?? null,'items'=>$body['items']];
    $orders[] = $order; writeJson(ORDERS_FILE, $orders); unset($order['idempotencyKey']); jsonResponse($order, 202);
}

problem('Resource tidak ditemukan', 404, 'Route was not found.');
