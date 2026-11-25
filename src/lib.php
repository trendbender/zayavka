<?php
declare(strict_types=1);

/* Zayavka: standalone form receiver. PHP 8.0+, SQLite, no libraries. MIT.
   The lead is written to the local database first; channels get a notice without personal data by default. */

const ZK_VERSION = '0.2.0';
const ZK_TRIES = 75;                                    // 1, 5, 15 minutes, then hourly: about three days
const ZK_FOREIGN_MAIL = '/(^|\.)(gmail\.com|googlemail\.com|google\.com|outlook\.[a-z.]+|hotmail\.[a-z.]+|live\.[a-z.]+|office365\.com|icloud\.com|me\.com|yahoo\.[a-z.]+|yahoodns\.net|proton\.me|protonmail\.[a-z]+|zoho\.[a-z.]+|fastmail\.[a-z]+|messagingengine\.com|gmx\.[a-z]+|mailgun\.org|sendgrid\.net|amazonaws\.com|amazonses\.com|mailjet\.com|postmarkapp\.com|brevo\.com|sendinblue\.com)$/i';
const ZK_FOREIGN_HOOK = '/(^|\.)(zapier\.com|make\.com|integromat\.com|slack\.com|discord\.com|discordapp\.com|telegram\.org|google\.com|googleapis\.com|azure\.com|azurewebsites\.net|windows\.net|office\.com|amazonaws\.com|cloudfunctions\.net|run\.app|vercel\.app|netlify\.app|workers\.dev|herokuapp\.com|n8n\.cloud|airtable\.com|bitrix24\.(?!ru$)[a-z.]+|amocrm\.com|kommo\.com|notion\.com|hubspot\.com|hubapi\.com|salesforce\.com|pipedrive\.com|webhook\.site|web3forms\.com|formspree\.io|forminit\.com|getform\.io)$/i';

ini_set('display_errors', '0');                         // warnings of this code do not reach the visitor; PHP start-up warnings need display_errors=Off in php.ini

/* ---------- configuration ---------- */

function zk_dir(): string { return getenv('ZAYAVKA_DIR') ?: dirname(__DIR__); }

/** 'https://Example.ru/' becomes 'https://example.ru', as browsers send it. Null when it cannot match any browser. */
function zk_origin(string $o): ?string {
    if (!preg_match('~^(https?)://([^/:@?#\s]+)(?::(\d{1,5}))?/?$~iu', trim($o), $m)) return null;
    $scheme = strtolower($m[1]); $host = mb_strtolower($m[2]); $port = $m[3] ?? '';
    if (preg_match('/[^\x21-\x7e]/', $host)) {
        $host = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
        if (!$host) return null;
    }
    if (($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80')) $port = '';
    return $scheme . '://' . $host . ($port !== '' ? ':' . $port : '');
}

function zk_cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $f = zk_dir() . '/config.php';
    if (!is_file($f) || !is_readable($f)) throw new RuntimeException('config.php is missing or not readable by this user');
    ob_start();                                         // swallows a byte order mark
    try { $c0 = require $f; } catch (Throwable $e) { throw new RuntimeException('config.php: ' . ($e instanceof ParseError ? 'syntax error' : 'error') . ' on line ' . $e->getLine()); } finally { ob_end_clean(); }    // the text of the error could hold a key
    if (!is_array($c0)) throw new RuntimeException('config.php must return an array');
    $c0 += [
        'allowed_origins' => [], 'forms' => [], 'fields' => [], 'consents' => [], 'secret' => '', 'web_roots' => [],
        'storage' => zk_dir() . '/storage', 'retention_days' => 365, 'suspect_days' => 30, 'audit_days' => 1096, 'max_bytes' => 32768,
        'rate_limit' => 10, 'rate_window' => 600, 'max_per_day' => 2000, 'max_db_mb' => 500, 'min_free_mb' => 100, 'trust_proxy' => false, 'channels' => [], 'deliver_on_submit' => true,
        'admin_password_hash' => '', 'admin_url' => '', 'notice_show_form' => true, 'thanks_url' => '/', 'timezone' => 'Europe/Moscow', 'require_any' => [],
    ];
    foreach (['allowed_origins', 'forms', 'fields', 'consents', 'secret', 'web_roots'] as $k)
        if (!$c0[$k]) throw new RuntimeException("config: $k is empty");
    foreach (array_keys($c0['fields']) as $k) if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $k)) throw new RuntimeException('config: fields: names are latin letters, digits, _ and - only');
    if (strlen($c0['secret']) < 32) throw new RuntimeException('config: secret must be 32+ characters');
    foreach ($c0['allowed_origins'] as $i => $o) {
        $c0['allowed_origins'][$i] = zk_origin((string) $o) ?? throw new RuntimeException("config: allowed_origins: '$o' must be scheme and host only, in punycode if PHP has no intl");
    }
    foreach ($c0['consents'] as $id => $text)
        if (trim((string) $text) === '') throw new RuntimeException("config: consents: text of '$id' is empty");
    date_default_timezone_set($c0['timezone']);
    return $c = $c0;
}

function zk_inside(string $path, string $root): bool { return $root !== '' && str_starts_with(rtrim($path, '/') . '/', rtrim($root, '/') . '/'); }

/** The path as written, without '.', '..' and double slashes; links are not followed. */
function zk_path(string $p): string {
    $out = [];
    foreach (explode('/', $p) as $x) { if ($x === '' || $x === '.') continue; if ($x === '..') array_pop($out); else $out[] = $x; }
    return '/' . implode('/', $out);
}

/** Nothing except submit.php and admin.php may lie inside a web root. Checked from the web and from the command line. */
function zk_guard(): void {
    $c = zk_cfg(); $st = $c['storage']; $probe = $st;
    while (!file_exists($probe) && dirname($probe) !== $probe) $probe = dirname($probe);
    $real = realpath($probe) . substr($st, strlen($probe));
    $roots = [];
    foreach ($c['web_roots'] as $r) $roots[] = realpath($r) ?: throw new RuntimeException("config: web_roots: '$r' not found");
    if (PHP_SAPI !== 'cli') {
        if (!empty($_SERVER['DOCUMENT_ROOT']) && ($r = realpath($_SERVER['DOCUMENT_ROOT']))) $roots[] = $r;
        if ($r = realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) $roots[] = dirname($r);
    }
    $roots = array_merge($roots, array_map('zk_path', $c['web_roots']));                // also as written: a link inside a web root that leads outside is refused too
    foreach ($roots as $r) {
        if (zk_inside(zk_path($st), $r)) throw new RuntimeException('storage is inside the web root');
        if (zk_inside($real, $r)) throw new RuntimeException('storage is inside the web root');
        if (zk_inside((string) realpath(zk_dir()), $r)) throw new RuntimeException('the program directory is inside the web root');
    }
    if (!is_dir($st) && !@mkdir($st, 0700, true) && !is_dir($st)) throw new RuntimeException('cannot create storage');
    foreach (['.htaccess' => "Require all denied\n", 'index.html' => ''] as $n => $body)
        if (!is_file("$st/$n")) @file_put_contents("$st/$n", $body);
}

