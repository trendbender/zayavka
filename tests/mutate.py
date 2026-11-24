#!/usr/bin/env python3
"""Removes protections one by one in a copy and checks that tests/run.py notices. Takes several minutes."""
import concurrent.futures as cf, os, shutil, subprocess, sys, tempfile
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
L, S, A, Z = "src/lib.php", "public/submit.php", "public/admin.php", "bin/zayavka"
M = [
 ("consent is not checked", L, "if (!in_array($in['consent'] ?? null, [true, 1, '1', 'on', 'yes'], true)) return $no(422, 'consent');", ""),
 ("any consent edition", L, "if (!isset($c['consents'][$cid])) return $no(409, 'consent_version');", ""),
 ("first edition is always recorded", L, "$text = (string) $c['consents'][$cid];", "$cid = (string) array_key_first($c['consents']); $text = (string) $c['consents'][$cid];"),
 ("any form name", L, "if (!isset($c['forms'][$form])) return $no(422, 'form');", ""),
 ("no length limit", L, "if (mb_strlen($v) > ($f['max'] ?? 500)) return $no(422, $name);", ""),
 ("line breaks stay", L, "if (!$multiline) $v =", "if (false) $v ="),
 ("page of any site", L, "if ($o === null || !in_array($o, zk_cfg()['allowed_origins'], true)) return '';", ""),
 ("personal data in page path", L, "str_contains($d, '@') || strlen(preg_replace('/\\D/', '', $d)) >= 7 || ", ""),
 ("page without UTF-8 check", L, "([^?#\\s]*)~iu', $raw, $m)", "([^?#\\s]*)~i', $raw, $m)"),
 ("any origin", S, "if ($o === null || !in_array($o, $c['allowed_origins'], true)) {", "if (false) {"),
 ("origin null trusted", S, "&& ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin') $o", ") $o"),
 ("no size limit", S, "elseif ((int) $len > $c['max_bytes'])", "elseif (false)"),
 ("any content type", S, "if ($ct !== 'application/json' && $ct !== 'application/x-www-form-urlencoded') [$code, $d]", "if (false) [$code, $d]"),
 ("warnings are shown", L, "ini_set('display_errors', '0');", "ini_set('display_errors', '1'); echo $undefined;"),
 ("trap ignored", L, "$suspect = !is_string($trap) || trim($trap) !== '' ? 1 : 0;", "$suspect = 0;"),
 ("suspect leads are announced", L, "if ($suspect) zk_count('suspect');\n        else foreach", "foreach"),
 ("repeat ignores content", L, "hash_hmac('sha256', $s . '|' . $body, $c['secret'])", "hash_hmac('sha256', $s, $c['secret'])"),
 ("twins are not looked up inside the transaction", L, "if ($old = $find()) return (int) $old;", ""),
 ("no rate limit", L, "if (zk_limited('submit', (int) $c['rate_limit'], (int) $c['rate_window']) || ", "if ("),
 ("no daily cap", L, " || zk_limited('day', (int) $c['max_per_day'], 86400, '*')) return", ") return"),
 ("limit is not atomic", L, "    return zk_tx(function () use ($h, $max, $window): bool {", "    return (function () use ($h, $max, $window): bool {"),
 ("X-Real-IP always trusted", L, "if (zk_cfg()['trust_proxy'] && filter_var($x, FILTER_VALIDATE_IP)) $ip = $x;", "if (filter_var($x, FILTER_VALIDATE_IP)) $ip = $x;"),
 ("full IPv6", L, ": substr($b, 0, 8);", ": $b;"),
 ("address stored", L, "$h = hash_hmac('sha256', $scope . '|' . ($who !== '' ? $who : zk_ip()), zk_cfg()['secret']);\n    return", "$h = $scope . '|' . ($who !== '' ? $who : zk_ip());\n    return"),
 ("telegram gets data", L, "'text' => zk_notice($lead)", "'text' => zk_full($lead)"),
 ("full without in_russia", L, "if (($ch['in_russia'] ?? false) !== true) return ['notice', 'in_russia is not declared'];", ""),
 ("full by default", L, "if (($ch['data'] ?? 'notice') !== 'full') return ['notice', 'configured'];", ""),
 ("no SMTP list", L, "if (zk_listed($h, ZK_FOREIGN_MAIL)) return ['notice', 'foreign SMTP'];", ""),
 ("SMTP host by IP", L, "if (!zk_local($h) && !zk_hostname($h)) return ['notice', 'SMTP host must be a plain name'];", ""),
 ("peer_name is not checked", L, "array_filter([$s['host'], $s['peer_name'] ?? ''])", "[$s['host']]"),
 ("SMTP account is not checked", L, "if (str_contains((string) ($s['user'] ?? ''), '@')", "if (false"),
 ("mail() for full", L, "if (empty($s['host'])) return ['notice', 'no smtp: the route of mail() is unknown'];", "if (empty($s['host'])) return ['full', 'x'];"),
 ("sender is not checked", L, "['to' => 'mailbox', 'from' => 'sender']", "['to' => 'mailbox']"),
 ("recipient is not checked", L, "['to' => 'mailbox', 'from' => 'sender']", "['from' => 'sender']"),
 ("MX is not checked", L, "foreach ($mx as $m) if (zk_listed($m, ZK_FOREIGN_MAIL)) return", "foreach ($mx as $m) if (false) return"),
 ("unknown MX is fine", L, "if (!$mx) return ['notice', \"MX of the $what is unknown\"];", ""),
 ("webhook list", L, "if (zk_listed($h, ZK_FOREIGN_HOOK)) return ['notice', 'foreign service'];", ""),
 ("webhook by IP", L, "if (!zk_local($h) && !zk_hostname($h)) return ['notice', 'an address instead of a host name'];", ""),
 ("declaration at acceptance ignored", L, "if ($mode === 'full' && !($lead['declared'] && hash_equals($lead['target'], zk_target($ch)))) $mode = 'notice';", ""),
 ("changed receiver ignored", L, "$lead['declared'] && hash_equals($lead['target'], zk_target($ch))", "$lead['declared']"),
 ("plain http", L, "if (!$tls && !(($p['scheme'] ?? '') === 'http' && zk_local($host))) throw new RuntimeException('https only');", ""),
 ("any host", L, "if (isset($p['user']) || (!zk_local($host) && !zk_hostname(rtrim($host, '.')))) throw new RuntimeException('bad host');", ""),
 ("certificate is not verified", L, "'verify_peer' => true, 'verify_peer_name' => true", "'verify_peer' => false, 'verify_peer_name' => false"),
 ("no deadline", L, "if (strlen($buf) > 4096 || microtime(true) > $end) throw", "if (strlen($buf) > 4096 || microtime(true) > $end + 40) throw"),
 ("endless line", L, "if (strlen($buf) > 4096 || microtime(true) > $end) throw", "if (microtime(true) > $end + 40) throw"),
 ("silent channel is not skipped", L, "if (isset($down[$d['channel']])) continue;", ""),
 ("no delivery lock", L, "if (!flock($lock, LOCK_EX | LOCK_NB)) {", "if (false) {"),
 ("attempt is not counted", L, "UPDATE deliveries SET tries=?, next_at=? WHERE id=? AND", "UPDATE deliveries SET tries=tries+0*?, next_at=0*? WHERE id=? AND"),
 ("never gives up", L, "$t >= ZK_TRIES ? 'failed' : 'pending'", "'pending'"),
 ("unsigned webhook", L, "if (!empty($ch['secret'])) {", "if (false) {"),
 ("visitor waits", S, "$code === 200 && $async &&", "$code === 200 &&"),
 ("purge after delivery in one try", S, "        zk_purge();\n    } catch (Throwable $e) { error_log('zayavka purge", "        zk_deliver(20); zk_purge();\n    } catch (Throwable $e) { error_log('zayavka purge"),
 ("no purge on submit", S, "        zk_purge();\n", ""),
 ("no purge in cron", Z, "$n = zk_purge();", "$n = 0;"),
 ("no record of destruction", L, "zk_audit($actor, 'destroyed'", "0 && zk_audit($actor, 'destroyed'"),
 ("suspect leads live long", L, "[(int) $c['suspect_days'], 1]", "[(int) $c['retention_days'], 1]"),
 ("no cascade", L, " ON DELETE CASCADE", ""),
 ("no secure_delete and no checkpoint", L, "PRAGMA wal_checkpoint(TRUNCATE)", "PRAGMA wal_checkpoint(PASSIVE)"),
 ("delete takes any digits", Z, "if (!preg_match('/^(?:Z-)?([1-9][0-9]{0,17})$/', $arg, $m)) $fail('give one number, for example Z-12'); return (int) $m[1];", "return (int) preg_replace('/\\D/', '', $arg);"),
 ("guessable database name", L, "substr(hash_hmac('sha256', 'db', zk_cfg()['secret']), 0, 24)", "'000'"),
 ("storage in a web root", L, "if (zk_inside($real, $r)) throw new RuntimeException('storage is inside the web root');", ""),
 ("program in a web root", L, "if (zk_inside((string) realpath(zk_dir()), $r)) throw", "if (false) throw"),
 ("directory of submit.php is not a root", L, "$roots[] = dirname($r);", ";"),
 ("storage open to others", L, "@mkdir($st, 0700, true)", "@mkdir($st, 0755, true)"),
 ("umask", L, "umask(0077)", "umask(0022)"),
 ("changed secret", L, "throw new RuntimeException('storage holds a database made with another secret');", "{}"),
 ("short secret", L, "if (strlen($c0['secret']) < 32) throw", "if (false) throw"),
 ("first run race", L, "if (!$lock || !flock($lock, LOCK_EX)) throw", "if (!$lock) throw"),
 ("own database counts as foreign", L, "if (!is_file($f) && array_diff(glob(\"$st/leads-*.sqlite\") ?: [], [$f]))", "if (!is_file($f) && (usleep(200000) || glob(\"$st/leads-*.sqlite\")))"),
 ("doctor ignores links", Z, "$say(!$links,", "$say(true,"),
 ("doctor ignores config rights", Z, "$say((fileperms($cf) & 0077) === 0", "$say((fileperms($cf) & 0007) === 0"),
 ("no csrf", A, "if ($post && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {", "if (false) {"),
 ("no login", A, "if (empty($_SESSION['in'])) {", "if (false) {"),
 ("any password", A, "&& password_verify($_POST['password'], $c['admin_password_hash'])", ""),
 ("no login limit", A, "if (zk_limited('login', 5, 900) || zk_limited('login', 200, 900, '*'))", "if (false)"),
 ("session id is kept at login", A, "session_regenerate_id(true); ", ""),
 ("no idle limit", A, "time() - $_SESSION['in'] > 1800 || ", ""),
 ("no absolute limit", A, "time() - $_SESSION['since'] > 28800 || ", ""),
 ("password change keeps sessions", A, " || !hash_equals($mark, $_SESSION['mark'])", ""),
 ("logout does nothing", A, "$_SESSION = []; session_destroy(); $go();", "$go();"),
 ("cookie without Secure", A, "'secure' => !$local", "'secure' => false"),
 ("cookie readable by scripts", A, "'httponly' => true", "'httponly' => false"),
 ("cookie cross-site", A, "'samesite' => 'Strict'", "'samesite' => 'Lax'"),
 ("sessions in shared tmp", A, "session_save_path($sd); ", ""),
 ("page readable by fetch", A, "$dest !== 'document' || ", ""),
 ("cross-site post", A, "($post && !in_array($site, ['same-origin', 'none'], true))", "false"),
 ("no CSP", A, "default-src 'none'; ", ""),
 ("no escaping", A, "htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')", "(string) $s"),
 ("export without password", A, "if (!$check()) { zk_audit('admin', 'export refused')", "if (false) { zk_audit('admin', 'export refused')"),
 ("delete without confirmation", A, "if (empty($_POST['sure'])) {", "if (false) {"),
 ("view is not logged", A, "zk_audit('admin', 'view', zk_key((int) $l['id']));", ""),
 ("csv formulas", L, "if (preg_match('/^\\s*[=+\\-@|]/u', $v)) $v = \"'\" . $v;", ""),
 ("csv formulas inside a cell", L, "$v = preg_replace('/([,;\\t\\r\\n|]\\s*)(?=[=@]|[+\\-][A-Za-z])/u', \"$1'\", $v);", ""),
 ("redirect by REQUEST_URI", A, "$self = '/' . ltrim($_SERVER['SCRIPT_NAME'], '/');", "$self = strtok($_SERVER['REQUEST_URI'], '?');"),
]
def one(m):
    name, f, a, b = m; d = tempfile.mkdtemp(); t = d + "/z"
    try:
        shutil.copytree(ROOT, t, ignore=shutil.ignore_patterns("config.php", "storage")); s = open(f"{t}/{f}").read()
        if s.count(a) != 1: return name, f"NOT APPLIED ({s.count(a)} places)", ""
        open(f"{t}/{f}", "w").write(s.replace(a, b))
        try: r = subprocess.run([sys.executable, t + "/tests/run.py"], capture_output=True, text=True, timeout=400)
        except subprocess.TimeoutExpired: return name, "caught", "timeout"
        fails = [l for l in r.stdout.splitlines() if l.startswith("FAIL")]
        return name, "caught" if r.returncode else "MISSED", " / ".join(x[5:75] for x in fails[:3])
    finally: shutil.rmtree(d, ignore_errors=True)
base = subprocess.run([sys.executable, ROOT + "/tests/run.py"], capture_output=True, text=True)
if base.returncode: print(base.stdout[-500:]); raise SystemExit("tests fail on the unchanged code")
pick = [m for m in M if len(sys.argv) < 2 or any(x in m[0] for x in sys.argv[1:])]
with cf.ThreadPoolExecutor(int(os.environ.get("JOBS", "4"))) as p: R = list(p.map(one, pick))
for n, r, i in R: print(f"{r:8} {n}  {i}")
bad = [n for n, r, i in R if r != "caught"]; print(f"caught {len(R) - len(bad)} of {len(R)}"); raise SystemExit(1 if bad else 0)
