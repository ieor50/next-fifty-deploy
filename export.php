<?php
// The whole sheet as CSV, one row per vision entry. This is the only way the
// data comes off a server we have no shell on, so it is the one thing that must
// not break.

declare(strict_types=1);

require __DIR__ . '/vision.php';
require __DIR__ . '/store.php';

if (!key_ok((string)($_GET['key'] ?? ''))) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "No.\n";
  exit;
}

$records = read_records();
$csv = to_csv($records);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="next-fifty-' . gmdate('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
// Excel reads a UTF-8 CSV as Latin-1 unless the file starts with a BOM, which
// turns every rupee sign in the budget column into mojibake. The BOM costs
// three bytes and saves that conversation.
echo "\xEF\xBB\xBF" . $csv;
