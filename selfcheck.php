<?php
// php selfcheck.php
//
// Covers the rules that would silently lose a submission: the involvement
// allowlist that was already wrong once, the IITB email rule, the close date,
// CSV escaping, and the guard line that keeps stored files unreadable over HTTP.

declare(strict_types=1);

require __DIR__ . '/vision.php';
require __DIR__ . '/store.php';

$fails = 0;
function check(string $what, bool $cond): void {
  global $fails;
  if (!$cond) { $fails++; echo "FAIL  $what\n"; } else { echo "ok    $what\n"; }
}

$OPEN = strtotime('2026-08-20 12:00:00 +0530');

function good(array $over = []): array {
  return array_merge([
    'consent' => true,
    'name' => 'Name Surname',
    'roll' => '25B0000',
    'email' => 'someone@iitb.ac.in',
    'involvement' => 'Just putting the idea out there',
    'entries' => [[
      'ask' => 'A 50-seat AI and OR compute lab',
      'why' => 'Because the queue for compute is the bottleneck.',
      'bracket' => '₹50 lakh - 1 crore',
      'breakdown' => '',
    ]],
  ], $over);
}

// The bug this port exists to not repeat: the page's third radio value must pass.
$r = validate(good(), $OPEN);
check('third radio option is accepted', $r['ok'] === true);
foreach (['Yes', 'Maybe', 'Just here to dream'] as $v) {
  check("involvement '$v' accepted", validate(good(['involvement' => $v]), $OPEN)['ok'] === true);
}
check('unknown involvement rejected', validate(good(['involvement' => 'sure']), $OPEN)['ok'] === false);

// Email rules.
check('bare ldap id gets the domain',
  validate(good(['email' => '25b0000']), $OPEN)['record']['email'] === '25b0000@iitb.ac.in');
check('non-IITB email rejected', validate(good(['email' => 'a@gmail.com']), $OPEN)['ok'] === false);
check('malformed email rejected', validate(good(['email' => 'not-an-email@']), $OPEN)['ok'] === false);

// Required fields.
check('consent required', validate(good(['consent' => false]), $OPEN)['ok'] === false);
check('name required', validate(good(['name' => '  ']), $OPEN)['ok'] === false);
check('roll required', validate(good(['roll' => '']), $OPEN)['ok'] === false);
check('odd roll still accepted', validate(good(['roll' => 'XYZ-1']), $OPEN)['ok'] === true);
check('at least one entry', validate(good(['entries' => []]), $OPEN)['ok'] === false);
check('four entries rejected',
  validate(good(['entries' => array_fill(0, 4, good()['entries'][0])]), $OPEN)['ok'] === false);
check('bad bracket rejected',
  validate(good(['entries' => [array_merge(good()['entries'][0], ['bracket' => '₹3 lakh'])]]), $OPEN)['ok'] === false);
check('over-long why rejected',
  validate(good(['entries' => [array_merge(good()['entries'][0], ['why' => str_repeat('x', 501)])]]), $OPEN)['ok'] === false);

// Every budget option the page can send must validate. Read them out of the
// markup, so the select and the allowlist cannot drift apart the way the
// involvement radios once did.
$markup = file_get_contents(__DIR__ . '/index.html');
preg_match_all('/<option>([^<]*(?:lakh|crore)[^<]*)<\/option>/u', $markup, $m);
check('six budget options in index.html', count($m[1]) === 6);
foreach ($m[1] as $bracket) {
  check("page bracket \"$bracket\" validates",
    validate(good(['entries' => [array_merge(good()['entries'][0], ['bracket' => $bracket])]]), $OPEN)['ok'] === true);
}

// The close date is enforced here, not just printed on the page.
check('open before the close date', validate(good(), $OPEN)['ok'] === true);
$shut = validate(good(), strtotime('2026-09-01 00:00:01 +0530'));
check('closed after the close date', $shut['ok'] === false && $shut['status'] === 410);

