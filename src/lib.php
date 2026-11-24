<?php
declare(strict_types=1);

/* Zayavka: standalone form receiver. PHP 8.0+, SQLite, no libraries. MIT.
   The lead is written to the local database first; channels get a notice without personal data by default. */

const ZK_VERSION = '0.1.0';
const ZK_TRIES = 75;                                    // 1, 5, 15 minutes, then hourly: about three days
const ZK_FOREIGN_MAIL = '/(^|\.)(gmail\.com|googlemail\.com|google\.com|outlook\.[a-z.]+|hotmail\.[a-z.]+|live\.[a-z.]+|office365\.com|icloud\.com|me\.com|yahoo\.[a-z.]+|yahoodns\.net|proton\.me|protonmail\.[a-z]+|zoho\.[a-z.]+|fastmail\.[a-z]+|messagingengine\.com|gmx\.[a-z]+|mailgun\.org|sendgrid\.net|amazonaws\.com|amazonses\.com|mailjet\.com|postmarkapp\.com|brevo\.com|sendinblue\.com)$/i';
const ZK_FOREIGN_HOOK = '/(^|\.)(zapier\.com|make\.com|integromat\.com|slack\.com|discord\.com|discordapp\.com|telegram\.org|google\.com|googleapis\.com|azure\.com|azurewebsites\.net|windows\.net|office\.com|amazonaws\.com|cloudfunctions\.net|run\.app|vercel\.app|netlify\.app|workers\.dev|herokuapp\.com|n8n\.cloud|airtable\.com|notion\.com|hubspot\.com|hubapi\.com|salesforce\.com|pipedrive\.com|webhook\.site|web3forms\.com|formspree\.io|forminit\.com|getform\.io)$/i';

