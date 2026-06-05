<?php
declare(strict_types=1);

function request_response(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $raw  = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        response_error(400, 'Invalid JSON');
        exit;
    }
    $cached = $data;
    return $cached;
}

function response_ok($data, int $status = 200, ?array $requestBody = null): void {
    $userId = is_array($requestBody) ? (string)($requestBody['code'] ?? '') : '';
    http_response_code($status);
    api_log_response($data, $status, $userId);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function response_error(int $status, string $message, array $details = []): void {
    http_response_code($status);
    api_log_response($details, $status, null);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error'   => ['code' => $status, 'message' => $message, 'details' => $details]
    ], JSON_UNESCAPED_SLASHES);
}
