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

// How many submissions one address may send per hour.
//
// Deliberately loose. IITB campus wifi is NAT'd, so a whole lab can share one
// public address, and a class filling the form together after an announcement is
// the normal case, not the attack. Blocking real students is far worse than
// letting a script through, so this is set to stop a flood, not to be tidy.
// Raise it here if anyone is ever turned away.
const RATE_LIMIT = 30;
const RATE_WINDOW = 3600;

/** The client address, as best we can see it from behind a proxy. */
function client_ip(): string {
  // X-Forwarded-For is trivially spoofable, so using it weakens the throttle.
  // Using REMOTE_ADDR instead would be worse: if this server sits behind a
  // reverse proxy, every student appears as the proxy's single address and the
  // whole department shares one bucket. A throttle that can be sidestepped beats
  // a throttle that locks out the people it was built for.
  $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
  if ($fwd !== '') {
    $first = trim(explode(',', $fwd)[0]);
    if ($first !== '') return $first;
  }
  return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * True if this address has already used up its hour.
 *
 * Fails open on any bookkeeping error: a rate limiter that cannot write its own
 * counter must not be the reason a student loses their submission.
 *
 * ponytail: one file per address, last-write-wins under concurrency, so a burst
 * of simultaneous requests can slip a few over the line. Fine for a form that
 * takes a few hundred entries in three weeks. If it ever needs to be exact,
 * that is a database, and this form does not deserve one.
 */
function rate_limited(): bool {
  ensure_store();
  // Hashed, so the folder never holds a list of raw student IP addresses, and so
  // IPv6 colons cannot turn into odd filenames.
  $path = DATA_DIR . '/rl-' . substr(hash('sha256', client_ip()), 0, 16) . '.php';
  $now = time();
  $state = ['start' => $now, 'n' => 0];

  if (is_file($path)) {
    $prev = json_decode(substr((string)@file_get_contents($path), strlen(GUARD)), true);
    if (is_array($prev) && isset($prev['start'], $prev['n']) && $now - (int)$prev['start'] < RATE_WINDOW) {
      $state = ['start' => (int)$prev['start'], 'n' => (int)$prev['n']];
    }
  }

  if ($state['n'] >= RATE_LIMIT) return true;

  $state['n']++;
  @file_put_contents($path, GUARD . json_encode($state), LOCK_EX);
  return false;
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
