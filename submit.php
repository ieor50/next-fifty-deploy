<?php
// POST target for the form. Same contract as the Vercel route it replaces:
// JSON in, {ok:true} or {error:"..."} out, so index.html did not change shape.

declare(strict_types=1);

require __DIR__ . '/vision.php';
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Allow: POST');
  http_response_code(405);
  echo json_encode(['error' => 'Method not allowed.']);
  exit;
}

// The form sends application/json, so $_POST is empty by design.
$raw = file_get_contents('php://input');
// A body this big is not a real submission: the fields cap at 500 characters
// and three entries. Refuse before spending memory on json_decode.
if (strlen((string)$raw) > 64 * 1024) {
  http_response_code(413);
  echo json_encode(['error' => 'That is larger than we can accept.']);
  exit;
}

$body = json_decode((string)$raw, true);
$result = validate(is_array($body) ? $body : []);

if (!$result['ok']) {
  http_response_code($result['status']);
  echo json_encode(['error' => $result['error']], JSON_UNESCAPED_UNICODE);
  exit;
}

// Checked after validation, not before, so a student fumbling a required field
// never burns their own quota. Only submissions good enough to be stored count.
if (rate_limited()) {
  http_response_code(429);
  header('Retry-After: 3600');
  echo json_encode(['error' => 'That is a lot of visions from one place. Try again in an hour.']);
  exit;
}

if (!store_record($result['record'])) {
  error_log('next-fifty: could not write submission to ' . DATA_DIR);
  http_response_code(502);
  echo json_encode(['error' => 'We could not save that just now.']);
  exit;
}

echo json_encode(['ok' => true]);
