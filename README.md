# zayavka

A standalone form receiver for websites. One PHP directory, SQLite, no libraries, no external services. MIT.

The lead is written to a database on your own server first. Everything that leaves the server afterwards is a
notice without personal data, unless you explicitly allow more.

Built for sites that fall under the Russian personal data law (152-FZ). It does not make a site compliant by itself
and it is not legal advice. You still need a lawful basis, a privacy policy, a separate consent text, a notification
to Roskomnadzor where required, and hosting and backups located in Russia. The program cannot verify where your
server is.

**Status:** 0.2.0, new. It has not run on a live site yet. The test suite passes on PHP 8.0 to 8.5. See "Security checks" for
what was and was not checked.

## What goes where

| Channel | By default | Personal data |
|---|---|---|
| Local database, admin page | everything | yes; the admin page has a password, the file is protected by file permissions only, it is not encrypted |
| Email | number, form label, time, link | only with `data: full` + `in_russia: true` + your own `smtp`; never if the recipient, the sender, the SMTP host or the MX servers are on the built-in list of foreign providers |
| Webhook (CRM and others) | delivery id, number, form, time, or your own `map` with the personal placeholders empty | only with `data: full` + `in_russia: true`, never to services on the built-in list or to an IP address (127.0.0.1 is allowed); redirects are not followed |
| Own code (`php`) | the same placeholders, the personal ones empty | only with `data: full` + `in_russia: true` + a named `target`; the program cannot see where your code sends them |
| Telegram | number, form label, time, link | never, there is no option for it |

The lists of foreign services are not exhaustive. `in_russia: true` is your statement, not a check: a foreign host
that is not on the list, for example your own domain on a foreign server, will receive the data. If a mail provider
or a CRM receives personal data, you need a contract with it (a processing assignment).

A lead that was accepted under one channel setting is not sent in full to a receiver that changed later (address, mailbox,
`headers`, `map`, `target`, own file). After changing a key, wait until the queue is empty or resend by hand.

A form label can itself be sensitive, for example the name of a medical specialty. Use neutral labels or set
`notice_show_form` to `false`.

## Goals and integrations

- **Goals.** Put the number of your Yandex Metrica counter into `data-metrika` and the goal id into `data-goal` of the form: after an
  accepted lead the form calls `reachGoal`. Nothing from the lead goes into the goal. The form never loads the counter: load
  Metrica on your site only after the visitor has allowed analytics, until then the goal is simply not sent. The browser event `zayavka:sent` (form
  name and lead number) is there for anything else. Without JavaScript use a goal "page visit" on `thanks_url`. A lead that
  filled the trap field reaches the goal too.
- **Ad tags.** Commented hidden fields keep `utm_*`, `yclid` and the Metrica client id with the lead.
- **CRM.** `config.example.php` holds commented templates for Bitrix24, amoCRM, PMS, any ordinary form post and your own PHP
  code. `map` describes the body, `headers` adds `Authorization`, `Accept` or `X-...` only. `doctor` names unknown
  placeholders. The templates carry `in_russia: false`: personal data stays here until you change it on purpose. They follow
  the public documentation of the vendors and were tested against a fake server, not against live CRM accounts. Only the HTTP
  status is read: an error inside a 200 answer is not seen.
- **Own code** (`examples/own.php`) bypasses the rules above: whoever pasted it answers for the data it sends. It runs from cron
  in a separate process with a time limit, after the lead is stored; its output and messages are discarded, only success or
  failure is known, a failure is repeated. Needs `proc_open`.

## Install

Requires PHP 8.0+ with `pdo_sqlite`, `mbstring`, `openssl`; SQLite 3.24+; HTTPS; cron.

1. Put this directory outside every web root. Copy `public/submit.php` and `public/admin.php` into the web root, for
   example `/zayavka/`, and set `ZAYAVKA_DIR` for PHP (nginx: `fastcgi_param ZAYAVKA_DIR /srv/zayavka;`, Apache:
   `SetEnv ZAYAVKA_DIR /srv/zayavka`), or edit the `$lib` line in both files.
   Nothing else may be reachable over HTTP. If you can, put `admin.php` on a separate subdomain: a script injected
   into the site could otherwise act inside an open admin session.
2. `cp config.example.php config.php && chmod 600 config.php`, then fill it in. `web_roots` must list every directory
   of the account that is served over HTTP: the program refuses to start from inside them.
3. Owner of the directory, `config.php` and `storage` = the user that PHP of the website runs as.
   **Run `bin/zayavka` as that user, never as root:** `sudo -u www-data php bin/zayavka doctor`.
4. `bin/zayavka password` reads a password from stdin and prints the hash for the admin page.
5. Cron, as the same user: `* * * * * php /path/bin/zayavka deliver`. It sends, retries and deletes expired leads.
   Under PHP-FPM and LiteSpeed the first attempt is made right after the answer; everywhere else only cron sends.
6. `bin/zayavka doctor` checks the setup and prints what each channel will send. Then open `admin.php`: it must show
   a login form. Doctor cannot see your web server settings: also open `/config.php`, `/storage/` and `/bin/zayavka` in a
   browser, each must give 404. Keep `display_errors=Off` in php.ini: start-up warnings of PHP appear before this code runs.
7. Add the form from `examples/form.html`. `consent_version` in the form is the id of an edition in `consents`.
   It works without JavaScript too.

Set `post_max_size` and the body limit of the web server to about 64 KB for `/zayavka/`: PHP reads a form before the
program can refuse it.

