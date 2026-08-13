<?php
// Run once, immediately after deploy: it prints the export key and locks itself.
// The key cannot ship in the repo because the repo is public, and nobody here
// has shell access to the server to put it there by hand. So the first person
// to load this page after deploy gets the key, and everyone after gets nothing.
//
// ponytail: first-hit-wins. Fine because the URL is unknown until you deploy and
// you open it seconds later. If that ever stops being true, delete setup.php and
// paste a key into data/key.php over SFTP instead.

declare(strict_types=1);

require __DIR__ . '/store.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (read_key() !== '') {
  http_response_code(409);
  echo "Already set up. The export key was shown once, when this page was first opened.\n";
  echo "Lost it? Delete data/key.php on the server, then load this page again.\n";
  exit;
}

$key = bin2hex(random_bytes(24));
if (!write_key($key)) {
  http_response_code(500);
  echo "Could not write to the data directory.\n";
  echo "Apache needs write permission on: " . DATA_DIR . "\n";
  exit;
}

echo "Export key, shown once. Save it now.\n\n";
echo "  $key\n\n";
echo "Download submissions with:\n";
echo "  export.php?key=$key\n";
