<?php
// Where submissions live, and the two tricks that keep them private on a server
// nobody here administers.
//
// 1. data/.htaccess denies the whole directory. That is the normal answer, but
//    it silently does nothing if the vhost sets AllowOverride None, and we
//    cannot see the vhost.
// 2. So every stored file is named .php and starts with an exit line. If Apache
//    ever serves the directory anyway, PHP runs the file, exits, and returns an
//    empty body instead of a student's name and roll number. Reading the file
//    back from disk skips the first line.
//
// Belt and braces on purpose: one submission list is not worth a 50 line
// deploy conversation about Apache config we are not allowed to read.

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const GUARD = "<?php exit; ?>\n";

const HTACCESS = <<<'TXT'
# Nothing in here is meant to be fetched over HTTP.
Require all denied
Options -Indexes
TXT;

/** Creates the data directory and its guard file if either is missing. */
function ensure_store(): void {
  if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0750, true);
  }
  $ht = DATA_DIR . '/.htaccess';
  // Rewritten every run, not just when absent: a deploy that copies the folder
  // without dotfiles is the exact case this protects against, and it is a cheap
  // write on a form that takes a few hundred submissions in three weeks.
  if (!is_file($ht) || file_get_contents($ht) !== HTACCESS) {
    @file_put_contents($ht, HTACCESS);
  }
}

/** Appends one record. Nothing overwrites: duplicates are allowed by design. */
function store_record(array $record): bool {
  ensure_store();
  $name = sprintf('sub-%d-%s.php', time(), bin2hex(random_bytes(6)));
  $body = GUARD . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return @file_put_contents(DATA_DIR . '/' . $name, $body, LOCK_EX) !== false;
}

/** Every stored record, oldest first. */
function read_records(): array {
  $out = [];
  foreach (glob(DATA_DIR . '/sub-*.php') ?: [] as $path) {
    $raw = @file_get_contents($path);
    if ($raw === false) continue;
    $json = substr($raw, strlen(GUARD));
    $rec = json_decode($json, true);
    if (is_array($rec)) $out[] = $rec;
  }
  usort($out, fn($a, $b) => strcmp($a['at'] ?? '', $b['at'] ?? ''));
  return $out;
}

/** The export key, or '' if setup.php has not been run yet. */
function read_key(): string {
  $path = DATA_DIR . '/key.php';
  if (!is_file($path)) return '';
  return trim(substr((string)@file_get_contents($path), strlen(GUARD)));
}

function write_key(string $key): bool {
  ensure_store();
  return @file_put_contents(DATA_DIR . '/key.php', GUARD . $key, LOCK_EX) !== false;
}