/* ---------- database ---------- */

/** The name depends on the secret: even if storage becomes reachable over HTTP, the file name cannot be guessed. */
function zk_db_file(): string { return zk_cfg()['storage'] . '/leads-' . substr(hash_hmac('sha256', 'db', zk_cfg()['secret']), 0, 24) . '.sqlite'; }

function zk_db(): PDO {
    static $db = null;
    if ($db) return $db;
    zk_guard();
    $st = zk_cfg()['storage']; $f = zk_db_file();
    if (!is_file($f) && array_diff(glob("$st/leads-*.sqlite") ?: [], [$f])) throw new RuntimeException('storage holds a database made with another secret');
    $old = umask(0077); $lock = null;
    try {
        if (!is_file("$f.v2") || !is_file($f)) {                        // first requests may be parallel: one creates or upgrades, the others wait before opening
            $lock = fopen("$st/init.lock", 'c');
            if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('cannot lock storage');
        }
        $d = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
        $d->exec('PRAGMA foreign_keys=ON; PRAGMA secure_delete=ON');
        $ver = fn(): int => (int) $d->query('PRAGMA user_version')->fetchColumn();
        if (!$lock && $ver() !== 2) {                                   // the mark is there, but the owner has put back an older copy
            $lock = fopen("$st/init.lock", 'c');
            if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('cannot lock storage');
        }
        if ($ver() > 2) throw new RuntimeException('the database was made by a newer version of zayavka');
        if ($lock && $ver() === 0) {
            $d->exec('PRAGMA journal_mode=WAL');
            $d->exec("BEGIN; CREATE TABLE leads(id INTEGER PRIMARY KEY AUTOINCREMENT, idem TEXT UNIQUE NOT NULL, form TEXT NOT NULL, page TEXT NOT NULL, fields TEXT NOT NULL, consent_id TEXT NOT NULL, consent_sha TEXT NOT NULL, consent_at TEXT NOT NULL, created_at INTEGER NOT NULL, suspect INTEGER NOT NULL DEFAULT 0);
                CREATE TABLE consents(sha TEXT PRIMARY KEY, id TEXT NOT NULL, text TEXT NOT NULL, first_seen TEXT NOT NULL);
                CREATE TABLE deliveries(id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE, channel TEXT NOT NULL, target TEXT NOT NULL, declared INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending', tries INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', UNIQUE(lead_id, channel));
                CREATE INDEX deliveries_due ON deliveries(status, next_at);
                CREATE TABLE hits(h TEXT NOT NULL, exp INTEGER NOT NULL); CREATE INDEX hits_h ON hits(h, exp);
                CREATE TABLE counters(day TEXT NOT NULL, k TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY(day, k));
                CREATE TABLE state(k TEXT PRIMARY KEY, v TEXT NOT NULL);
                CREATE TABLE audit(ts INTEGER NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, lead TEXT NOT NULL DEFAULT '', info TEXT NOT NULL DEFAULT '');
                CREATE TABLE sessions(h TEXT PRIMARY KEY, since INTEGER NOT NULL, seen INTEGER NOT NULL, mark TEXT NOT NULL);
                CREATE TABLE known(h TEXT PRIMARY KEY, since INTEGER NOT NULL, mark TEXT NOT NULL);
                PRAGMA user_version=2; COMMIT");
        } elseif ($lock && $ver() === 1) {    // 0.1.0: delivery numbers could repeat, sessions were files
            $d->exec('PRAGMA foreign_keys=OFF');
            $d->exec("BEGIN IMMEDIATE; CREATE TABLE deliveries2(id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE, channel TEXT NOT NULL, target TEXT NOT NULL, declared INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending', tries INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', UNIQUE(lead_id, channel));
                INSERT INTO deliveries2 SELECT id,lead_id,channel,target,declared,status,tries,next_at,note FROM deliveries;
                DROP TABLE deliveries; ALTER TABLE deliveries2 RENAME TO deliveries; CREATE INDEX deliveries_due ON deliveries(status, next_at);
                DELETE FROM sqlite_sequence WHERE name IN ('deliveries','deliveries2');
                INSERT INTO sqlite_sequence(name,seq) VALUES('deliveries', (SELECT COALESCE(MAX(id),0) FROM deliveries) + 1000000);
                CREATE TABLE sessions(h TEXT PRIMARY KEY, since INTEGER NOT NULL, seen INTEGER NOT NULL, mark TEXT NOT NULL);
                CREATE TABLE known(h TEXT PRIMARY KEY, since INTEGER NOT NULL, mark TEXT NOT NULL);
                PRAGMA user_version=2; COMMIT");
            $d->exec('PRAGMA foreign_keys=ON');
            foreach (glob("$st/sessions/sess_*") ?: [] as $x) @unlink($x);
            @rmdir("$st/sessions");
        }
        if ($lock) touch("$f.v2");
    } finally {
        umask($old);
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
    return $db = $d;
}

function zk_q(string $sql, array $p = []): PDOStatement { $s = zk_db()->prepare($sql); $s->execute($p); return $s; }
function zk_key(int $id): string { return 'Z-' . $id; }
function zk_audit(string $actor, string $action, string $lead = '', string $info = ''): void { zk_q('INSERT INTO audit VALUES(?,?,?,?,?)', [time(), $actor, $action, $lead, $info]); }
function zk_state(string $k, ?string $v = null): string {
    if ($v !== null) zk_q('INSERT INTO state VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', [$k, $v]);
    return (string) zk_q('SELECT v FROM state WHERE k=?', [$k])->fetchColumn();
}
function zk_count(string $k, int $n = 1): void { zk_q('INSERT INTO counters VALUES(?,?,?) ON CONFLICT(day,k) DO UPDATE SET n=n+excluded.n', [date('Y-m-d'), $k, $n]); }

function zk_tx(callable $fn) {
    $db = zk_db(); $db->exec('BEGIN IMMEDIATE');
    try { $r = $fn(); $db->exec('COMMIT'); return $r; } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

/** Deleted text can survive in the write-ahead log while another process reads the database. False: try again later. */
function zk_checkpoint(): bool { return (int) zk_db()->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM)[0] === 0; }

/* ---------- limits ---------- */

/** IPv6 counts as a /64 network: one subscriber owns all of it. */
function zk_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $x = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if (zk_cfg()['trust_proxy'] && filter_var($x, FILTER_VALIDATE_IP)) $ip = $x;
    $b = (string) @inet_pton($ip);
    if (strlen($b) === 16) $b = str_starts_with($b, str_repeat("\0", 10) . "\xff\xff") ? substr($b, 12) : substr($b, 0, 8);
    return bin2hex($b);
}

/** The wider IPv6 network of the visitor: a /48 is handed out for free by tunnel brokers. Empty for IPv4. */
function zk_net48(): string { $b = (string) hex2bin(zk_ip()); return strlen($b) === 8 ? 'net48:' . bin2hex(substr($b, 0, 6)) : ''; }

/** Bytes in use: the file itself does not shrink after deletions, free pages are used again. */
function zk_used(): int { $p = fn(string $k): int => (int) zk_db()->query("PRAGMA $k")->fetchColumn(); return ($p('page_count') - $p('freelist_count')) * $p('page_size'); }

function zk_low_space(): bool { return zk_used() > 0.8 * zk_cfg()['max_db_mb'] * 1048576; }

/** Disk is the only thing that closes the form for everybody. */
function zk_full_disk(): bool {
    $c = zk_cfg();
    $free = function_exists('disk_free_space') ? @disk_free_space($c['storage']) : false;
    return zk_used() > $c['max_db_mb'] * 1048576 || ($free !== false && $free < $c['min_free_mb'] * 1048576);
}

/** Stores a keyed hash of the address until the window ends. $who = '*' counts everybody together. */
function zk_limited(string $scope, int $max, int $window, string $who = ''): bool {
    $h = hash_hmac('sha256', $scope . '|' . ($who !== '' ? $who : zk_ip()), zk_cfg()['secret']);
    return zk_tx(function () use ($h, $max, $window): bool {
        zk_q('DELETE FROM hits WHERE exp<=?', [time()]);
        if ((int) zk_q('SELECT COUNT(*) FROM hits WHERE h=?', [$h])->fetchColumn() >= $max) return true;
        zk_q('INSERT INTO hits VALUES(?,?)', [$h, time() + $window]);
        return false;
    });
}

function zk_forgive(string $scope): void { zk_q('DELETE FROM hits WHERE h=?', [hash_hmac('sha256', $scope . '|' . zk_ip(), zk_cfg()['secret'])]); }

/* ---------- deletion ---------- */

/** Deletes one lead and leaves a record of destruction without its contents: number, field names, reason, undelivered count. */
function zk_delete(int $id, string $actor, string $reason): bool {
    return zk_tx(function () use ($id, $actor, $reason): bool {
        $l = zk_q('SELECT fields, created_at FROM leads WHERE id=?', [$id])->fetch();
        if (!$l) return false;
        $left = (int) zk_q("SELECT COUNT(*) FROM deliveries WHERE lead_id=? AND status<>'sent'", [$id])->fetchColumn();
        zk_q('DELETE FROM leads WHERE id=?', [$id]);
        zk_audit($actor, 'destroyed', zk_key($id), 'fields: ' . implode(',', array_keys((array) json_decode($l['fields'], true))) . '; received: ' . date('Y-m-d', (int) $l['created_at']) . '; reason: ' . $reason . ($left ? "; undelivered: $left" : ''));
        if ($left && $reason === 'retention') zk_count('expired undelivered', $left);
        return true;
    });
}

/** Deletes all leads above the daily cap (mark 2) or with a filled trap (mark 1) that nobody has confirmed. Each one leaves its record in the log. */
function zk_purge_over(string $actor, int $mark = 2): int {
    $n = 0;
    foreach (zk_q('SELECT id FROM leads WHERE suspect=? AND suspect>0', [$mark])->fetchAll(PDO::FETCH_COLUMN) as $id) $n += (int) zk_delete((int) $id, $actor, $mark === 2 ? 'over the daily cap' : 'trap');
    if ($n && !zk_checkpoint()) zk_state('checkpoint', 'pending');
    return $n;
}

function zk_purge(): int {
    $c = zk_cfg(); $n = 0;
    foreach ([[(int) $c['retention_days'], '0,1,2'], [(int) $c['suspect_days'], '1']] as [$days, $suspect]) {
        if ($days <= 0) continue;
        foreach (zk_q("SELECT id FROM leads WHERE suspect IN ($suspect) AND created_at<? LIMIT 500", [time() - $days * 86400])->fetchAll(PDO::FETCH_COLUMN) as $id) $n += (int) zk_delete((int) $id, 'system', 'retention');
    }
    zk_q('DELETE FROM hits WHERE exp<=?', [time()]);
    zk_q('DELETE FROM audit WHERE ts<?', [time() - (int) $c['audit_days'] * 86400]);
    zk_q('DELETE FROM counters WHERE day<?', [date('Y-m-d', time() - 90 * 86400)]);
    if ($n) zk_checkpoint();
    return $n;
}

/* ---------- accepting a lead ---------- */

function zk_clean(string $v, bool $multiline): string {
    $v = str_replace(["\r\n", "\r", "\u{85}", "\u{2028}", "\u{2029}"], "\n", $v);
    $v = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{61C}\x{80}-\x{9F}]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    if (!$multiline) $v = preg_replace('/[\n\t]+/u', ' ', $v);
    return trim($v);
}

/** Host and path of our own site only. Query is dropped; path parts that look like an address or a phone are hidden. */
function zk_page($raw): string {
    if (!is_string($raw) || strlen($raw) > 2000 || !preg_match('~^(https?://[^/?#\s]+)([^?#\s]*)~iu', $raw, $m)) return '';
    $o = zk_origin($m[1]);
    if ($o === null || !in_array($o, zk_cfg()['allowed_origins'], true)) return '';
    $parts = array_map(function (string $p): string {
        $d = rawurldecode($p);
        return !mb_check_encoding($d, 'UTF-8') || str_contains($d, '@') || strlen(preg_replace('/\D/', '', $d)) >= 7 || preg_match('/[\x00-\x1F\x7F]|[\x{80}-\x{9F}\x{61C}\x{2028}\x{2029}]/u', $d) ? '***' : $p;
    }, explode('/', $m[2] ?: '/'));
    return mb_substr(parse_url($o, PHP_URL_HOST) . implode('/', $parts), 0, 300);
}

/** Validate and store. Returns [http status, payload]. */
function zk_accept(array $in): array {
    $c = zk_cfg();
    $no = fn(int $code, string $why): array => [$code, ['ok' => false, 'error' => $why]];
    $form = is_string($in['form'] ?? null) ? $in['form'] : '';
    if (!isset($c['forms'][$form])) return $no(422, 'form');
    if (!in_array($in['consent'] ?? null, [true, 1, '1', 'on', 'yes'], true)) return $no(422, 'consent');
    $cid = is_string($in['consent_version'] ?? null) ? $in['consent_version'] : '';
    if (!isset($c['consents'][$cid])) return $no(409, 'consent_version');             // the page shows an edition we do not know
    $out = [];
    foreach ($c['fields'] as $name => $f) {
        $v = $in[$name] ?? '';
        if (!is_scalar($v) || !mb_check_encoding((string) $v, 'UTF-8')) return $no(422, $name);
        $v = zk_clean((string) $v, !empty($f['multiline']));
        if ($v === '' && !empty($f['required'])) return $no(422, $name);
        if (mb_strlen($v) > ($f['max'] ?? 500)) return $no(422, $name);
        if ($v !== '' && ($f['type'] ?? '') === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) return $no(422, $name);
        if ($v !== '' && ($f['type'] ?? '') === 'phone' && !preg_match('/^\+?[0-9][0-9 ()\-]{5,24}$/', $v)) return $no(422, $name);
        if ($v !== '') $out[$name] = $v;
    }
    if ($c['require_any'] && !array_intersect($c['require_any'], array_keys($out))) return $no(422, implode('|', $c['require_any']));

    // Same id and same content is a repeat. Same id and changed content is a new lead: a corrected phone must not be lost.
    $body = json_encode([$form, $out], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $mark = fn(string $s): string => hash_hmac('sha256', $s . '|' . $body, $c['secret']);
    $slot = intdiv(time(), 600);
    $keys = is_string($in['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $in['id']) ? [$mark('id:' . $in['id'])] : [$mark("slot:$slot"), $mark('slot:' . ($slot - 1))];
    $find = fn() => zk_q('SELECT id FROM leads WHERE idem IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY id DESC', $keys)->fetchColumn();
    if ($old = $find()) return [200, ['ok' => true, 'key' => zk_key((int) $old)]];
    if (zk_limited('submit', (int) $c['rate_limit'], (int) $c['rate_window'])) return $no(429, 'rate');
    if (($n48 = zk_net48()) !== '' && zk_limited('submit48', 5 * (int) $c['rate_limit'], (int) $c['rate_window'], $n48)) return $no(429, 'rate');
    if (zk_full_disk()) return $no(503, 'full');

    $trap = $in['zk_hp'] ?? '';
    $suspect = !is_string($trap) || trim($trap) !== '' ? 1 : 0;                       // kept and marked: a password manager may fill the trap for a person
    // Above the daily cap leads are still stored, but marked and not announced: a stranger must not be able to close the form for everybody.
    if ($suspect ? zk_limited('trap', 500, 86400, '*') : zk_limited('day', (int) $c['max_per_day'], 86400, '*')) { $suspect = $suspect ? 1 : 2; zk_count('over the daily cap'); }
    $text = (string) $c['consents'][$cid]; $sha = hash('sha256', $text);
    $id = zk_tx(function () use ($c, $keys, $find, $form, $in, $body, $out, $cid, $sha, $text, $suspect): int {
        zk_q('INSERT INTO consents VALUES(?,?,?,?) ON CONFLICT(sha) DO NOTHING', [$sha, $cid, $text, date('c')]);
        if ($old = $find()) return (int) $old;                                        // a parallel twin was first
        zk_q('INSERT INTO leads(idem,form,page,fields,consent_id,consent_sha,consent_at,created_at,suspect) VALUES(?,?,?,?,?,?,?,?,?)',
            [$keys[0], $form, zk_page($in['page'] ?? ''), json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), $cid, $sha, date('c'), time(), $suspect]);
        $id = (int) zk_db()->lastInsertId();
        if ($suspect === 1) zk_count('suspect');
        $first = $suspect === 2 && zk_state('over the cap') !== date('Y-m-d');             // one plain notice a day, so that the owner comes and looks
        if ($first) zk_state('over the cap', date('Y-m-d'));
        if (!$suspect || $first) foreach ($c['channels'] as $n => $ch) zk_q('INSERT INTO deliveries(lead_id,channel,target,declared) VALUES(?,?,?,?)', [$id, $n, zk_target($ch), $first ? 0 : (int) zk_declared($ch)]);
        return $id;
    });
    return [200, ['ok' => true, 'key' => zk_key($id)]];
}

/* ---------- delivery policy: what may leave the server ---------- */

function zk_listed(string $host, string $re): bool { return (bool) preg_match($re, rtrim(strtolower($host), '.')); }
function zk_local(string $host): bool { return in_array($host, ['127.0.0.1', 'localhost'], true); }
/** A real name with a letter zone: no port, no IP address in any notation. */
function zk_hostname(string $h): bool { return (bool) preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+(xn--[a-z0-9-]+|[a-z]{2,})$/i', $h); }
function zk_domain(string $mail): string { return strtolower(substr(strrchr($mail, '@') ?: '', 1)); }
function zk_declared(array $ch): bool { return ($ch['data'] ?? 'notice') === 'full' && ($ch['in_russia'] ?? false) === true && ($ch['type'] ?? '') !== 'telegram'; }
/** Fingerprint of the receiver. A queued lead is not sent in full to a receiver that changed after it was accepted. */
function zk_target(array $ch): string { return hash_hmac('sha256', json_encode([$ch['type'] ?? '', $ch['to'] ?? '', $ch['from'] ?? '', $ch['url'] ?? '', $ch['chat_id'] ?? '', $ch['smtp']['host'] ?? '']
    + (($x = array_filter([$ch['headers'] ?? [], $ch['target'] ?? '', $ch['map'] ?? [], ($ch['type'] ?? '') === 'php' && is_file($ch['file'] ?? '') ? hash_file('sha256', $ch['file']) : ''])) ? [6 => $x] : [])), zk_cfg()['secret']); }

function zk_mx(string $domain): array {
    if (isset(zk_cfg()['mx_resolver'])) return (zk_cfg()['mx_resolver'])($domain);
    $r = @dns_get_record($domain, DNS_MX);
    return $r ? array_column($r, 'target') : [];
}

/** Returns [mode, reason]. 'full' needs the owner's declaration AND nothing known may contradict it. */
function zk_policy(array $ch): array {
    $type = $ch['type'] ?? '';
    if ($type === 'telegram') return ['notice', 'telegram: servers abroad, notice only'];
    if (($ch['data'] ?? 'notice') !== 'full') return ['notice', 'configured'];
    if (($ch['in_russia'] ?? false) !== true) return ['notice', 'in_russia is not declared'];
    if ($type === 'email') {
        $s = $ch['smtp'] ?? [];
        if (empty($s['host'])) return ['notice', 'no smtp: the route of mail() is unknown'];
        foreach (array_filter([$s['host'], $s['peer_name'] ?? '']) as $h) {
            if (!zk_local($h) && !zk_hostname($h)) return ['notice', 'SMTP host must be a plain name'];
            if (zk_listed($h, ZK_FOREIGN_MAIL)) return ['notice', 'foreign SMTP'];
        }
        if (str_contains((string) ($s['user'] ?? ''), '@') && zk_listed(zk_domain($s['user']), ZK_FOREIGN_MAIL)) return ['notice', 'foreign SMTP account'];
        foreach (['to' => 'mailbox', 'from' => 'sender'] as $k => $what) {            // bounces return to the sender with the text inside
            $d = zk_domain((string) ($ch[$k] ?? ''));
            if (zk_listed($d, ZK_FOREIGN_MAIL)) return ['notice', "foreign $what"];
            $mx = zk_mx($d);
            if (!$mx) return ['notice', "MX of the $what is unknown"];
            foreach ($mx as $m) if (zk_listed($m, ZK_FOREIGN_MAIL)) return ['notice', "$what is hosted abroad (MX)"];
        }
        return ['full', 'declared in Russia'];
    }
    if ($type === 'webhook') {
        $h = strtolower(rtrim((string) parse_url((string) ($ch['url'] ?? ''), PHP_URL_HOST), '.'));
        if (!zk_local($h) && !zk_hostname($h)) return ['notice', 'an address instead of a host name'];
        if (zk_listed($h, ZK_FOREIGN_HOOK)) return ['notice', 'foreign service'];
        return ['full', 'declared in Russia'];
    }
    if ($type === 'php') {
        if (!is_string($ch['target'] ?? null) || $ch['target'] === '') return ['notice', 'target is not named'];
        return ['full', 'declared in Russia: own code, the program cannot see where it sends'];
    }
    return ['notice', 'unknown channel'];
}

function zk_notice(array $lead): string {
    $c = zk_cfg();
    $form = $c['forms'][$lead['form']] ?? 'form';                                     // label from config, never visitor input
    return 'Новая заявка ' . zk_key((int) $lead['id']) . ($c['notice_show_form'] ? "\nФорма: " . $form : '') . "\nПолучена: " . date('d.m.Y H:i', (int) $lead['created_at'])
        . ($c['admin_url'] ? "\nОткрыть (нужен вход): " . $c['admin_url'] . '?id=' . $lead['id'] : '');
}

function zk_full(array $lead): string {
    $c = zk_cfg(); $t = zk_notice($lead) . "\n";
    foreach ((array) json_decode($lead['fields'], true) as $k => $v) $t .= "\n" . ($c['fields'][$k]['label'] ?? $k) . ': ' . str_replace("\n", "\n    ", (string) $v);
    return $t . "\n\nСтраница: " . ($lead['page'] ?: '-') . "\nСогласие: " . $lead['consent_id'] . ' (sha256 ' . substr($lead['consent_sha'], 0, 12) . '), ' . $lead['consent_at'];
}

/* ---------- own formats: a CRM gets the body it expects ---------- */

/** What {placeholders} of a map become. In notice mode everything personal is an empty string. */
function zk_values(array $lead, string $mode): array {
    $c = zk_cfg(); $full = $mode === 'full';
    $v = ['key' => zk_key((int) $lead['id']), 'delivery' => (string) $lead['did'], 'created_at' => date('c', (int) $lead['created_at']),
        'form' => $full || $c['notice_show_form'] ? $lead['form'] : '', 'form_label' => $full || $c['notice_show_form'] ? (string) ($c['forms'][$lead['form']] ?? '') : '',
        'link' => $c['admin_url'] ? $c['admin_url'] . '?id=' . $lead['id'] : '', 'page' => $full ? $lead['page'] : '', 'text' => $full ? zk_full($lead) : zk_notice($lead),
        'consent_id' => $full ? $lead['consent_id'] : '', 'consent_sha256' => $full ? $lead['consent_sha'] : '', 'consent_at' => $full ? $lead['consent_at'] : ''];
    $f = $full ? (array) json_decode($lead['fields'], true) : [];
    foreach (array_keys($c['fields']) as $k) $v['field:' . $k] = (string) ($f[$k] ?? '');
    return $v;
}

/** Fills a map of any depth. Values are inserted once and never read as a template. A branch whose placeholders are all empty is left out. */
function zk_fill($m, array $v, bool &$had = false, bool &$some = false) {
    if (is_string($m)) {
        $h = $s = false;
        $r = preg_replace_callback('/\{([^{}]*)\}/', function (array $x) use ($v, &$h, &$s): string {
            if (!isset($v[$x[1]])) return $x[0];
            $h = true; $s = $s || $v[$x[1]] !== '';
            return $v[$x[1]];
        }, $m);
        $had = $h; $some = $s;
        return $r;
    }
    if (!is_array($m)) return $m;
    $out = []; $list = zk_is_list($m);
    foreach ($m as $k => $x) {
        $h = $s = false; $r = zk_fill($x, $v, $h, $s);
        $had = $had || $h; $some = $some || $s;
        if ($h && !$s) continue;
        if ($list) $out[] = $r; else $out[$k] = $r;
    }
    return $out;
}
function zk_is_list(array $a): bool { return $a === [] || array_keys($a) === range(0, count($a) - 1); }

/** Placeholders of a map that mean nothing: doctor names them. */
function zk_map_unknown($m): array {
    if (is_array($m)) return array_merge([], ...array_map('zk_map_unknown', array_values($m)));
    if (!is_string($m) || !preg_match_all('/\{([^{}]*)\}/', $m, $x)) return [];
    $known = ['key', 'delivery', 'created_at', 'form', 'form_label', 'link', 'page', 'text', 'consent_id', 'consent_sha256', 'consent_at'];
    foreach (array_keys(zk_cfg()['fields']) as $k) $known[] = 'field:' . $k;
    return array_values(array_diff($x[1], $known));
}

/** Extra headers of a webhook: only names that cannot change how the request is read. */
function zk_headers(array $ch): array {
    $out = [];
    foreach ((array) ($ch['headers'] ?? []) as $k => $x) {
        if (!is_string($x) || !preg_match('/^(X-(?!Zayavka-)[A-Za-z0-9-]+|Authorization|Accept)$/i', (string) $k) || preg_match('/[\r\n\x00]/', (string) $x)) throw new RuntimeException('bad header');
        $out[] = $k . ': ' . $x;
    }
    return $out;
}

/** Own code runs in a separate process: it cannot hold the queue, stop the run or print the lead into the mail of cron. Only its success is known. */
function zk_own(array $ch, array $values): void {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('own code runs from cron only');
    if (!is_string($ch['file'] ?? null) || !is_file($ch['file'])) throw new RuntimeException('own code: file not found');
    if (!function_exists('proc_open')) throw new RuntimeException('own code: proc_open is disabled');
    $null = ['file', '/dev/null', 'w'];
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', dirname(__DIR__) . '/bin/own', $ch['file'], (string) max(1, min(60, (int) ($ch['timeout'] ?? 15)))], [0 => ['pipe', 'r'], 1 => $null, 2 => $null, 3 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('own code: cannot start');
    $end = microtime(true) + max(1, min(60, (int) ($ch['timeout'] ?? 15))); $said = '';
    foreach ([0, 3] as $i) stream_set_blocking($pipes[$i], false);
    $in = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    while (microtime(true) < $end) {
        if ($in !== '') { $n = @fwrite($pipes[0], $in); if ($n === false) break; $in = substr($in, $n); if ($in === '') fclose($pipes[0]); }
        $said .= (string) @fread($pipes[3], 16);
        if ($said !== '' || !proc_get_status($p)['running']) break;
        usleep(20000);
    }
    if ($said === '') $said = (string) @fread($pipes[3], 16);
    if (is_resource($pipes[0])) fclose($pipes[0]);
    fclose($pipes[3]); proc_terminate($p, 9); proc_close($p);
    if ($said !== 'done') throw new RuntimeException($said === 'fail' ? 'own code failed' : 'own code did not finish');
}

/* ---------- sending ---------- */

function zk_connect(string $host, int $port, bool $tls, array $ssl, float $end) {
    $f = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . $host . ':' . $port, $no, $err, max(1.0, min(8.0, $end - microtime(true))), STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl + ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]));
    if (!$f) throw new RuntimeException('cannot connect');
    stream_set_blocking($f, false);
    return $f;
}
function zk_put($f, string $d, float $end): void {
    while ($d !== '') {
        $n = @fwrite($f, $d);
        if ($n === false || microtime(true) > $end) throw new RuntimeException('cannot write');
        if ($n === 0) usleep(10000);
        $d = substr($d, $n);
    }
}
/** One line, at most 4 KB, with a hard deadline: a slow or endless answer cannot hold the queue. */
function zk_line($f, string &$buf, float $end): string {
    while (($p = strpos($buf, "\n")) === false) {
        if (strlen($buf) > 4096 || microtime(true) > $end) throw new RuntimeException('no answer');
        $d = @fread($f, 1024);
        if ($d === '' || $d === false) { if (feof($f)) throw new RuntimeException('connection closed'); usleep(10000); continue; }
        $buf .= $d;
    }
    $l = rtrim(substr($buf, 0, $p), "\r"); $buf = substr($buf, $p + 1);
    return $l;
}

/** The receiver answered and refused this one lead: the next lead may go through. */
final class ZkRejected extends RuntimeException {}

/** Own client: only the status line is read, redirects are not followed because a redirect could lead abroad. */
function zk_http(string $url, string $body, array $headers): void {
    $p = parse_url($url) ?: []; $host = strtolower($p['host'] ?? ''); $tls = ($p['scheme'] ?? '') === 'https';
    if (!$tls && !(($p['scheme'] ?? '') === 'http' && zk_local($host))) throw new RuntimeException('https only');
    if (isset($p['user']) || (!zk_local($host) && !zk_hostname(rtrim($host, '.')))) throw new RuntimeException('bad host');
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    foreach (array_merge($headers, [$path]) as $h) if (preg_match('/[\r\n]/', $h)) throw new RuntimeException('bad request');
    $end = microtime(true) + 10; $buf = '';
    $f = zk_connect($host, $p['port'] ?? ($tls ? 443 : 80), $tls, [], $end);
    try {
        zk_put($f, "POST $path HTTP/1.1\r\nHost: $host" . (isset($p['port']) ? ':' . $p['port'] : '') . "\r\nUser-Agent: zayavka\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body, $end);
        $l = zk_line($f, $buf, $end);
        if (!preg_match('~^HTTP/1\.[01] (\d{3})~', $l, $m)) throw new RuntimeException('http: bad answer');
        if ($m[1][0] === '2') return;
        // the receiver is alive and has answered: perhaps it refuses this lead only. 401, 429 and 503 are about the channel
        throw in_array($m[1], ['401', '429', '503'], true) ? new RuntimeException('http ' . $m[1]) : new ZkRejected('http ' . $m[1]);
    } finally { fclose($f); }
}

function zk_smtp(array $s, string $from, string $to, string $msg): void {
    $end = microtime(true) + 20; $buf = '';
    $f = zk_connect($s['host'], (int) ($s['port'] ?? 465), true, ['peer_name' => $s['peer_name'] ?? $s['host']] + (isset($s['cafile']) ? ['cafile' => $s['cafile']] : []), $end);
    $say = function (?string $cmd, int $want, bool $content = false) use ($f, &$buf, $end): void {
        if ($cmd !== null) zk_put($f, $cmd . "\r\n", $end);
        do { $l = zk_line($f, $buf, $end); } while (isset($l[3]) && $l[3] === '-');
        if ((int) $l !== $want) { $t = 'smtp expected ' . $want . ' got ' . (int) $l; throw $content && (int) $l >= 550 && (int) $l <= 554 ? new ZkRejected($t) : new RuntimeException($t); }
    };
    try {
        $say(null, 220); $say('EHLO localhost', 250);
        $say('AUTH LOGIN', 334); $say(base64_encode($s['user']), 334); $say(base64_encode($s['pass']), 235);
        $say("MAIL FROM:<$from>", 250); $say("RCPT TO:<$to>", 250); $say('DATA', 354);
        $say(preg_replace('/^\./m', '..', $msg) . "\r\n.", 250, true);
        zk_put($f, "QUIT\r\n", $end);
    } finally { fclose($f); }
}

/** A plain word to the owner, never about a person: once a day through every mailbox and chat. */
function zk_alert(string $what, string $text): void {
    if (zk_state('alert ' . $what) === date('Y-m-d')) return;
    zk_state('alert ' . $what, date('Y-m-d'));
    foreach (zk_cfg()['channels'] as $ch) try {
        if (($ch['type'] ?? '') === 'telegram') zk_telegram($ch, $text);
        if (($ch['type'] ?? '') === 'email') zk_mail($ch, 'zayavka', $text, 'alert.' . date('Ymd') . '.' . bin2hex(random_bytes(4)));
    } catch (Throwable $e) { error_log('zayavka alert: ' . get_class($e)); }
}

function zk_telegram(array $ch, string $text): void {
    zk_http(($ch['api'] ?? 'https://api.telegram.org') . '/bot' . $ch['token'] . '/sendMessage',
        http_build_query(['chat_id' => $ch['chat_id'], 'text' => $text, 'disable_web_page_preview' => 'true']), ['Content-Type: application/x-www-form-urlencoded']);
}

function zk_email(array $ch, array $lead, string $mode): void {
    zk_mail($ch, 'Заявка ' . zk_key((int) $lead['id']), $mode === 'full' ? zk_full($lead) : zk_notice($lead), $lead['did'] . '.' . substr($lead['idem'], 0, 16));
}
function zk_mail(array $ch, string $subject, string $text, string $id): void {
    foreach (['to', 'from'] as $k) if (!filter_var($ch[$k] ?? '', FILTER_VALIDATE_EMAIL)) throw new RuntimeException("email: bad $k");
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $h = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64', 'From: <' . $ch['from'] . '>', 'Date: ' . date('r'), 'Message-ID: <zayavka.' . $id . strrchr($ch['from'], '@') . '>'];
    $body = chunk_split(base64_encode($text));
    if (!empty($ch['smtp'])) { zk_smtp($ch['smtp'], $ch['from'], $ch['to'], implode("\r\n", array_merge($h, ['To: <' . $ch['to'] . '>', 'Subject: ' . $subj])) . "\r\n\r\n" . $body); return; }
    if (!mail($ch['to'], $subj, $body, implode("\r\n", $h))) throw new RuntimeException('mail() failed');
}

function zk_send(array $ch, array $lead): string {
    [$mode] = zk_policy($ch);
    if ($mode === 'full' && !($lead['declared'] && hash_equals($lead['target'], zk_target($ch)))) $mode = 'notice';
    switch ($ch['type'] ?? '') {
        case 'email': zk_email($ch, $lead, $mode); break;
        case 'telegram':
            zk_telegram($ch, zk_notice($lead)); break;
        case 'webhook':
            $d = ['delivery' => (int) $lead['did'], 'key' => zk_key((int) $lead['id']), 'created_at' => date('c', (int) $lead['created_at'])];
            if ($mode === 'full' || zk_cfg()['notice_show_form']) $d['form'] = $lead['form'];
            if ($mode === 'full') $d += ['fields' => json_decode($lead['fields'], true), 'page' => $lead['page'], 'consent_version' => $lead['consent_id'], 'consent_sha256' => $lead['consent_sha'], 'consent_at' => $lead['consent_at']];
            if (isset($ch['map'])) { $d = zk_fill((array) $ch['map'], zk_values($lead, $mode)); if ($d === [] && !zk_is_list((array) $ch['map'])) $d = new stdClass(); }
            $asForm = ($ch['format'] ?? 'json') === 'form';
            $j = $asForm ? http_build_query($d) : json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $hd = array_merge([$asForm ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json'], zk_headers($ch));
            if (!empty($ch['secret'])) { $ts = (string) time(); $hd[] = 'X-Zayavka-Timestamp: ' . $ts; $hd[] = 'X-Zayavka-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $j, $ch['secret']); }
            zk_http($ch['url'], $j, $hd); break;
        case 'php': zk_own($ch, zk_values($lead, $mode)); break;
        default: throw new RuntimeException('unknown channel type');
    }
    return $mode;
}

/** Sends what is due, at least once each. The attempt is counted before sending, so a crash cannot block the queue.
    Own code has a queue and a lock of its own, so it never holds the other channels. */
function zk_deliver(float $budget, bool $own = false): array {
    $c = zk_cfg(); zk_db();
    $lock = @fopen($c['storage'] . ($own ? '/own.lock' : '/deliver.lock'), 'ce');    // e: own code must not inherit the lock
    if (!$lock) throw new RuntimeException('deliver.lock is not writable by this user');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['sent' => 0, 'failed' => 0]; }
    $end = microtime(true) + $budget; $sent = $bad = 0; $down = $refused = [];
    $php = array_keys(array_filter($c['channels'], fn($x) => ($x['type'] ?? '') === 'php'));
    $q = 'SELECT id, channel, tries FROM deliveries WHERE status=? AND next_at<=? AND channel ' . ($own ? 'IN' : 'NOT IN') . ' (' . implode(',', array_fill(0, max(1, count($php)), '?')) . ') ORDER BY next_at, id LIMIT 200';
    foreach (zk_q($q, array_merge(['pending', time()], array_map('strval', $php) ?: ['']))->fetchAll() as $d) {
        if (microtime(true) >= $end) break;
        if (isset($down[$d['channel']])) continue;                                    // one silent channel must not hold the others
        $t = (int) $d['tries'] + 1;
        try {
            if (!zk_q("UPDATE deliveries SET tries=?, next_at=? WHERE id=? AND status='pending' AND tries=?", [$t, time() + [60, 300, 900, 3600][min($t - 1, 3)], $d['id'], $d['tries']])->rowCount()) continue;
            // read now, not at the start of the run: the lead may have been deleted while we were sending others
            $r = zk_q('SELECT d.id did, d.target, d.declared, l.* FROM deliveries d JOIN leads l ON l.id=d.lead_id WHERE d.id=?', [$d['id']])->fetch();
            if (!$r) continue;
            try {
                if (!isset($c['channels'][$d['channel']])) throw new RuntimeException('channel removed');
                $mode = zk_send($c['channels'][$d['channel']], $r);
                $sent++; zk_q("UPDATE deliveries SET status='sent', note=? WHERE id=?", [$mode, $d['id']]);
                if ($mode === 'full') zk_audit('system', 'sent in full', zk_key((int) $r['id']), 'channel: ' . $d['channel']);
            } catch (PDOException $e) { throw $e; } catch (Throwable $e) {
                $bad++; $refused[$d['channel']] = ($refused[$d['channel']] ?? 0) + 1;
                if (!$e instanceof ZkRejected || $refused[$d['channel']] >= 20) $down[$d['channel']] = true;
                zk_q('UPDATE deliveries SET status=?, note=? WHERE id=?', [$t >= ZK_TRIES ? 'failed' : 'pending', substr($e->getMessage(), 0, 120), $d['id']]);
            }
        } catch (PDOException $e) { error_log('zayavka: delivery ' . $d['id'] . ': database is busy'); }
    }
    flock($lock, LOCK_UN); fclose($lock);
    return ['sent' => $sent, 'failed' => $bad];
}

/* ---------- shared by the admin page and the command line ---------- */

/** Admin sessions live in the database as keyed hashes: no files, nothing is created before a correct password. */
function zk_session_key(string $token): string { return hash_hmac('sha256', 'session|' . $token, zk_cfg()['secret']); }
function zk_session_start(string $mark): string {
    $token = bin2hex(random_bytes(32));
    zk_q('DELETE FROM sessions WHERE seen<? OR since<?', [time() - 1800, time() - 28800]);
    zk_q('INSERT INTO sessions VALUES(?,?,?,?)', [zk_session_key($token), time(), time(), $mark]);
    return $token;
}
/** True while the session is fresh: 30 minutes idle, 8 hours in total, same password. */
function zk_session_ok(string $token, string $mark): bool {
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) return false;
    $s = zk_q('SELECT * FROM sessions WHERE h=?', [$k = zk_session_key($token)])->fetch();
    if (!$s) return false;
    if (time() - $s['seen'] > 1800 || time() - $s['since'] > 28800 || !hash_equals($s['mark'], $mark)) { zk_q('DELETE FROM sessions WHERE h=?', [$k]); return false; }
    zk_q('UPDATE sessions SET seen=? WHERE h=?', [time(), $k]);
    return true;
}
/** A browser that has logged in before, for 90 days or until logout or a new password. */
function zk_known_add(string $mark): string {
    $token = bin2hex(random_bytes(32));
    zk_q('DELETE FROM known WHERE since<?', [time() - 90 * 86400]);
    zk_q('INSERT INTO known VALUES(?,?,?)', [zk_session_key('known' . $token), time(), $mark]);
    return $token;
}
function zk_known(string $token, string $mark): bool {
    $m = zk_q('SELECT mark FROM known WHERE h=? AND since>=?', [zk_session_key('known' . $token), time() - 90 * 86400])->fetchColumn();
    return is_string($m) && hash_equals($m, $mark);
}
/** Form token: bound to the cookie of this browser, so it cannot be prepared elsewhere. */
function zk_token(string $cookie): string { return hash_hmac('sha256', 'form|' . $cookie, zk_cfg()['secret']); }

/** Spreadsheet formula injection, also after a separator, a quote or a line break inside the cell. Plain phone numbers stay as they are. */
function zk_csv_cell(string $v): string {
    if (!preg_match('/^[+\-]?[0-9][0-9 ()\-]*$/', $v)) {
        $out = ''; $start = true;                                                      // at the start of what some program may take for a cell
        foreach (mb_str_split($v, 1, 'UTF-8') as $x) {
            if ($start && in_array($x, ['=', '+', '-', '@', '|', '＝', '＋', '－', '＠'], true)) $out .= "'";
            $start = in_array($x, [',', ';', "\t", "\r", "\n", '|'], true) || ($start && in_array($x, [' ', '"', "'", "\u{A0}", "\u{3000}"], true));
            $out .= $x;
        }
        $v = $out;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}
function zk_csv(array $row): string { return implode(';', array_map(fn($v) => zk_csv_cell((string) $v), $row)) . "\r\n"; }

/** What the owner should know: shown on the admin page and by doctor. */
function zk_warnings(): array {
    $w = [];
    if ($n = (int) zk_q("SELECT COUNT(*) FROM deliveries WHERE status='failed'")->fetchColumn()) $w[] = "Не доставлено уведомлений: $n. Повтор: bin/zayavka retry";
    if ($n = (int) zk_q('SELECT COUNT(*) FROM leads WHERE suspect=1')->fetchColumn()) $w[] = "Подозрительных заявок (без уведомления): $n. Проверьте их в списке";
    foreach (zk_q('SELECT k, SUM(n) n FROM counters WHERE day>=? AND k NOT IN (?,?,?) GROUP BY k', [date('Y-m-d', time() - 7 * 86400), 'suspect', 'accepted', 'via proxy'])->fetchAll() as $r) $w[] = "За 7 дней: {$r['k']}: {$r['n']}";
    if (($o = zk_state('refused origin')) !== '') $w[] = "Последний отклонённый адрес сайта: $o. Добавляйте его в allowed_origins, только если это ваш сайт: такой запрос может прислать кто угодно";
    if ($n = (int) zk_q('SELECT COUNT(*) FROM leads WHERE suspect=2')->fetchColumn()) $w[] = "Сверх дневного предела (без уведомлений): $n. Откройте и разберите, хранятся обычный срок";
    if (zk_low_space()) $w[] = 'База заняла больше 80 % от max_db_mb: после 100 % форма перестанет принимать заявки';
    $sum = fn(string $k): int => (int) zk_q('SELECT SUM(n) FROM counters WHERE day>=? AND k=?', [date('Y-m-d', time() - 7 * 86400), $k])->fetchColumn();
    if (!zk_cfg()['trust_proxy'] && ($p = $sum('via proxy')) >= 5 && $p >= 0.9 * $sum('accepted')) $w[] = 'Похоже, сайт стоит за прокси: все посетители считаются одним адресом, см. trust_proxy в README. Не включайте trust_proxy, если прокси нет: предел заявок можно будет обойти';
    if (time() - (int) zk_state('last cron') > 900) $w[] = 'Cron не запускался больше 15 минут: повторы и удаление по сроку не работают';
    return $w;
}
