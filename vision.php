<?php
// Validation and CSV rules, shared by submit.php and export.php so the two can
// never disagree about what a valid submission looks like. Ported from
// lib/vision.js in the Vercel build; keep the two in step if either moves.

declare(strict_types=1);

const CATEGORIES = [
  'Labs & compute',
  'Department spaces',
  'Software & tools',
  'Student research & travel',
  'Teaching & curriculum infrastructure',
  'Something else',
];

const BRACKETS = [
  'Under ₹5 lakh',
  '₹5-10 lakh',
  '₹10-25 lakh',
  '₹25-50 lakh',
  '₹50 lakh - 1 crore',
  '₹1 crore+',
];

// These are the radio values in index.html, verbatim. The Vercel build shipped
// with 'Just here to dream' here while the page sent 'Just putting the idea out
// there', so every student who picked the third option got a 400. Both strings
// are accepted now, because a page cached in someone's browser can still send
// the old one.
const INVOLVEMENT = ['Yes', 'Maybe', 'Just putting the idea out there', 'Just here to dream'];

// 31 Aug 2026, 23:59:59 IST. Change this one line to move the close date; the
// line printed on the page is in index.html.
const CLOSES_AT = '2026-08-31 23:59:59 +0530';

const MAX_ENTRIES = 3;

const LIMITS = ['name' => 120, 'roll' => 20, 'ask' => 120, 'why' => 500, 'breakdown' => 500];

function closes_at(): int {
  return strtotime(CLOSES_AT);
}

function s($v): string {
  return is_string($v) ? trim($v) : '';
}

/**
 * Validates one submission and returns a normalised record.
 * ['ok' => true, 'record' => [...]] or ['ok' => false, 'status' => int, 'error' => string]
 */
function validate($body, ?int $now = null): array {
  $now = $now ?? time();
  $fail = fn(string $error, int $status = 400) => ['ok' => false, 'status' => $status, 'error' => $error];

  if ($now > closes_at()) {
    return $fail('The vision sheet has closed. Thank you to everyone who sent something in.', 410);
  }

  if (!is_array($body)) $body = [];

  if (($body['consent'] ?? null) !== true) {
    return $fail('Please tick the box so we know we can share this with the department.');
  }

  $name = s($body['name'] ?? null);
  $roll = s($body['roll'] ?? null);
  // The field is labelled "LDAP ID", so a bare id with no domain is the expected
  // input, not a mistake. Complete it rather than reject it.
  $email = strtolower(s($body['email'] ?? null));
  if ($email !== '' && !str_contains($email, '@')) $email .= '@iitb.ac.in';

  if ($name === '') return $fail('We need your name.');
  if (mb_strlen($name) > LIMITS['name']) return $fail('That name is longer than we can store.');
  if ($roll === '') return $fail('We need your roll number.');
  if (mb_strlen($roll) > LIMITS['roll']) return $fail('That roll number does not look right.');
  // Roll number format is deliberately not enforced. The page warns on an odd
  // shape and lets it through; spec says never block on content.
  if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email) || mb_strlen($email) > 254) {
    return $fail('That email does not look right.');
  }
  if (!preg_match('/@iitb\.ac\.in$/i', $email)) {
    return $fail('Please use your IITB email, the one ending in @iitb.ac.in.');
  }

  $raw = $body['entries'] ?? null;
  if (!is_array($raw) || array_is_list($raw) === false) $raw = is_array($raw) ? array_values($raw) : [];
  if (count($raw) < 1) return $fail('Tell us at least one thing IEOR should have in 2036.');
  if (count($raw) > MAX_ENTRIES) return $fail('Three visions is the limit.');

  $entries = [];
  foreach ($raw as $i => $e) {
    $at = fn(string $what) => 'Vision ' . ($i + 1) . ': ' . $what;
    if (!is_array($e)) $e = [];
    $ask = s($e['ask'] ?? null);
    $why = s($e['why'] ?? null);
    $breakdown = s($e['breakdown'] ?? null);
    $category = s($e['category'] ?? null);
    $bracket = s($e['bracket'] ?? null);

    if ($ask === '') return $fail($at('what should IEOR have in 2036?'));
    if (mb_strlen($ask) > LIMITS['ask']) return $fail($at('keep the ask to one line.'));
    // The category dropdown was pulled from the page on Anant's call, so a
    // submission without one is normal. The column stays in the CSV and the
    // value is still checked when present, so putting the field back is a
    // markup change and nothing else.
    if ($category !== '' && !in_array($category, CATEGORIES, true)) return $fail($at('pick where this belongs.'));
    if ($why === '') return $fail($at('tell us why it matters.'));
    if (mb_strlen($why) > LIMITS['why']) return $fail($at('the why is longer than we can store.'));
    if (!in_array($bracket, BRACKETS, true)) return $fail($at('pick a ballpark budget.'));
    if (mb_strlen($breakdown) > LIMITS['breakdown']) return $fail($at('the breakdown is too long.'));

    $entries[] = ['ask' => $ask, 'category' => $category, 'why' => $why,
                  'bracket' => $bracket, 'breakdown' => $breakdown];
  }

  $involvement = s($body['involvement'] ?? null);
  if (!in_array($involvement, INVOLVEMENT, true)) {
    return $fail('Let us know whether you would want to be involved.');
  }

  return ['ok' => true, 'record' => [
    'at' => gmdate('Y-m-d\TH:i:s.000\Z', $now),
    'name' => $name, 'roll' => $roll, 'email' => $email,
    'entries' => $entries, 'involvement' => $involvement,
  ]];
}

const CSV_COLUMNS = [
  'timestamp', 'name', 'roll', 'email', 'entry_no',
  'ask', 'category', 'why', 'bracket', 'breakdown', 'involvement',
];

function cell($v): string {
  $s = (string)($v ?? '');
  return preg_match('/["\,\n\r]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
}

/**
 * Flattens records to CSV with one row per vision entry, not per person, so
 * category and bracket aggregation is a pivot table away.
 */
function to_csv(array $records): string {
  $rows = [implode(',', CSV_COLUMNS)];
  foreach ($records as $r) {
    foreach (($r['entries'] ?? []) as $i => $e) {
      $rows[] = implode(',', array_map('cell', [
        $r['at'] ?? '', $r['name'] ?? '', $r['roll'] ?? '', $r['email'] ?? '', $i + 1,
        $e['ask'] ?? '', $e['category'] ?? '', $e['why'] ?? '', $e['bracket'] ?? '',
        $e['breakdown'] ?? '', $r['involvement'] ?? '',
      ]));
    }
  }
  return implode("\n", $rows) . "\n";
}