**Behind a proxy or a CDN** without `trust_proxy` all visitors share one address and one limit: a few leads or a few
wrong passwords block everybody for a while. Set `trust_proxy` only if the proxy always overwrites `X-Real-IP` and
the server cannot be reached around the proxy. Without a proxy leave it off: the header would let anyone pass the limits.

**Upgrading from 0.1.0:** copy `src`, `bin`, `public`; the database is converted at the first request. Make a copy of `storage` before.
An older copy put back later is converted too; delete the `-wal` and `-shm` files next to the database before you put a copy in place.
After a copy is put back, lead and delivery numbers given out since then are used again, a CRM may take new leads for repeats, and
deletions made since then are undone: repeat them from your own records. An empty database file is replaced by a new empty database.

## How it behaves

- **Consent.** The form sends the id of the edition it shows. Unknown id: the lead is refused and the visitor is asked
  to reload. The id, the SHA-256 and the full text of the edition, and the server time are stored. This records what the
  form sent, it does not prove that a person clicked.
- **Repeats.** Same id and same content is one lead. Same id and changed content is a new lead. Without JavaScript the
  same content within about 10 minutes is one lead.
- **Trap field.** A filled trap does not discard the lead: it is stored, marked and shown in the admin page, without
  notices. A password manager may fill the trap for a real person.
- **Delivery** is at least once: a crash between sending and recording sends again. `delivery` in a webhook is stable,
  use it to drop duplicates. Retries take about three days, then `failed` and a warning; `bin/zayavka retry` starts over.
- **Deletion.** Expired leads are deleted on every handled submission and on every cron run, also if they were not
  delivered. Every deletion leaves a record without contents: number, field names, dates, reason. `bin/zayavka audit`
  prints the log. SQLite `secure_delete` is on and the write-ahead log is truncated; while another process reads the
  database, the truncation waits for the next cron run. Backups, disk snapshots and SSD remnants are yours to handle.
- **Limits.** Per address (IPv6: per /64 and per /48). Above `max_per_day` leads are still stored for the usual
  time, but marked and not announced, except one plain notice a day: open the list.
  Delete them all with `bin/zayavka purge-over yes` or in the admin page; `purge-trap yes` does the same for leads with a filled trap. At 80 % of `max_db_mb` cron writes to every mailbox and
  chat once a day, without personal data. Only a full disk (`max_db_mb`, `min_free_mb`) closes the form. Wrong passwords: 5 per
  address in 15 minutes, 20 per IPv6 /48, 200 for everybody together. A browser that has logged in before (90 days, until
  "forget this browser" or a new password) is held by the first limit only; a new browser of the owner waits while strangers keep guessing.
- **Admin sessions** are keyed hashes in the database. Nothing is stored before a correct password.
- **Addresses.** The program stores a keyed hash of the IP (IPv6: of the /64 network) until the limit window ends.
  Whoever has the `secret` can reverse it. Web server logs are separate.
- **Webhook signature.** With `secret` set: `X-Zayavka-Timestamp` and `X-Zayavka-Signature: sha256=HMAC(timestamp.body)`.
  Reject old timestamps.

## Limits you should know

One shared admin password, at most 72 bytes, so the log does not name a person. No second factor. Lead numbers grow by one
and are shown to the visitor: two leads tell how many came between them. A lead can be found and deleted, but not corrected or
blocked. No encryption at rest. If the database is locked or the disk is full, the lead is not kept anywhere: the visitor
is told plainly that it was not accepted. No automatic breach alerts:
the law gives 24 and 72 hours to notify Roskomnadzor. No backups: make your own, in Russia, with a retention period.
Excel, real Telegram, SMTP, CRM and Metrica servers were not part of the tests.

## Tests

`python3 tests/run.py` starts PHP's built-in server with fake Telegram, webhook and SMTPS servers; it needs `openssl`
on the command line and no network. `python3 tests/mutate.py` removes protections one by one and checks that the tests
notice (170 changes). Passing tests are not a proof of security.

## Security checks

Version 0.2.0, 25 November 2026. Done on local machines with made-up data, by AI reviewers and scripts. This is not an
independent penetration test and not a certificate; passing checks are not a proof of security.

- Own test suite: 355 checks, all passed on PHP 8.0, 8.1, 8.2, 8.3, 8.4 and 8.5. Upgrade from 0.1.0 and putting an old copy
  back: 22 checks on PHP 8.1 and 8.5.
- Mutation run: 170 protections removed one by one. The full run noticed 169; the test for the last one was made stricter and
  noticed it in a repeat.
- SQL: every database call was read; 502 requests with hostile input, the schema did not change.
- HTML injection: 9 payloads in six fields and in `Origin`, 37 admin pages, nothing left unescaped.
- CSRF and Fetch Metadata: 35 combinations. Foreign `Origin` and `Referer`: 11 spellings.
- Password guessing: 40 parallel attempts, exactly 5 were checked. Lead limit under parallel load: exact.
- Webhook and SMTP: self-signed and wrong-name certificates, redirects, endless and silent answers are refused.
- Raw HTTP through Apache 2.4 with PHP-FPM: CL+TE, double Content-Length, bare LF.
- Static analysis: PHPStan level 5, Semgrep (266 public rules, every match read by hand), gitleaks.
- OWASP ASVS 5.0 levels 1-2, 253 requirements: 89 met, 30 partly, 15 not met, 9 depend on your server, 110 not applicable.
  Not met, for example: no second factor, no breached-password check.

Not checked: LiteSpeed, hosting panels, real Telegram, SMTP, CRM, Metrica and Excel, long operation on a live site.
The audit of 0.1.0 found problems of availability and hardening; they are fixed in 0.2.0.