ini_set('display_errors', '0');                         // a warning must never reach the visitor or break the JSON answer

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
    try { $c0 = require $f; } finally { ob_end_clean(); }
    if (!is_array($c0)) throw new RuntimeException('config.php must return an array');
    $c0 += [
        'allowed_origins' => [], 'forms' => [], 'fields' => [], 'consents' => [], 'secret' => '', 'web_roots' => [],
        'storage' => zk_dir() . '/storage', 'retention_days' => 365, 'suspect_days' => 30, 'audit_days' => 1096, 'max_bytes' => 32768,
        'rate_limit' => 10, 'rate_window' => 600, 'max_per_day' => 2000, 'trust_proxy' => false, 'channels' => [], 'deliver_on_submit' => true,
        'admin_password_hash' => '', 'admin_url' => '', 'notice_show_form' => true, 'thanks_url' => '/', 'timezone' => 'Europe/Moscow', 'require_any' => [],
    ];
    foreach (['allowed_origins', 'forms', 'fields', 'consents', 'secret', 'web_roots'] as $k)
        if (!$c0[$k]) throw new RuntimeException("config: $k is empty");
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
    foreach ($roots as $r) {
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
        if (!is_file("$f.ready") || !is_file($f)) {                     // first requests may be parallel: one creates, the others wait before opening
            $lock = fopen("$st/init.lock", 'c');
            if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('cannot lock storage');
        }
        $d = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
        $d->exec('PRAGMA foreign_keys=ON; PRAGMA secure_delete=ON');
        if ($lock && (int) $d->query('PRAGMA user_version')->fetchColumn() === 0) {
            $d->exec('PRAGMA journal_mode=WAL');
            $d->exec("BEGIN; CREATE TABLE leads(id INTEGER PRIMARY KEY AUTOINCREMENT, idem TEXT UNIQUE NOT NULL, form TEXT NOT NULL, page TEXT NOT NULL, fields TEXT NOT NULL, consent_id TEXT NOT NULL, consent_sha TEXT NOT NULL, consent_at TEXT NOT NULL, created_at INTEGER NOT NULL, suspect INTEGER NOT NULL DEFAULT 0);
                CREATE TABLE consents(sha TEXT PRIMARY KEY, id TEXT NOT NULL, text TEXT NOT NULL, first_seen TEXT NOT NULL);
                CREATE TABLE deliveries(id INTEGER PRIMARY KEY, lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE, channel TEXT NOT NULL, target TEXT NOT NULL, declared INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending', tries INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', UNIQUE(lead_id, channel));
                CREATE INDEX deliveries_due ON deliveries(status, next_at);
                CREATE TABLE hits(h TEXT NOT NULL, exp INTEGER NOT NULL); CREATE INDEX hits_h ON hits(h, exp);
                CREATE TABLE counters(day TEXT NOT NULL, k TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY(day, k));
                CREATE TABLE state(k TEXT PRIMARY KEY, v TEXT NOT NULL);
                CREATE TABLE audit(ts INTEGER NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, lead TEXT NOT NULL DEFAULT '', info TEXT NOT NULL DEFAULT '');
                PRAGMA user_version=1; COMMIT");
        }
        if ($lock) touch("$f.ready");
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

function zk_purge(): int {
    $c = zk_cfg(); $n = 0;
    foreach ([[(int) $c['retention_days'], 0], [(int) $c['suspect_days'], 1]] as [$days, $suspect]) {
        if ($days <= 0) continue;
        foreach (zk_q('SELECT id FROM leads WHERE suspect>=? AND created_at<? LIMIT 500', [$suspect, time() - $days * 86400])->fetchAll(PDO::FETCH_COLUMN) as $id) $n += (int) zk_delete((int) $id, 'system', 'retention');
    }
    zk_q('DELETE FROM hits WHERE exp<=?', [time()]);
    zk_q('DELETE FROM audit WHERE ts<?', [time() - (int) $c['audit_days'] * 86400]);
    zk_q('DELETE FROM counters WHERE day<?', [date('Y-m-d', time() - 90 * 86400)]);
    if ($n) zk_checkpoint();
    return $n;
}

/* ---------- accepting a lead ---------- */

function zk_clean(string $v, bool $multiline): string {
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    if (!$multiline) $v = preg_replace('/[\n\t\x{85}\x{2028}\x{2029}]+/u', ' ', $v);
    return trim($v);
}

/** Host and path of our own site only. Query is dropped; path parts that look like an address or a phone are hidden. */
function zk_page($raw): string {
    if (!is_string($raw) || strlen($raw) > 2000 || !preg_match('~^(https?://[^/?#\s]+)([^?#\s]*)~iu', $raw, $m)) return '';
    $o = zk_origin($m[1]);
    if ($o === null || !in_array($o, zk_cfg()['allowed_origins'], true)) return '';
    $parts = array_map(function (string $p): string {
        $d = rawurldecode($p);
        return !mb_check_encoding($d, 'UTF-8') || str_contains($d, '@') || strlen(preg_replace('/\D/', '', $d)) >= 7 || preg_match('/[\x00-\x1F\x7F]/', $d) ? '***' : $p;
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
    if (zk_limited('submit', (int) $c['rate_limit'], (int) $c['rate_window']) || zk_limited('day', (int) $c['max_per_day'], 86400, '*')) return $no(429, 'rate');

    $trap = $in['zk_hp'] ?? '';
    $suspect = !is_string($trap) || trim($trap) !== '' ? 1 : 0;                       // kept and marked: a password manager may fill the trap for a person
    $text = (string) $c['consents'][$cid]; $sha = hash('sha256', $text);
    $id = zk_tx(function () use ($c, $keys, $find, $form, $in, $body, $out, $cid, $sha, $text, $suspect): int {
        zk_q('INSERT INTO consents VALUES(?,?,?,?) ON CONFLICT(sha) DO NOTHING', [$sha, $cid, $text, date('c')]);
        if ($old = $find()) return (int) $old;                                        // a parallel twin was first
        zk_q('INSERT INTO leads(idem,form,page,fields,consent_id,consent_sha,consent_at,created_at,suspect) VALUES(?,?,?,?,?,?,?,?,?)',
            [$keys[0], $form, zk_page($in['page'] ?? ''), json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), $cid, $sha, date('c'), time(), $suspect]);
        $id = (int) zk_db()->lastInsertId();
        if ($suspect) zk_count('suspect');
        else foreach ($c['channels'] as $n => $ch) zk_q('INSERT INTO deliveries(lead_id,channel,target,declared) VALUES(?,?,?,?)', [$id, $n, zk_target($ch), (int) zk_declared($ch)]);
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
function zk_target(array $ch): string { return hash_hmac('sha256', json_encode([$ch['type'] ?? '', $ch['to'] ?? '', $ch['from'] ?? '', $ch['url'] ?? '', $ch['chat_id'] ?? '', $ch['smtp']['host'] ?? '']), zk_cfg()['secret']); }

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

/* ---------- sending ---------- */

function zk_connect(string $host, int $port, bool $tls, array $ssl, float $end) {
    $f = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . $host . ':' . $port, $no, $err, max(1.0, min(8.0, $end - microtime(true))), STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl + ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]));
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
        if ($m[1][0] !== '2') throw new RuntimeException('http ' . $m[1]);
    } finally { fclose($f); }
}

function zk_smtp(array $s, string $from, string $to, string $msg): void {
    $end = microtime(true) + 20; $buf = '';
    $f = zk_connect($s['host'], (int) ($s['port'] ?? 465), true, ['peer_name' => $s['peer_name'] ?? $s['host']] + (isset($s['cafile']) ? ['cafile' => $s['cafile']] : []), $end);
    $say = function (?string $cmd, int $want) use ($f, &$buf, $end): void {
        if ($cmd !== null) zk_put($f, $cmd . "\r\n", $end);
        do { $l = zk_line($f, $buf, $end); } while (isset($l[3]) && $l[3] === '-');
        if ((int) $l !== $want) throw new RuntimeException('smtp expected ' . $want . ' got ' . (int) $l);
    };
    try {
        $say(null, 220); $say('EHLO localhost', 250);
        $say('AUTH LOGIN', 334); $say(base64_encode($s['user']), 334); $say(base64_encode($s['pass']), 235);
        $say("MAIL FROM:<$from>", 250); $say("RCPT TO:<$to>", 250); $say('DATA', 354);
        $say(preg_replace('/^\./m', '..', $msg) . "\r\n.", 250);
        zk_put($f, "QUIT\r\n", $end);
    } finally { fclose($f); }
}

function zk_email(array $ch, array $lead, string $mode): void {
    foreach (['to', 'from'] as $k) if (!filter_var($ch[$k] ?? '', FILTER_VALIDATE_EMAIL)) throw new RuntimeException("email: bad $k");
    $subj = '=?UTF-8?B?' . base64_encode('Заявка ' . zk_key((int) $lead['id'])) . '?=';
    $h = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64', 'From: <' . $ch['from'] . '>', 'Date: ' . date('r'), 'Message-ID: <zayavka.' . $lead['did'] . '.' . substr($lead['idem'], 0, 16) . strrchr($ch['from'], '@') . '>'];
    $body = chunk_split(base64_encode($mode === 'full' ? zk_full($lead) : zk_notice($lead)));
    if (!empty($ch['smtp'])) { zk_smtp($ch['smtp'], $ch['from'], $ch['to'], implode("\r\n", array_merge($h, ['To: <' . $ch['to'] . '>', 'Subject: ' . $subj])) . "\r\n\r\n" . $body); return; }
    if (!mail($ch['to'], $subj, $body, implode("\r\n", $h))) throw new RuntimeException('mail() failed');
}

function zk_send(array $ch, array $lead): string {
    [$mode] = zk_policy($ch);
    if ($mode === 'full' && !($lead['declared'] && hash_equals($lead['target'], zk_target($ch)))) $mode = 'notice';
    switch ($ch['type'] ?? '') {
        case 'email': zk_email($ch, $lead, $mode); break;
        case 'telegram':
            zk_http(($ch['api'] ?? 'https://api.telegram.org') . '/bot' . $ch['token'] . '/sendMessage',
                http_build_query(['chat_id' => $ch['chat_id'], 'text' => zk_notice($lead), 'disable_web_page_preview' => 'true']), ['Content-Type: application/x-www-form-urlencoded']); break;
        case 'webhook':
            $d = ['delivery' => (int) $lead['did'], 'key' => zk_key((int) $lead['id']), 'created_at' => date('c', (int) $lead['created_at'])];
            if ($mode === 'full' || zk_cfg()['notice_show_form']) $d['form'] = $lead['form'];
            if ($mode === 'full') $d += ['fields' => json_decode($lead['fields'], true), 'page' => $lead['page'], 'consent_version' => $lead['consent_id'], 'consent_sha256' => $lead['consent_sha'], 'consent_at' => $lead['consent_at']];
            $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $hd = ['Content-Type: application/json'];
            if (!empty($ch['secret'])) { $ts = (string) time(); $hd[] = 'X-Zayavka-Timestamp: ' . $ts; $hd[] = 'X-Zayavka-Signature: sha256=' . hash_hmac('sha256', $ts . '.' . $j, $ch['secret']); }
            zk_http($ch['url'], $j, $hd); break;
        default: throw new RuntimeException('unknown channel type');
    }
    return $mode;
}

/** Sends what is due, at least once each. The attempt is counted before sending, so a crash cannot block the queue. */
function zk_deliver(float $budget): array {
    $c = zk_cfg(); zk_db();
    $lock = @fopen($c['storage'] . '/deliver.lock', 'c');
    if (!$lock) throw new RuntimeException('deliver.lock is not writable by this user');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['sent' => 0, 'failed' => 0]; }
    $end = microtime(true) + $budget; $sent = $bad = 0; $down = [];
    foreach (zk_q("SELECT id, channel, tries FROM deliveries WHERE status='pending' AND next_at<=? ORDER BY next_at, id LIMIT 200", [time()])->fetchAll() as $d) {
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
                $bad++; $down[$d['channel']] = true;
                zk_q('UPDATE deliveries SET status=?, note=? WHERE id=?', [$t >= ZK_TRIES ? 'failed' : 'pending', substr($e->getMessage(), 0, 120), $d['id']]);
            }
        } catch (PDOException $e) { error_log('zayavka: delivery ' . $d['id'] . ': database is busy'); }
    }
    flock($lock, LOCK_UN); fclose($lock);
    return ['sent' => $sent, 'failed' => $bad];
}

