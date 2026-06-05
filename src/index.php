<?php

/*
 * Project Name: AI Agent
 * Entry Point  : receives HTTP POST, runs agent, returns JSON
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

header('Content-Type: application/json');

// ✅ Single line replaces all individual requires
require_once __DIR__ . '/bootstrap.php';

//! Get a request header value.
function _get_header(string $name): ?string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return $_SERVER[$key] ?? null;
}


//* Parse a request body, decrypting if secure.
function request_body(): array {
    // Read raw body once
    $raw = file_get_contents('php://input');
    if ($raw === false) { $raw = ''; }

    $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');

    // 1) JSON payload
    if (stripos($contentType, 'application/json') !== false) {
        if ($raw === '') {
            // empty JSON body
            return [];
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // You can use your existing response_error() helper or throw/return []
            response_error(400, 'invalid json');
            exit;
        }
        return is_array($data) ? $data : [];
    }

    // 2) Form data or multipart form (files in $_FILES)
    if (stripos($contentType, 'application/x-www-form-urlencoded') !== false
        || stripos($contentType, 'multipart/form-data') !== false) {

        // Merge $_POST and file info (if you want files as metadata)
        $result = $_POST;

        // Optionally add file metadata (do NOT include file contents automatically)
        if (!empty($_FILES)) {
            foreach ($_FILES as $key => $fileInfo) {
                $result[$key] = $fileInfo; // contains name, type, tmp_name, error, size
            }
        }
        return $result;
    }

    // 3) Fallback: try JSON decode of raw if possible
    if ($raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }

    // 4) Last fallback: return $_POST (may be empty)
    return $_POST ?: [];
}

//* Parse request
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!preg_match('#/(v[12])/(.+)$#', $uriPath, $m)) {
  response_error(404, 'Not Found');
  exit;
}

$version = $m[1]; // 'v1' or 'v2'
$path    = $m[2]; // e.g., 'auth/otp/send'

/* ---------------- * End of Request Parsing * ----------------  */

//* Route request to appropriate handler
try {
  route($version, $path, $method);
} catch (Throwable $e) {
  response_error(500, 'Server error', ['detail' => $e->getMessage()]);
}

function route(string $version, string $path, string $method): void {

      if ($path === 'decode' && ($method === 'GET' || $method === 'POST')) {
        $result = array();
        $body = request_body();
        response_ok($body, 200, $body);
        return;
      }

      if ($path === 'chat' && ($method === 'GET' || $method === 'POST')) {
        $result = array();
        $body = request_body();
        $response = runAgent($body);
        response_ok($response, 200, $body);
        return;
      }

      if ($path === 'history' && ($method === 'GET' || $method === 'POST')) {
        $result = array();
        $body = request_body();
        $response = history($body);
        response_ok($response, 200, $body);
        return;
      }

      if ($path === 'clearhistory' && ($method === 'GET' || $method === 'POST')) {
        $result = array();
        $body = request_body();
        $response = clearhistory($body);
        response_ok($response);
        return;
      }

      if ($path === 'profile' && ($method === 'GET' || $method === 'POST')) {
        $result = array();
        $body = request_body();
        $response = getProfile($body);
        response_ok($response, 200, $body);
        return;
      }

      /* ChatGPT */
      if ($path === 'generateimage' && ($method === 'POST' || $method === 'GET')) {
        $body = request_body();
        $response = generateImage($body);
        response_ok($response, 200, $body);
        return;
      }
}

/*  */
/* $input     = json_decode(file_get_contents("php://input"), true);
$code      = $input['code']      ?? null;
$message   = $input['message']   ?? '';
$selection = $input['selection'] ?? null;

if (!$code || !$message) {
    echo json_encode(["success" => false, "error" => "Invalid input"]);
    exit;
}

$response = runAgent($code, $message, $selection);
response_ok($response);
return; */