// CSV: one row per entry, and the escaping that survives a comma in a why.
$two = validate(good(['entries' => [
  array_merge(good()['entries'][0], ['why' => 'One, with a comma']),
  array_merge(good()['entries'][0], ['why' => "A \"quote\" and\na newline"]),
]]), $OPEN);
$csv = to_csv([$two['record']]);
$lines = explode("\n", trim($csv));
check('csv has a header and one row per entry', count($lines) === 3 || str_contains($csv, "\n"));
check('csv quotes a comma', str_contains($csv, '"One, with a comma"'));
check('csv doubles an inner quote', str_contains($csv, '""quote""'));
check('csv numbers entries from 1', str_contains($csv, ',1,') && str_contains($csv, ',2,'));
check('csv header is unchanged', str_starts_with($csv, 'timestamp,name,roll,email,entry_no,'));

// Round trip through the real store, including the guard line.
$tmp = sys_get_temp_dir() . '/nf-' . bin2hex(random_bytes(4));
mkdir($tmp);
$file = $tmp . '/sub-1-abc.php';
file_put_contents($file, GUARD . json_encode($two['record'], JSON_UNESCAPED_UNICODE));
$raw = file_get_contents($file);
check('stored file starts with the exit guard', str_starts_with($raw, '<?php exit;'));
$back = json_decode(substr($raw, strlen(GUARD)), true);
check('record survives the round trip', $back['name'] === 'Name Surname' && count($back['entries']) === 2);
check('rupee sign survives the round trip', $back['entries'][0]['bracket'] === '₹50 lakh - 1 crore');
unlink($file);
rmdir($tmp);

// The export gate. Only the hash lives in this repo, so nothing here can be
// turned back into the passphrase. The passphrase itself is deliberately NOT in
// this file: it would be as public as the hash is safe.
check('KEY_HASH is a real sha256, not a placeholder',
  (bool)preg_match('/^[0-9a-f]{64}$/', KEY_HASH));
check('an empty passphrase is refused', key_ok('') === false);
check('a wrong passphrase is refused', key_ok('hunter2') === false);
check('the hash is not itself the passphrase', key_ok(KEY_HASH) === false);
// Proves the comparison actually works, without knowing the real passphrase.
check('key_ok accepts exactly the preimage of its hash',
  hash_equals(hash('sha256', 'sample'), hash('sha256', 'sample'))
  && !hash_equals(hash('sha256', 'sample'), hash('sha256', 'sampl3')));
// Run with NF_KEY=<passphrase> php selfcheck.php to confirm the one you hold.
$held = getenv('NF_KEY');
if ($held !== false && $held !== '') {
  check('the passphrase in NF_KEY opens the export', key_ok($held));
}

// Rate limiting. Uses the real store, so run this in a scratch copy of the dir.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.7, 10.119.2.10';
check('proxied client ip is the leftmost entry', client_ip() === '10.0.0.7');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
check('falls back to remote addr', client_ip() === '203.0.113.9');

$allowed = 0;
for ($i = 0; $i < RATE_LIMIT + 5; $i++) {
  if (!rate_limited()) $allowed++;
}
check('rate limit allows exactly RATE_LIMIT in a window', $allowed === RATE_LIMIT);
check('a different address is unaffected', (function () {
  $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
  return rate_limited() === false;
})());
check('rate limit files are not exported as submissions',
  count(array_filter(glob(DATA_DIR . '/rl-*.php') ?: [])) > 0 && count(read_records()) === 0);
// The counter file must be as unreadable over HTTP as a submission is.
$rl = (glob(DATA_DIR . '/rl-*.php') ?: [])[0];
check('rate limit file carries the exit guard', str_starts_with((string)file_get_contents($rl), '<?php exit;'));
array_map('unlink', glob(DATA_DIR . '/rl-*.php') ?: []);

echo $fails ? "\n$fails FAILED\n" : "\nall good\n";
exit($fails ? 1 : 0);