/* ---------- shared by the admin page and the command line ---------- */

/** Spreadsheet formula injection, also after a comma or a line break inside the cell. Plain phone numbers stay as they are. */
function zk_csv_cell(string $v): string {
    if (!preg_match('/^[+\-]?[0-9][0-9 ()\-]*$/', $v)) {
        if (preg_match('/^\s*[=+\-@|]/u', $v)) $v = "'" . $v;
        $v = preg_replace('/([,;\t\r\n|]\s*)(?=[=@]|[+\-][A-Za-z])/u', "$1'", $v);
    }
    return '"' . str_replace('"', '""', $v) . '"';
}
function zk_csv(array $row): string { return implode(';', array_map(fn($v) => zk_csv_cell((string) $v), $row)) . "\r\n"; }

/** What the owner should know: shown on the admin page and by doctor. */
function zk_warnings(): array {
    $w = [];
    if ($n = (int) zk_q("SELECT COUNT(*) FROM deliveries WHERE status='failed'")->fetchColumn()) $w[] = "Не доставлено уведомлений: $n. Повтор: bin/zayavka retry";
    if ($n = (int) zk_q('SELECT COUNT(*) FROM leads WHERE suspect=1')->fetchColumn()) $w[] = "Подозрительных заявок (без уведомления): $n. Проверьте их в списке";
    foreach (zk_q('SELECT k, SUM(n) n FROM counters WHERE day>=? AND k<>? GROUP BY k', [date('Y-m-d', time() - 7 * 86400), 'suspect'])->fetchAll() as $r) $w[] = "За 7 дней: {$r['k']}: {$r['n']}";
    if (($o = zk_state('refused origin')) !== '') $w[] = "Последний отклонённый адрес сайта: $o. Если он ваш, добавьте его в allowed_origins";
    if (!zk_cfg()['trust_proxy'] && zk_state('proxy seen') !== '') $w[] = 'Похоже, сайт стоит за прокси: все посетители считаются одним адресом, см. trust_proxy в README';
    if (time() - (int) zk_state('last cron') > 900) $w[] = 'Cron не запускался больше 15 минут: повторы и удаление по сроку не работают';
    return $w;
}
