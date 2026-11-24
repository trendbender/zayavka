# zayavka

A standalone form receiver for websites. One PHP directory, SQLite, no libraries, no external services. MIT.

The lead is written to a database on your own server first. Everything that leaves the server afterwards is a
notice without personal data, unless you explicitly allow more.

Built for sites that fall under the Russian personal data law (152-FZ). It does not make a site compliant by itself
and it is not legal advice. You still need a lawful basis, a privacy policy, a separate consent text, a notification
to Roskomnadzor where required, and hosting and backups located in Russia. The program cannot verify where your
server is.

**Status:** 0.1.0, new. It has not run on a live site yet. The test suite passes on PHP 8.0 to 8.5; nginx with PHP-FPM 8.3
was tried briefly. Apache, LiteSpeed and shared hosting panels were not.

## What goes where

| Channel | By default | Personal data |
|---|---|---|
| Local database, admin page | everything | yes; the admin page has a password, the file is protected by file permissions only, it is not encrypted |
| Email | number, form label, time, link | only with `data: full` + `in_russia: true` + your own `smtp`; never if the recipient, the sender, the SMTP host or the MX servers are on the built-in list of foreign providers |
| Webhook (CRM and others) | delivery id, number, form, time | only with `data: full` + `in_russia: true`, never to services on the built-in list or to an IP address (127.0.0.1 is allowed); redirects are not followed |
| Telegram | number, form label, time, link | never, there is no option for it |

The lists of foreign services are not exhaustive. `in_russia: true` is your statement, not a check: a foreign host
that is not on the list, for example your own domain on a foreign server, will receive the data. If a mail provider
or a CRM receives personal data, you need a contract with it (a processing assignment).

A lead that was accepted under one channel setting is not sent in full to a receiver that changed later.

A form label can itself be sensitive, for example the name of a medical specialty. Use neutral labels or set
`notice_show_form` to `false`.

## Install

Requires PHP 8.0+ with `pdo_sqlite`, `mbstring`, `openssl`, `session`; SQLite 3.24+; HTTPS; cron.

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
   browser, each must give 404.
7. Add the form from `examples/form.html`. `consent_version` in the form is the id of an edition in `consents`.
   It works without JavaScript too.

Set `post_max_size` and the body limit of the web server to about 64 KB for `/zayavka/`: PHP reads a form before the
program can refuse it.

**Behind a proxy or a CDN** without `trust_proxy` all visitors share one address and one limit: a few leads or a few
wrong passwords block everybody for a while. Set `trust_proxy` only if the proxy always overwrites `X-Real-IP` and
the server cannot be reached around the proxy.

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
- **Addresses.** The program stores a keyed hash of the IP (IPv6: of the /64 network) until the limit window ends.
  Whoever has the `secret` can reverse it. Web server logs are separate.
- **Webhook signature.** With `secret` set: `X-Zayavka-Timestamp` and `X-Zayavka-Signature: sha256=HMAC(timestamp.body)`.
  Reject old timestamps.

## Limits you should know

One shared admin password, so the log does not name a person. A lead can be found and deleted, but not corrected or
blocked. No encryption at rest. If the database is locked or the disk is full, the lead is not kept anywhere: the visitor
is told plainly that it was not accepted. No automatic breach alerts:
the law gives 24 and 72 hours to notify Roskomnadzor. No backups: make your own, in Russia, with a retention period.
Excel, real Telegram, SMTP and CRM servers were not part of the tests.

## Tests

`python3 tests/run.py` starts PHP's built-in server with fake Telegram, webhook and SMTPS servers; it needs `openssl`
on the command line and no network. `python3 tests/mutate.py` removes protections one by one and checks that the tests
notice (94 changes). Passing tests are not a proof of security.
