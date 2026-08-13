# The Next Fifty: IEOR Golden Jubilee vision sheet

A single page that asks current IEOR students one question: what should IEOR have in 2036
that it doesn't have today? Answers are collected until **31 August 2026** and exported as a
CSV for the Golden Jubilee fundraising effort.

Static HTML plus three small PHP files. **No database, no Drupal module, no composer, no
changes to the existing site.**

---

## For whoever deploys this

Copy the contents of this repository into a folder called **`next-fifty`** in the web root,
so the page answers at:

```
https://www.ieor.iitb.ac.in/next-fifty/
```

That is the whole deploy. Nothing to configure, nothing to edit.

**Two requirements**, both already true on a box running Drupal 10:

1. PHP 8.1 or newer, with `mbstring` and `json` (Drupal 10 requires both).
2. Apache must be able to **write** to the `next-fifty/data/` directory. The scripts create
   it on first use; if the web user cannot write there, `setup.php` will say so in plain
   English rather than failing silently.

Please **do not** rename `data/`, and do not serve the folder with `Options +Indexes`.

### Confirm it worked

| Open this | You should see |
|---|---|
| `/next-fifty/` | The form, on a cream page, with the IEOR mark at the top |
| `/next-fifty/data/` | **403 Forbidden.** If you see a file listing instead, stop and tell Harshit |

### A note for the site admin, unrelated to this folder

The TLS certificate on that host only covers `www.ieor.iitb.ac.in`. The bare
`ieor.iitb.ac.in` fails the name check and throws a full page browser warning:

```
subject=CN=www.ieor.iitb.ac.in
X509v3 Subject Alternative Name: DNS:www.ieor.iitb.ac.in
```

Anything circulated as a bare `ieor.iitb.ac.in` link will scare students off. Worth adding
the apex name to the SAN when the cert is next renewed.

---

## For Harshit

Once it is deployed, the whole sheet downloads as a CSV from:

```
https://www.ieor.iitb.ac.in/next-fifty/export.php?key=<PASSPHRASE>
```

**One row per vision entry, not per person**, so pivoting on budget bracket is immediate:

```
timestamp, name, roll, email, entry_no, ask, category, why, bracket, breakdown, involvement
```

The file carries a UTF-8 BOM so Excel renders the rupee signs instead of mojibake.

Only the **SHA-256 of that passphrase** is in this repository, as `KEY_HASH` in `store.php`.
A hash cannot be reversed, so this repo being public gives nobody access, and the passphrase
itself is never stored on the server at all.

To rotate it: pick a new random passphrase, put its hash in `store.php`, redeploy.

```
php -r "echo bin2hex(random_bytes(24));"          # the passphrase, keep it
php -r "echo hash('sha256', '<passphrase>');"     # the hash, goes in store.php
NF_KEY=<passphrase> php selfcheck.php             # confirms the pair matches
```

---

## Files

| File | What it does |
|---|---|
| `index.html` | The whole page. No framework, no build step. |
| `submit.php` | POST target. JSON in, `{ok:true}` or `{error:"..."}` out. |
| `vision.php` | Field rules and CSV flattening, shared so they cannot drift apart. |
| `store.php` | Where submissions land, and how they stay unreadable over HTTP. |
| `setup.php` | Run once. Prints the export key, then locks. |
| `export.php` | The CSV, gated on that key. |
| `selfcheck.php` | `php selfcheck.php`. 32 assertions over the rules that matter. |

## How submissions are stored

One file per submission in `data/`, append-only. Nothing overwrites: a second submission from
the same person is allowed by design and sorted out at aggregation time.

Two things keep those files private, because we cannot read this server's vhost config:

1. `data/.htaccess` denies the directory. That is the normal answer, and it silently does
   nothing if the vhost sets `AllowOverride None`.
2. So every stored file is named `.php` and begins with `<?php exit; ?>`. If Apache ever
   serves that directory anyway, PHP runs the file, exits, and returns an **empty body**
   instead of a student's name and roll number. Reading from disk skips that first line.

Verified: fetching a stored submission over HTTP returns 200 with 0 bytes, while the same
file on disk contains the full record.

## Rate limiting

The submit endpoint is public, as any form on the open internet is: anyone who knows the URL
can POST to it. They cannot read, list or delete anything, and they have no say over where a
file lands, but nothing stops a script from sending a flood of valid-looking submissions.

So one address gets **30 submissions per hour**, after which it receives a 429 and a plain
message. The limit is checked *after* validation, so a student fumbling a required field
never burns their own quota.

Deliberately loose, for one reason: **IITB campus wifi is NAT'd**, so an entire lab can share
a single public address, and a class filling the form together after an announcement is the
normal case rather than an attack. Turning real students away is far worse than letting a
script through. If anyone is ever refused, raise `RATE_LIMIT` in `store.php`.

For the same reason the client address is read from `X-Forwarded-For` when present, falling
back to `REMOTE_ADDR`. That header is trivially spoofable, so it weakens the throttle, but
the alternative is worse: behind a reverse proxy every student would appear as the proxy's
one address and the whole department would share a single bucket.

Counters are stored hashed, so the folder never holds a list of raw student IP addresses.

## Validation, deliberately lopsided

Only two things block a submission: a missing required field, and an email that is not
`@iitb.ac.in`. An unusual roll number shows a warning and goes through anyway. Nothing about
the *content* of an answer is ever rejected. A bare LDAP id is completed to
`<id>@iitb.ac.in` rather than refused, because the field is labelled "LDAP ID".

The close date is enforced in `vision.php`, not just printed on the page. After
31 August 2026, 23:59:59 IST, submissions get a 410 and a plain message. To move it, change
`CLOSES_AT` in `vision.php` and the printed line in `index.html` together.
