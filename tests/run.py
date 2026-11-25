#!/usr/bin/env python3
"""End-to-end tests: real HTTP against php -S, fake Telegram/webhook and a fake SMTPS server. No network."""
import base64, concurrent.futures as cf, csv, fcntl, glob, hashlib, hmac, http.client, http.cookiejar, http.server, io, json, os, re, shutil, socket, sqlite3, ssl, subprocess, tempfile, threading, time, urllib.error, urllib.parse, urllib.request
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = tempfile.mkdtemp(); APP, WEB, REL = T + "/app", T + "/web", T + "/rel"
for d in ("src", "bin"): shutil.copytree(f"{ROOT}/{d}", f"{APP}/{d}")
shutil.copytree(ROOT + "/public", WEB)
def port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p
PW, PF, PH, PS, PN, PE = [port() for _ in range(6)]; B = f"http://127.0.0.1:{PW}"; O = "https://example.ru"
got, mails, hook_code = [], [], [200]
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        b = self.rfile.read(int(self.headers["Content-Length"])).decode()
        if self.path == "/slow": time.sleep(1.5)
        got.append((self.path, b, dict(self.headers)))
        self.send_response(hook_code[0])
        if hook_code[0] == 302: self.send_header("Location", f"http://127.0.0.1:{PH}/abroad")
        self.end_headers()
    do_GET = do_POST
    def log_message(self, *a): pass
threading.Thread(target=http.server.ThreadingHTTPServer(("127.0.0.1", PH), H).serve_forever, daemon=True).start()
def raw(p, answer):
    s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(("127.0.0.1", p)); s.listen(20)
    def one(k):
        try:
            k.recv(65536)
            if answer is None: time.sleep(60); return
            while True: k.sendall(answer)
        except OSError: pass
    def loop():
        while True: threading.Thread(target=one, args=(s.accept()[0],), daemon=True).start()
    threading.Thread(target=loop, daemon=True).start()
raw(PN, None); raw(PE, b"x" * 65536)                       # a silent server and an endless one
subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", T+"/k.pem", "-out", T+"/c.pem", "-days", "2", "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost,IP:127.0.0.1"], check=True, capture_output=True)
def smtpd():
    c = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); c.load_cert_chain(T+"/c.pem", T+"/k.pem")
    s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(("127.0.0.1", PS)); s.listen(5)
    while True:
        try:
            k = c.wrap_socket(s.accept()[0], server_side=True); f = k.makefile("rwb")
            say = lambda x: (f.write(x.encode() + b"\r\n"), f.flush())
            say("220 x"); f.readline(); say("250-x"); say("250 AUTH LOGIN"); f.readline(); say("334 u"); f.readline(); say("334 p"); f.readline(); say("235 ok")
            f.readline(); say("250 ok"); to = f.readline(); say("250 ok"); f.readline(); say("354 go"); d = b""
            while not d.endswith(b"\r\n.\r\n"): d += f.readline()
            say("250 ok"); mails.append((to.decode(), d.decode())); k.close()
        except Exception: pass
threading.Thread(target=smtpd, daemon=True).start()
ENV = {**os.environ, "ZAYAVKA_DIR": APP}
def cli(*a, inp=None, env=None): return subprocess.run(["php", (env or ENV)["ZAYAVKA_DIR"]+"/bin/zayavka", *a], capture_output=True, text=True, env=env or ENV, input=inp, timeout=90)
PASS = "correct horse battery"; PHASH = cli("password", inp=PASS + "\r\n").stdout.strip()
DEF = dict(rate=100000, day=100000, chans="", extra="", storage=f"'{APP}/storage'", origins=f"'{O}'", secret="str_repeat('t',32)", roots=f"'{WEB}'", consents="'v1'=>'Текст один','v2'=>'Текст два'", proxy="false", phash=None, adm=f"http://127.0.0.1:{PW}/admin.php", head="<?php ", fwin=600)
def config(**k):
    c = {**DEF, **k}
    if os.path.exists(APP + "/config.php"): os.chmod(APP + "/config.php", 0o600)
    open(APP + "/config.php", "w").write(f"""{c['head']}return [{c['extra']}'secret'=>{c['secret']},'allowed_origins'=>[{c['origins']}],'web_roots'=>[{c['roots']}],'storage'=>{c['storage']},'rate_limit'=>{c['rate']},'rate_window'=>{c['fwin']},'max_per_day'=>{c['day']},'consents'=>[{c['consents']}],'forms'=>['callback'=>'Обратный звонок'],'trust_proxy'=>{c['proxy']},
 'fields'=>['name'=>['label'=>'Имя','max'=>100],'phone'=>['label'=>'Телефон','type'=>'phone'],'email'=>['label'=>'E-mail','type'=>'email'],'message'=>['label'=>'Сообщение','multiline'=>true]],'require_any'=>['phone','email'],'thanks_url'=>'/ok',
 'admin_password_hash'=>'{c['phash'] if c['phash'] is not None else PHASH}','admin_url'=>'{c['adm']}','channels'=>[{c['chans']}]];""")
    os.chmod(APP + "/config.php", 0o600); time.sleep(0.03)
config()
open(T + "/fin.php", "w").write("<?php function fastcgi_finish_request() { while (ob_get_level()) ob_end_flush(); flush(); return true; }")
def serve(p, web, *opt, env=ENV, workers="8"): return subprocess.Popen(["php", "-d", "opcache.enable=0", "-d", "display_errors=1", *opt, "-S", f"127.0.0.1:{p}", "-t", web], env={**env, "PHP_CLI_SERVER_WORKERS": workers}, stdout=subprocess.DEVNULL, stderr=open(T+"/err", "a"), start_new_session=True)
srv = [serve(PW, WEB), serve(PF, WEB, "-d", f"auto_prepend_file={T}/fin.php")]; time.sleep(0.8)
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a): return None
def req(path, data=None, headers=None, op=None, base=B):
    h = {"Origin": O, **(headers or {})}; r = urllib.request.Request(base + path, data, {k: v for k, v in h.items() if v is not None})
    try:
        x = (op or urllib.request.build_opener(NoRedir)).open(r, timeout=40); return x.status, x.read().decode("utf-8", "replace"), x.headers
    except urllib.error.HTTPError as e: return e.code, e.read().decode("utf-8", "replace"), e.headers
def post(d, base=B, **h): return req("/submit.php", json.dumps(d).encode(), {"Content-Type": "application/json", **{k.replace("_", "-"): v for k, v in h.items()}}, base=base)
def form(d, **h): return req("/submit.php", urllib.parse.urlencode(d).encode(), {"Content-Type": "application/x-www-form-urlencoded", "Accept": "text/html", **h})
seq = [0]
def lead(**k):
    seq[0] += 1
    return {"form": "callback", "name": "Анна Тест", "phone": "+7 900 000-00-00", "email": "anna@example.ru", "consent": "1", "consent_version": "v1", "page": "https://example.ru/p/?phone=79000000000#x", "id": "test-id-%012d" % seq[0], **k}
DBF = lambda: [f for f in glob.glob(APP + "/storage/leads-*.sqlite")][0]
def db(q, *a):
    c = sqlite3.connect(DBF(), timeout=10); r = c.execute(q, a).fetchall(); c.commit(); c.close(); return r
def last(): return db("select id,form,page,fields,consent_id,consent_sha,suspect from leads order by id desc limit 1")[0]
def count(): return db("select count(*) from leads")[0][0]
n = [0, 0]
def ck(name, cond, info=""):
    n[0 if cond else 1] += 1; print("ok  " if cond else "FAIL", name, "" if cond else str(info)[:600], flush=True)
PD = ["Анна", "900 000", "anna@"]
clean = lambda s: not any(x in s for x in PD)
full = "'data'=>'full','in_russia'=>true"
hook = lambda opt="", path="/h", name="w", p=PH: f"'{name}'=>['type'=>'webhook','url'=>'http://127.0.0.1:{p}{path}','secret'=>'s',{opt}],"
tg = f"'tg'=>['type'=>'telegram','token'=>'T','chat_id'=>'1','api'=>'http://127.0.0.1:{PH}',{full}],"
def deliver(**k): got.clear(); mails.clear(); r = cli("deliver"); time.sleep(0.15); return r
def drop(): db("delete from deliveries")

def accept():
    s, b, _ = post(lead()); ck("valid lead stored", s == 200 and json.loads(b)["ok"], (s, b))
    r = last(); ck("fields and consent edition stored", "Анна" in r[3] and r[4] == "v1" and r[5] == hashlib.sha256("Текст один".encode()).hexdigest(), r)
    ck("consent text archived", db("select text from consents where sha=?", r[5]) == [("Текст один",)])
    post(lead(consent_version="v2")); ck("the edition the visitor saw is recorded", last()[4] == "v2")
    ck("409: unknown consent edition", post(lead(consent_version="v9"))[0] == 409); ck("409: no consent edition", post(lead(consent_version=""))[0] == 409)
    for nm, d in [("no consent", {"consent": ""}), ("consent=false", {"consent": False}), ("consent=no", {"consent": "no"}), ("unknown form", {"form": "x"}), ("form not a string", {"form": ["callback"]}), ("bad phone", {"phone": "abc"}), ("bad email", {"email": "a\r\nBcc: x@y.z"}), ("no contact", {"phone": "", "email": ""}), ("field not scalar", {"name": {"a": 1}}), ("too long", {"name": "я" * 101})]:
        c0 = count(); s, b, _ = post(lead(**d)); ck("422: " + nm, s == 422 and count() == c0, (s, b))
    for url, want in [("https://example.ru/p/?phone=79000000000#x", "example.ru/p/"), ("https://evil.ru/login", ""), ("https://example.ru/u/a@b.ru/79001234567", "example.ru/u/***/***"), ("https://example.ru/u/a%40b.ru", "example.ru/u/***"), ("javascript:alert(1)", ""), (["x"], "")]:
        post(lead(page=url)); ck(f"page {url} -> {want!r}", last()[2] == want, last()[2])
    ck("bad UTF-8 in page is dropped", form({**lead(), "page": b"https://example.ru/\xff\xfe"})[0] == 303 and last()[2] == "", last()[2])
    ck("bad UTF-8 in a field: 422", form({**lead(), "name": b"\xff\xfe"})[0] == 422)
    post(lead(name="Иван\nСогласие: отозвано\u2028Телефон: 1", message="a\nТелефон: 2", phone="+7 900 111-11-11")); f = json.loads(last()[3]); ck("line breaks are removed from single-line fields", "\n" not in f["name"] and "\u2028" not in f["name"] and "\n" in f["message"], f)
    out = cli("show", f"Z-{last()[0]}").stdout; ck("a message cannot imitate card lines", "\nТелефон: 2" not in out and "    Телефон: 2" in out, out)
    post(lead(name="\x01\u202eИван\x00")); ck("control and bidi characters are removed", json.loads(last()[3])["name"] == "Иван", last())
def unicode():
    post(lead(name="A\u009bB\u2028C\u061cD\u0085E")); n = json.loads(last()[3])["name"]; ck("C1 controls and odd line breaks are removed", n == "AB CD E", repr(n))
    post(lead(message="x\u2028Согласие: нет\u009b")); m = json.loads(last()[3])["message"]; ck("multi-line: odd breaks become plain ones", m == "x\nСогласие: нет", repr(m))
    post(lead(page="https://example.ru/a%C2%9Bb/")); ck("page: C1 controls are hidden", last()[2] == "example.ru/***/", last()[2])
def origins():
    ck("403: foreign origin", post(lead(), Origin="https://evil.ru")[0] == 403); ck("403: origin prefix trick", post(lead(), Origin=O + ".evil.ru")[0] == 403); ck("403: http instead of https", post(lead(), Origin="http://example.ru")[0] == 403)
    ck("403: no origin at all", post(lead(), Origin=None)[0] == 403); ck("403: Origin null alone", post(lead(), Origin="null")[0] == 403)
    ck("403: Origin null, same-origin, foreign host", post(lead(), Origin="null", Sec_Fetch_Site="same-origin")[0] == 403)
    ck("referer is enough", post(lead(), Origin=None, Referer=O + "/p?x")[0] == 200); ck("403: foreign referer", post(lead(), Origin=None, Referer="https://evil.ru/https://example.ru")[0] == 403)
    ck("refused origin is shown to the owner", db("select v from state where k='refused origin'")[0][0] == "https://evil.ru")
    config(origins=f"'{B}'"); ck("Origin null from the same site is accepted", post(lead(), Origin="null", Sec_Fetch_Site="same-origin")[0] == 200); ck("403: Origin null, cross-site", post(lead(), Origin="null", Sec_Fetch_Site="cross-site")[0] == 403)
    for o in ["https://example.ru/", "https://Example.RU", "https://example.ru:443"]: config(origins=f"'{o}'"); ck(f"config origin {o} is normalised", post(lead())[0] == 200)
    config(origins="'https://пример.рф'"); s = post(lead(), Origin="https://xn--e1afmkfd.xn--p1ai")[0]; d = cli("doctor"); ck("Cyrillic origin works or is refused by doctor", s == 200 or (s == 500 and d.returncode == 1 and "punycode" in d.stderr), (s, d.stdout, d.stderr))
    for o in ["https://example.ru/path", "example.ru", "ftp://example.ru"]: config(origins=f"'{o}'"); d = cli("doctor"); ck(f"doctor refuses origin {o}", d.returncode == 1 and "allowed_origins" in d.stderr, d.stderr)
    config()
def bodies():
    ck("413: oversize json", post(lead(x="a" * 40000))[0] == 413); ck("413: oversize form", form({**lead(), "x": "a" * 40000})[0] == 413)
    ck("400: broken json", req("/submit.php", b"{", {"Content-Type": "application/json"})[0] == 400); ck("400: json is not an object", req("/submit.php", b"5", {"Content-Type": "application/json"})[0] == 400)
    ck("415: multipart", req("/submit.php", b"--x\r\n\r\n--x--", {"Content-Type": "multipart/form-data; boundary=x"})[0] == 415); ck("405: GET", req("/submit.php")[0] == 405)
    c = http.client.HTTPConnection("127.0.0.1", PW, timeout=10); c.putrequest("POST", "/submit.php"); [c.putheader(k, v) for k, v in {"Origin": O, "Content-Type": "application/x-www-form-urlencoded", "Transfer-Encoding": "chunked"}.items()]; c.endheaders(); b = urllib.parse.urlencode(lead()).encode(); c.send(b"%x\r\n%s\r\n0\r\n\r\n" % (len(b), b)); c0 = count(); s = c.getresponse().status; ck("a body without a length is not stored", s in (400, 411, 501) and count() == c0, s)
    s, b, _ = post(lead(zk_hp=["x"])); ck("warnings never reach the answer", s == 200 and json.loads(b)["ok"], b)
    s, b, h = form({**lead(), "id": ""}); ck("works without JavaScript (303)", s == 303 and h["Location"] == "/ok", (s, b))
    s, b, _ = form(lead(consent="")); ck("readable text without JavaScript", s == 422 and "согласие" in b, b)
def traps():
    c0 = count(); d0 = db("select count(*) from deliveries")[0][0]; config(chans=hook()); s, b, _ = post(lead(zk_hp="http://spam")); ck("trap: kept, marked, no notices", s == 200 and count() == c0 + 1 and last()[6] == 1 and db("select count(*) from deliveries")[0][0] == d0, (s, b, last()))
    ck("trap: real number in the answer", json.loads(b)["key"] == f"Z-{last()[0]}", b); post(lead()); ck("normal lead is queued", last()[6] == 0 and db("select count(*) from deliveries")[0][0] == d0 + 1); drop(); config()
def repeats():
    c0 = count(); x = lead(); a, c = post(x)[1], post(x)[1]; ck("same id, same content: one lead", a == c and count() == c0 + 1)
    e = post({**x, "phone": "+7 900 222-22-22"})[1]; ck("same id, corrected phone: not lost", e != a and "222-22-22" in last()[3] and count() == c0 + 2, (a, e))
    y = {**lead(name="Без скрипта"), "id": ""}; form(y); form(y); ck("double click without JavaScript: one lead", count() == c0 + 3, count())
    z = lead(name="Гонка"); lk = sqlite3.connect(DBF(), isolation_level=None); lk.execute("begin immediate")
    with cf.ThreadPoolExecutor(7) as p: fs = [p.submit(post, z) for _ in range(7)]; time.sleep(1.2); lk.execute("rollback"); lk.close(); r = [f.result() for f in fs]      # all seven are past the first lookup
    ck("parallel twins: all 200, one lead", {x[0] for x in r} == {200} and len({x[1] for x in r}) == 1 and count() == c0 + 4, {x[0] for x in r})
def limits():
    db("delete from hits"); config(rate=2); x = lead(); codes = [post(x)[0], post(lead())[0], post(lead())[0]]; ck("429 after the limit", codes == [200, 200, 429], codes)
    ck("repeat of an accepted lead is not limited", post(x)[0] == 200); hs = [x[0] for x in db("select h from hits")]; ck("IP is not stored", hs and all(re.fullmatch("[0-9a-f]{64}", x) for x in hs))
    ck("429 is counted for the owner", db("select n from counters where k='refused: 429'")[0][0] >= 1)
    db("update hits set exp=1"); post(lead()); ck("hashes are deleted when the window ends", db("select count(*) from hits where exp<=1")[0][0] == 0)
    db("update hits set exp=1"); cli("deliver"); ck("cron deletes old hashes too", db("select count(*) from hits")[0][0] == 0)
    codes = [post(lead(), X_Real_IP=f"198.51.100.{i}")[0] for i in range(3)]; ck("X-Real-IP is ignored by default", codes[-1] == 429, codes); ck("one odd request does not say 'proxy'", "прокси" not in cli("doctor").stdout)
    db("delete from hits"); config(rate=2, proxy="true"); codes = [post(lead(), X_Real_IP=f"198.51.100.{i}")[0] for i in range(4)]; ck("trust_proxy: addresses are separate", codes == [200] * 4, codes)
    db("delete from hits"); codes = [post(lead(), X_Real_IP=f"2001:db8:1:2::{i:x}")[0] for i in range(1, 4)]; ck("IPv6 counts as /64", codes[-1] == 429, codes); ck("another /64 is separate", post(lead(), X_Real_IP="2001:db8:1:3::1")[0] == 200)
    db("delete from hits"); codes = [post(lead(), X_Real_IP=f"198.51.100.{i}, 10.0.0.1")[0] for i in range(3)]; ck("a list in X-Real-IP is not an address", codes[-1] == 429, codes)
    db("delete from hits"); config(rate=5)
    with cf.ThreadPoolExecutor(12) as p: r = [x[0] for x in p.map(lambda _: post(lead()), range(24))]
    ck("parallel leads do not pass the limit", r.count(200) == 5, r); db("delete from hits"); config(day=3, chans=hook()); d0 = db("select count(*) from deliveries")[0][0]; codes = [post(lead())[0] for _ in range(4)]; ck("daily cap: the lead is kept and marked", codes == [200] * 4 and last()[6] == 2, (codes, last())); post(lead()); post(lead()); ck("daily cap: one notice a day", db("select count(*) from deliveries")[0][0] == d0 + 4, db("select count(*) from deliveries")[0][0] - d0)
    db("delete from hits"); db("delete from state where k='over the cap'"); drop(); config(day=1, chans=hook(full)); post(lead()); drop(); post(lead()); deliver(); ck("daily cap: the notice holds nothing personal", len(got) == 1 and clean(got[0][1]), got); ck("daily cap: the owner is told", "Сверх дневного предела" in cli("doctor").stdout)
    db("delete from hits"); config(); post(lead(zk_hp="x")); t1 = last()[0]; o2 = db("select max(id) from leads where suspect=2")[0][0]; db("update leads set created_at=created_at-31*86400 where id in (?,?)", t1, o2); cli("deliver"); left = {x[0] for x in db("select id from leads where id in (?,?)", t1, o2)}; ck("leads over the cap are kept as long as others, trap leads are not", left == {o2}, left); drop()
    big = "x" * 100000; [db("insert into leads(idem,form,page,fields,consent_id,consent_sha,consent_at,created_at) values(?,?,?,?,?,?,?,?)", f"big{i}", "callback", "", big, "v1", "x", "x", int(time.time())) for i in range(60)]
    config(extra="'max_db_mb'=>4,"); a = post(lead())[0]; config(extra="'max_db_mb'=>7,"); w = cli("doctor").stdout; b0 = post(lead())[0]; config(extra="'max_db_mb'=>4,"); db("delete from leads where idem like 'big%'"); ck("space freed by deletions is used again", (a, b0, post(lead())[0]) == (503, 200, 200), (a, b0)); ck("a nearly full database is announced", "больше 80 %" in w, w); config()
    config(extra="'max_db_mb'=>0,", chans=tg); db("update state set v='' where k='alert space'"); drop(); deliver(); deliver_n = len(got); t1 = urllib.parse.unquote_plus(got[0][1]) if got else ""; deliver(); ck("space: one word a day to the chat, nothing personal", deliver_n == 1 and len(got) == 0 and "80 %" in t1 and clean(t1), (deliver_n, t1))
    config(extra="'max_db_mb'=>0,", chans=f"'m'=>['type'=>'email','to'=>'s@example.ru','from'=>'site@example.ru','smtp'=>['host'=>'localhost','port'=>{PS},'user'=>'u','pass'=>'p','cafile'=>'{T}/c.pem']],"); db("update state set v='' where k='alert space'"); deliver(); ck("space: a word to the mailbox", len(mails) == 1 and "80 %" in base64.b64decode(mails[0][1].split("\r\n\r\n", 1)[1].split("\r\n.\r\n")[0]).decode(), mails); config()
    db("update leads set suspect=2 where id in (select id from leads where suspect=0 order by id desc limit 3)"); c0 = count(); p = cli("purge-over"); ck("purge-over asks first", p.returncode == 1 and count() == c0, p.stderr); n2 = db("select count(*) from leads where suspect=2")[0][0]; p = cli("purge-over", "yes"); ck("purge-over deletes only leads above the cap, with records", count() == c0 - n2 and n2 >= 3 and f"deleted: {n2}" in p.stdout and db("select count(*) from audit where action='destroyed' and info like '%over the daily cap%'")[0][0] >= n2, (p.stdout, n2))
    post(lead(zk_hp="x")); post(lead()); c0 = count(); n1 = db("select count(*) from leads where suspect=1")[0][0]; p0 = cli("purge-trap"); p = cli("purge-trap", "yes"); ck("purge-trap deletes only trap leads", p0.returncode == 1 and n1 >= 1 and count() == c0 - n1 and f"deleted: {n1}" in p.stdout, (p.stdout, n1))
    pd = port(); x = serve(pd, WEB, "-d", "disable_functions=disk_free_space"); time.sleep(0.6); ck("no disk_free_space on the hosting: leads are accepted", post(lead(), base=f"http://127.0.0.1:{pd}")[0] == 200); x.kill()
    db("delete from hits"); config(day=3, chans=hook()); [post(lead(zk_hp="x")) for _ in range(5)]; post(lead()); ck("trap leads do not spend the daily cap", last()[6] == 0, last())
    for nm, ex in [("database is large", "'max_db_mb'=>0,"), ("disk is nearly full", "'min_free_mb'=>1000000000,")]: c0 = count(); config(extra=ex); s, b, _ = form(lead()); ck(f"{nm}: honest refusal", s == 503 and "место" in b and count() == c0, (s, b))
    db("delete from hits"); config(rate=2, proxy="true"); codes = [post(lead(), X_Real_IP=f"2001:db8:9:{i:x}::1")[0] for i in range(12)]; ck("IPv6 /48 has its own limit", codes[:10] == [200] * 10 and codes[10:] == [429, 429], codes); ck("another /48 is separate", post(lead(), X_Real_IP="2001:db8:a:1::1")[0] == 200)
    db("delete from hits"); db("delete from counters"); config(rate=100); [post(lead(), X_Forwarded_For="1.2.3.4") for _ in range(6)]; o = cli("doctor").stdout; ck("a real proxy is noticed, with a warning", "Похоже, сайт стоит за прокси" in o and "Не включайте trust_proxy, если прокси нет" in o, o)
    [post(lead()) for _ in range(3)]; ck("a few requests with the header are not a proxy", "сайт стоит за прокси" not in cli("doctor").stdout); req("/submit.php", b"{}", {"Content-Type": "application/json", "Origin": "https://www.site-test.ru"}); ck("a refused origin is shown with a warning", "только если это ваш сайт" in cli("doctor").stdout); db("delete from hits"); config()
def channels():
    drop(); config(chans=tg); post(lead()); deliver(); body = urllib.parse.unquote_plus(got[0][1]) if got else ""; ck("telegram never gets personal data, even if asked", len(got) == 1 and clean(body) and "Z-" in body and got[0][0] == "/botT/sendMessage", body)
    for nm, opt, want in [("webhook default", "", False), ("webhook full without declaration", "'data'=>'full'", False), ("webhook in_russia without full", "'in_russia'=>true", False), ("webhook in_russia=>1", "'data'=>'full','in_russia'=>1", False), ("webhook full declared", full, True)]:
        config(chans=hook(opt)); post(lead()); deliver(); ck(nm + (": data" if want else ": number only"), len(got) == 1 and ("Анна" in got[0][1]) == want and ("example.ru/p" in got[0][1].replace("\\/", "/")) == want, got)
    h = {k.lower(): v for k, v in got[0][2].items()}; ck("webhook is signed with the time", h.get("x-zayavka-signature") == "sha256=" + hmac.new(b"s", (h.get("x-zayavka-timestamp", "") + "." + got[0][1]).encode(), hashlib.sha256).hexdigest(), h)
    ck("full transfer is in the log", db("select count(*) from audit where action='sent in full' and info='channel: w'")[0][0] == 1); ck("webhook has a stable delivery id", json.loads(got[0][1])["delivery"] > 0)
    config(chans=hook()); post(lead()); config(chans=hook(full)); deliver(); ck("a queued notice does not become full after a setting change", len(got) == 1 and clean(got[0][1]), got)
    config(chans=hook(full)); post(lead()); config(chans=hook(full, path="/other")); deliver(); ck("a queued lead is not sent in full to a changed receiver", len(got) == 1 and got[0][0] == "/other" and clean(got[0][1]), got)
    hook_code[0] = 302; config(chans=hook(full)); post(lead()); deliver(); ck("redirect is not followed", [g[0] for g in got] == ["/h"], got)
    hook_code[0] = 500; s = post(lead())[0]; deliver(); p = db("select status,tries from deliveries order by id desc limit 1")[0]; ck("channel error: delivery stays pending", s == 200 and p[0] == "pending", (s, p))
    hook_code[0] = 200; db("update deliveries set next_at=0"); deliver(); left = db("select count(*) from deliveries where status='pending'")[0][0]; ck("cron resends", left == 0 and len(got) >= 1, (left, len(got))); deliver(); ck("nothing is sent twice", not got, got)
    db("update deliveries set status='pending', tries=74, next_at=0 where id=(select max(id) from deliveries)"); hook_code[0] = 500; deliver(); ck("failed after the last attempt", db("select status from deliveries order by id desc limit 1")[0][0] == "failed"); ck("doctor reports a failed delivery", cli("doctor").returncode == 1)
    hook_code[0] = 200; cli("retry"); deliver(); ck("retry queues it again", len(got) == 1)
    for code, n in [(422, 3), (400, 3), (403, 3), (404, 3), (500, 3), (401, 1), (429, 1), (503, 1)]:
        drop(); config(chans=hook(full)); [post(lead()) for _ in range(3)]; hook_code[0] = code; deliver(); ck(f"answer {code}: {'the next lead is tried' if n == 3 else 'the channel waits'}", len(got) == n, len(got)); hook_code[0] = 200
    drop(); config(chans=hook(full)); [post(lead()) for _ in range(23)]; hook_code[0] = 422; deliver(); ck("at most 20 refusals in one run", len(got) == 20, len(got)); hook_code[0] = 200
    drop(); post(lead()); d1 = db("select max(id) from deliveries")[0][0]; cli("delete", f"Z-{last()[0]}"); post(lead()); d2 = db("select max(id) from deliveries")[0][0]; ck("a delivery number is never used again", d2 > d1, (d1, d2)); drop()
    config(chans=hook(p=PN, name="silent") + hook(path="/ok")); [post(lead()) for _ in range(3)]; t = time.time(); deliver(); ck("a silent channel does not hold the others", len([g for g in got if g[0] == "/ok"]) == 3 and time.time() - t < 25, (len(got), time.time() - t))
    drop(); config(chans=hook(p=PE, name="endless") + hook(path="/ok")); post(lead()); t = time.time(); r = deliver(); ck("an endless answer does not break the queue", r.returncode == 0 and len(got) == 1 and time.time() - t < 25 and db("select tries from deliveries where channel='endless'")[0][0] == 1, (r, got))
    drop(); config(chans=hook(full, path="/slow")); [post(lead()) for _ in range(2)]; i = last()[0]; got.clear(); p = subprocess.Popen(["php", APP + "/bin/zayavka", "deliver"], env=ENV, stdout=subprocess.DEVNULL); time.sleep(0.7); cli("delete", f"Z-{i}", "request"); p.wait(); time.sleep(0.2); ck("a lead deleted during a run is not sent", len(got) == 1 and f"Z-{i}\"" not in got[0][1], [g[1][:60] for g in got])
    drop(); config(chans=hook(path="/slow")); post(lead()); got.clear(); ps = [subprocess.Popen(["php", APP + "/bin/zayavka", "deliver"], env=ENV, stdout=subprocess.DEVNULL) for _ in range(3)]; [p.wait() for p in ps]; time.sleep(0.2); ck("parallel runs send once", len(got) == 1, len(got))
    drop(); [post(lead()) for _ in range(2)]; got.clear(); p = subprocess.Popen(["php", APP + "/bin/zayavka", "deliver"], env=ENV, stdout=subprocess.DEVNULL); time.sleep(0.6); r = cli("deliver"); p.wait(); time.sleep(0.2); ck("a second run stands aside", r.stdout == "" and len(got) == 2, (r.stdout, len(got)))
    drop(); config(chans=hook("", p=PN)); t = time.time(); s = post(lead())[0]; ck("without a finish function the visitor does not wait", s == 200 and time.time() - t < 3, time.time() - t)
    drop(); config(chans=hook()); got.clear(); s, b, _ = post(lead(), base=f"http://127.0.0.1:{PF}"); time.sleep(0.5); ck("with a finish function it is sent at once", s == 200 and len(got) == 1, (s, got))
    drop(); lk = APP + "/storage/deliver.lock"; os.path.exists(lk) and os.remove(lk); os.mkdir(lk); db("update leads set created_at=1 where id=(select min(id) from leads)"); m = db("select min(id) from leads")[0][0]; s, b, _ = post(lead(), base=f"http://127.0.0.1:{PF}"); time.sleep(0.3); ck("delivery failure: 200, clean JSON, expired lead still deleted", s == 200 and json.loads(b)["ok"] and db("select count(*) from leads where id=?", m)[0][0] == 0, (s, b)); os.rmdir(lk)
    config(chans=tg, extra="'notice_show_form'=>false,"); drop(); post(lead()); deliver(); b = urllib.parse.unquote_plus(got[0][1]); ck("form label can be hidden (telegram)", "Z-" in b and "Обратный" not in b, b)
    config(chans=hook(), extra="'notice_show_form'=>false,"); drop(); post(lead()); deliver(); ck("form label can be hidden (webhook)", "callback" not in got[0][1], got); drop(); config()
def integrations():
    f2 = "'fields'=>['name'=>['label'=>'Имя','max'=>100],'phone'=>['label'=>'Телефон','type'=>'phone'],'email'=>['label'=>'E-mail','type'=>'email'],'message'=>['label'=>'Сообщение','multiline'=>true]],"
    m = "'map'=>['t'=>'Заявка {key}: {form_label}','n'=>'{field:name}','txt'=>'{text}','p'=>[['VALUE'=>'{field:phone}','VALUE_TYPE'=>'WORK']],'e'=>['deep'=>['x'=>'{field:message}','const'=>'1']],'fixed'=>'WEB','num'=>7,'pg'=>'{page}','c'=>'{consent_id}'],"
    def sent(chans, d=None, **k):
        drop(); config(chans=chans, **k); post(d or lead()); deliver(); return got[0][1] if got else ""
    b = json.loads(sent(hook(m + full))); ck("map: filled", b["n"] == "Анна Тест" and b["p"] == [{"VALUE": "+7 900 000-00-00", "VALUE_TYPE": "WORK"}] and b["t"].startswith("Заявка Z-") and b["fixed"] == "WEB" and b["num"] == 7 and b["c"] == "v1", b)
    ck("map: a branch without values is left out", "e" not in b, b)
    for nm, o in [("by default", ""), ("full without a declaration", "'data'=>'full',"), ("in_russia without full", "'in_russia'=>true,")]:
        b = sent(hook(m + o)); ck(f"map: nothing personal, {nm}", b != "" and clean(b) and "n" not in json.loads(b) and "p" not in json.loads(b) and "pg" not in json.loads(b) and "c" not in json.loads(b), b)
    b = sent(hook(m), extra="'notice_show_form'=>false,"); ck("map: the form label can be hidden", "callback" not in b and "Обратный" not in b and "Звонок" not in b, b)
    b = json.loads(sent(hook(m + full), lead(name="{field:phone} {text} $1 \\1 {key}"))); ck("map: values are not read as a template", b["n"] == "{field:phone} {text} $1 \\1 {key}", b)
    b = sent(hook("'format'=>'form','map'=>['a'=>'{field:name}','b'=>['c'=>'{field:phone}']]," + full), lead(name="A&b=c")); ck("format form", urllib.parse.parse_qs(b) == {"a": ["A&b=c"], "b[c]": ["+7 900 000-00-00"]}, b)
    sent(hook("'headers'=>['Authorization'=>'Bearer T','X-Lead-Token'=>'q']," + full)); h = {k.lower(): v for k, v in got[0][2].items()}; ck("headers are sent", h.get("authorization") == "Bearer T" and h.get("x-lead-token") == "q" and h["content-type"] == "application/json", h)
    for nm, hd in [("line break", "'X-A'=>\"1\\r\\nHost: evil.ru\""), ("host", "'Host'=>'evil.ru'"), ("length", "'Content-Length'=>'1'"), ("encoding", "'Transfer-Encoding'=>'chunked'"), ("name with a colon", "'X-A: 1\r\nB'=>'1'")]:
        sent(hook("'headers'=>[" + hd + "]," + full)); ck(f"bad header is refused: {nm}", not got and db("select note from deliveries order by id desc limit 1")[0][0] == "bad header", got)
    drop(); config(chans=hook("'headers'=>['X-Lead-Token'=>'one']," + full)); post(lead()); config(chans=hook("'headers'=>['X-Lead-Token'=>'two']," + full)); deliver(); ck("a queued lead is not sent in full after the key changed", got and clean(got[0][1]), got)
    out, of = T + "/own.txt", T + "/own.php"
    def own(o, body=None, name="own"):
        open(of, "w").write("<?php return function (array $v): void { " + (body or f"file_put_contents('{out}', json_encode($v, JSON_UNESCAPED_UNICODE));") + " };"); return f"'{name}'=>['type'=>'php','file'=>'{of}','timeout'=>2,{o}],"
    def ran(o, body=None, **k):
        if os.path.exists(out): os.unlink(out)
        sent(own(o, body), **k); return open(out).read() if os.path.exists(out) else ""
    on = "'target'=>'db'," + full + ","
    b = ran(on); ck("own code gets the lead", b and json.loads(b)["field:name"] == "Анна Тест", b); ck("own code: marked as sent in full", db("select status, note from deliveries order by id desc limit 1")[0] == ("sent", "full"))
    for nm, o in [("by default", "'target'=>'db',"), ("without a named target", full + ","), ("without a declaration", "'target'=>'db','data'=>'full',")]:
        b = ran(o); ck(f"own code: nothing personal {nm}", b != "" and clean(b), b)
    drop(); config(chans=own("'target'=>'a'," + full + ",")); post(lead()); config(chans=own("'target'=>'b'," + full + ",")); os.path.exists(out) and os.unlink(out); deliver(); ck("own code: queued lead is not sent in full to another target", clean(open(out).read()))
    drop(); config(chans=own(on)); post(lead()); own(on, f"file_put_contents('{out}', 'new' . json_encode($v, JSON_UNESCAPED_UNICODE));"); deliver(); ck("own code: queued lead is not given in full to a changed file", clean(open(out).read()) and "new" in open(out).read())
    loud = "echo $v['field:name']; error_log($v['field:phone']); "
    for nm, body, note in [("exception", "throw new RuntimeException('no ' . $v['field:phone']);", "own code failed"), ("anonymous exception", "throw new class($v['field:name']) extends Exception {};", "own code failed"), ("exit", "exit(0);", "own code did not finish"), ("extra buffer", "ob_start(); echo $v['field:name']; exit;", "own code did not finish"), ("shutdown function", "register_shutdown_function(fn() => print($v['field:name'])); exit;", "own code did not finish"), ("slow", "sleep(30);", "own code did not finish"), ("warning", "echo $v['nope']; file_get_contents('/nope' . $v['field:phone']); throw new Exception;", "own code failed")]:
        e0 = os.path.getsize(T + "/err"); drop(); config(chans=own(on, loud + body)); post(lead()); t = time.time(); r = deliver(); w = db("select status, note from deliveries order by id desc limit 1")[0]
        ck(f"own code: {nm}", w == ("pending", note) and clean(r.stdout + r.stderr) and "errors 1" in r.stdout and time.time() - t < 6, (w, r.stdout, r.stderr, time.time() - t))
    drop(); config(chans=own(on, "sleep(30);") + hook()); [post(lead()) for _ in range(3)]; t = time.time(); deliver(); ck("own code never holds the other channels", len(got) == 3 and time.time() - t < 12, (len(got), time.time() - t))
    drop(); config(chans=own(on, "sleep(2);", "own").replace("'timeout'=>2", "'timeout'=>8") + hook()); post(lead()); p = subprocess.Popen(["php", APP + "/bin/zayavka", "deliver"], env=ENV); time.sleep(0.9); got.clear(); post(lead()); cli("deliver"); ck("another run sends while own code is busy", len(got) == 1, got); p.wait()
    drop(); config(chans="'own'=>['type'=>'php','target'=>'x','file'=>'/nope.php'],"); post(lead()); deliver(); ck("own code: no file", db("select note from deliveries order by id desc limit 1")[0][0] == "own code: file not found"); ck("doctor: own code file", "FAIL  channel own: own code file and proc_open" in cli("doctor").stdout)
    drop(); config(chans=own(on, "throw new Exception;")); s2 = req("/submit.php", json.dumps(lead()).encode(), {"Content-Type": "application/json"}, base=f"http://127.0.0.1:{PF}")[0]; time.sleep(0.5); ck("own code never runs from a web request", s2 == 200 and db("select tries from deliveries order by id desc limit 1")[0][0] == 0)
    drop(); config(chans=own(on, "sleep(30);").replace("'timeout'=>2", "'timeout'=>20")); post(lead()); p = subprocess.Popen(["php", APP + "/bin/zayavka", "deliver"], env=ENV); time.sleep(1.2); p.kill(); p.wait(); k = open(APP + "/storage/own.lock"); 
    try: fcntl.flock(k, fcntl.LOCK_EX | fcntl.LOCK_NB); free = True
    except OSError: free = False
    k.close(); subprocess.run(["pkill", "-f", of]); ck("a killed run does not leave the lock to own code", free)
    ex = open(ROOT + "/examples/own.php").read(); ck("examples/own.php refuses until it is written", "throw new RuntimeException" in ex and subprocess.run(["php", "-l", ROOT + "/examples/own.php"], capture_output=True).returncode == 0)
    config(chans=hook("'map'=>['a'=>'{field:nmae} {key}'],")); o = cli("doctor").stdout; ck("doctor names unknown placeholders", "FAIL  channel w: map, unknown placeholders: field:nmae" in o, o)
    config(chans=hook("'headers'=>['Host'=>'x'],")); o = cli("doctor").stdout; ck("doctor refuses a bad header", "FAIL  channel w: headers" in o, o)
    config(chans=own("'target'=>'db'," + full + ",")); o = cli("doctor").stdout; ck("doctor says what own code may do", "channel own: sends PERSONAL DATA (declared in Russia: own code, the program cannot see where it sends)" in o, o)
    # the commented templates of config.example.php work as they are: only the address is replaced
    src = open(ROOT + "/config.example.php").read()
    def template(name):
        ls = src.split("\n"); i = next(k for k, l in enumerate(ls) if l.lstrip().startswith(f"// '{name}' => [")); t = ""
        for l in ls[i:]:
            if not l.strip(): break
            t += re.sub(r"^\s*// ?", "", l) + "\n"
        return re.sub(r"'url' => '[^']*'", f"'url' => 'http://127.0.0.1:{PH}/crm'", t)
    ck("templates are off for personal data", src.count("'in_russia' => false") == 5, src.count("'in_russia' => false"))
    off = template; template = lambda n: off(n).replace("'in_russia' => false", "'in_russia' => true")
    b = json.loads(sent(template("bitrix24"))); ck("template bitrix24", b["fields"]["NAME"] == "Анна Тест" and b["fields"]["PHONE"] == [{"VALUE": "+7 900 000-00-00", "VALUE_TYPE": "WORK"}] and b["fields"]["SOURCE_ID"] == "WEB", b)
    b = json.loads(sent(template("bitrix24"), lead(email=""))); ck("template bitrix24: no empty e-mail", "EMAIL" not in b["fields"], b)
    b = json.loads(sent(template("amocrm"))); h = {k.lower(): v for k, v in got[0][2].items()}; ct = b[0]["_embedded"]["contacts"][0]; ck("template amocrm", ct["first_name"] == "Анна Тест" and ct["custom_fields_values"][0]["values"][0]["value"] == "+7 900 000-00-00" and h["authorization"] == "Bearer LONG_LIVED_TOKEN", b)
    b = json.loads(sent(template("amocrm"), lead(email=""))); ck("template amocrm: no empty e-mail", [x["field_code"] for x in b[0]["_embedded"]["contacts"][0]["custom_fields_values"]] == ["PHONE"], b)
    b = json.loads(sent(template("pms"))); h = {k.lower(): v for k, v in got[0][2].items()}; ck("template pms", b["contact_name"] == "Анна Тест" and b["external_id"].startswith("zayavka-Z-") and h["x-lead-token"] == "PROJECT_TOKEN", b)
    b = urllib.parse.parse_qs(sent(template("other"))); ck("template other", b["name"] == ["Анна Тест"], b)
    for nm in ("bitrix24", "amocrm", "pms", "other"): ck(f"template {nm}: nothing personal without a declaration", clean(sent(off(nm)))); ck(f"template {nm} passes doctor", "unknown placeholders" not in cli("doctor").stdout)
    for u, want in [("https://x.bitrix24.com/r", 0), ("https://x.bitrix24.eu/r", 0), ("https://x.bitrix24.kz/r", 0), ("https://x.amocrm.com/r", 0), ("https://x.kommo.com/r", 0), ("https://x.bitrix24.ru/r", 1), ("https://x.amocrm.ru/r", 1)]: config(chans=f"'w'=>['type'=>'webhook','url'=>'{u}',{full}],"); o = cli("doctor").stdout; ck(f"foreign zone: {u}", ("sends PERSONAL DATA" in o) == bool(want), o)
    cfg = open(APP + "/config.php").read(); os.chmod(APP + "/config.php", 0o600); open(APP + "/config.php", "w").write(cfg.replace("'message'=>", "'имя'=>")); time.sleep(0.05); ck("a field name with odd letters is refused", post(lead())[0] == 500 and "latin letters" in cli("doctor").stderr); config()
    for p in ("{field:имя}", "{ key }", "{}"): config(chans=hook(f"'map'=>['a'=>'{p}'],")); ck(f"doctor names the placeholder {p}", "unknown placeholders" in cli("doctor").stdout)
    for hd in ("'X-Zayavka-Signature'=>'x'", "'X-A'=>['b']"): sent(hook("'headers'=>[" + hd + "]," + full)); ck(f"bad header is refused: {hd}", not got)
    ck("map: nothing left is an object", sent(hook("'map'=>['a'=>'{field:message}'],")) == "{}", got)
    drop(); config(chans=hook("'map'=>['a'=>'{key}']," + full)); post(lead()); config(chans=hook("'map'=>['a'=>'{field:name}']," + full)); deliver(); ck("a queued lead is not sent in full after the map changed", got and clean(got[0][1]), got)
    config(head="<?php $a = T0KENTAIL; "); e0 = os.path.getsize(T + "/err"); s4 = post(lead())[0]; lg = open(T + "/err").read()[e0:] + cli("doctor").stderr; ck("a key without quotes in config: the text stays out of the log", s4 == 500 and "T0KENTAIL" not in lg and "error on line" in lg, lg)
    config(head="<?php 'SECRETTAIL x'; $a = 'b' SECRETTAIL; "); e0 = os.path.getsize(T + "/err"); s3, b3, _ = post(lead()); lg = open(T + "/err").read()[e0:] + cli("doctor").stderr; ck("a syntax error in config: the text stays out of the log", s3 == 500 and "SECRETTAIL" not in lg and "syntax error on line" in lg, lg); config()
    drop(); config()
    goals()

def goals():
    if not shutil.which("node"): print("skip goals: no node", flush=True); return
    js = re.search(r"<script>(.*?)</script>", open(ROOT + "/examples/form.html").read(), re.S).group(1)
    run = lambda metrika, ok, ym: json.loads(subprocess.run(["node", "-e", """
const calls=[],events=[];let submit;const el={id:{value:''},page:{value:''},form:{value:'callback'}};
const url={name:'utm_source',value:''},cid={value:''},st={},bt={};
const f={elements:el,dataset:{metrika:@M@,goal:'lead',fallback:''},action:'/x',reset(){},addEventListener(n,h){submit=h},
 querySelector:q=>q==='button'?bt:st,querySelectorAll:q=>q==='[data-from-url]'?[url]:q==='[data-ym-client-id]'?[cid]:[]};
globalThis.window=globalThis;Object.defineProperty(globalThis,"crypto",{value:require("crypto").webcrypto,configurable:true});if(@Y@)globalThis.ym=(...a)=>{calls.push(a);if(a[1]==='getClientID')a[2](123);if(@Y@===2&&a[1]==='reachGoal')throw Error('x')};
globalThis.location={search:'?utm_source=ya&phone=79000000000',origin:'https://example.ru',pathname:'/p/'};
globalThis.document={querySelectorAll:()=>[f],dispatchEvent:e=>events.push(e.detail)};globalThis.CustomEvent=class{constructor(n,o){this.detail=o.detail}};
globalThis.URLSearchParams=URLSearchParams;globalThis.FormData=class{constructor(){return [['name','Анна']]}};globalThis.fetch=async()=>({ok:@O@,status:422,json:async()=>({ok:@O@,key:'Z-1'})});
@J@
submit({preventDefault(){}}).then(()=>console.log(JSON.stringify({calls:calls.map(c=>c.slice(0,3).filter(x=>typeof x!=='function')),events,utm:url.value,cid:cid.value,text:st.textContent})));
""".replace("@M@", json.dumps(metrika)).replace("@O@", "true" if ok else "false").replace("@Y@", str(ym)).replace("@J@", js)], capture_output=True, text=True).stdout or "{}")
    r = run("12345", True, 1); ck("goal: reached once for an accepted lead", [c for c in r.get("calls", []) if c[1] == "reachGoal"] == [[12345, "reachGoal", "lead"]], r); ck("goal: the event holds no personal data", r.get("events") == [{"form": "callback", "key": "Z-1"}], r)
    ck("ad tags and client id are filled", r.get("utm") == "ya" and r.get("cid") == "123", r)
    r = run("12345", False, 1); ck("goal: not reached for a refused lead", not [c for c in r["calls"] if c[1] == "reachGoal"] and r["events"] == [], r)
    r = run("", True, 1); ck("goal: no counter, nothing is called", r["calls"] == [], r)
    r = run("12345", True, 0); ck("goal: no Metrica on the page, the form works", "принята" in r.get("text", ""), r)
    r = run("12345", True, 2); ck("goal: a broken counter does not break the form", "принята" in r.get("text", ""), r)

def policy():
    def doc(ch): config(chans=ch); d = cli("doctor"); return d.returncode, d.stdout
    for u in ["https://203.0.113.7/h", "https://203.0.113.7./h", "https://3405803783/h", "https://0xcb007107/h", "https://203.0.28935/h", "https://[::1]/h", "https://hooks.zapier.com/h", "https://HOOKS.Slack.com./h"]:
        c, o = doc(f"'w'=>['type'=>'webhook','url'=>'{u}',{full}],"); ck(f"full is refused for {u}", c == 1 and "channel w: sends number only" in o, o)
    c, o = doc(f"'w'=>['type'=>'webhook','url'=>'https://crm.example.ru/h',{full}],"); ck("full is allowed for a plain host", "channel w: sends PERSONAL DATA" in o, o)
    c, o = doc("'w'=>['type'=>'webhook','url'=>'https://crm.example.ru/h','data'=>'full'],"); ck("doctor: full without a declaration", "FAIL  channel w: sends number only (in_russia is not declared)" in o, o)
    c, o = doc("'w'=>['type'=>'webhook','url'=>'https://crm.example.ru/h','in_russia'=>true],"); ck("doctor: notice by default", "OK    channel w: sends number only (configured)" in o, o)
    c, o = doc("'w'=>['type'=>'webhook','url'=>'https://hooks.zapier.com/h'],"); ck("a notice to a foreign service is not an error", "FAIL  channel" not in o)
    for u in ["http://crm.example.ru/h", "https://user@crm.example.ru/h", "https://203.0.113.7/h", "ftp://127.0.0.1/h"]:
        drop(); config(chans=f"'w'=>['type'=>'webhook','url'=>'{u}'],"); post(lead()); deliver(); r = db("select note from deliveries order by id desc limit 1")[0][0]; ck(f"never connects to {u}", r in ("https only", "bad host"), r)
    drop(); sm = lambda **k: "'smtp'=>[" + ",".join(f"'{a}'=>'{b}'" for a, b in {"host": "127.0.0.1", "peer_name": "localhost", "port": PS, "user": "u", "pass": "p", "cafile": T + "/c.pem", **k}.items()) + "],"
    def mail(opt, to="s@example.ru", frm="site@example.ru", mx="['mx.yandex.net']", smtp=None):
        drop(); config(chans=f"'m'=>['type'=>'email','to'=>'{to}','from'=>'{frm}',{opt + ',' if opt else ''}{sm() if smtp is None else smtp}],", extra=f"'mx_resolver'=>fn($d)=>{mx},"); post(lead()); deliver()
        return base64.b64decode(re.split(r"\r\n\r\n", mails[0][1], maxsplit=1)[1].rsplit("\r\n.\r\n", 1)[0]).decode() if mails else None
    for nm, a, want in [("default", ("",), False), ("full, Russian MX", (full,), True), ("full to gmail", (full, "a@gmail.com"), False), ("full, own domain on Google", (full, "s@example.ru", "site@example.ru", "$d==='example.ru'?['aspmx.l.google.com']:[]"), False), ("full, own domain on Microsoft", (full, "s@example.ru", "site@example.ru", "['example-ru.mail.protection.outlook.com']"), False), ("full, MX unknown", (full, "s@example.ru", "site@example.ru", "[]"), False), ("full, not declared", ("'data'=>'full'",), False),
                        ("full, sender on gmail", (full, "s@example.ru", "owner@gmail.com"), False), ("full, sender domain on Google", (full, "s@example.ru", "site@other.ru", "$d==='other.ru'?['aspmx.l.google.com']:['mx.yandex.net']"), False), ("full, SMTP account on gmail", (full, "s@example.ru", "site@example.ru", "['mx.yandex.net']", sm(user="x@gmail.com")), False)]:
        t = mail(*a); ck(f"mail {nm}" + (": data" if want else ": number only"), t is not None and ("Анна" in t) == want and "Z-" in t, t)
    for nm, s in [("no smtp", ""), ("foreign SMTP", sm(host="smtp.gmail.com")), ("SMTP with a port inside", sm(host="smtp.gmail.com:465")), ("SMTP by IP", sm(host="203.0.113.7")), ("foreign peer_name", sm(peer_name="smtp.gmail.com"))]:
        config(chans=f"'m'=>['type'=>'email','to'=>'s@example.ru','from'=>'site@example.ru',{full},{s}],", extra="'mx_resolver'=>fn($d)=>['mx.yandex.net'],"); d = cli("doctor"); ck(f"full mail is refused: {nm}", d.returncode == 1 and "channel m: sends number only" in d.stdout, d.stdout)
    drop(); config(chans=f"'m'=>['type'=>'email','to'=>'s@example.ru','from'=>'site@example.ru','smtp'=>['host'=>'127.0.0.1','port'=>{PS},'user'=>'u','pass'=>'p']],"); post(lead()); deliver(); ck("SMTP with an untrusted certificate is refused", not mails); drop(); config()
def deletion():
    def old(**k): post(lead(**k)); i = last()[0]; db("update leads set created_at=1 where id=?", i); return i
    i = old(name="ZZOLD"); post(lead()); ck("expired lead is deleted by a submission", not db("select 1 from leads where id=?", i))
    i = old(); post(lead(consent="")); ck("expired lead is deleted by a refused submission too", not db("select 1 from leads where id=?", i))
    config(chans=hook()); i = old(); cli("deliver"); config(); a = db("select actor,info from audit where action='destroyed' and lead=?", f"Z-{i}"); ck("expired lead is deleted by cron, with a record", not db("select 1 from leads where id=?", i) and a and a[0][0] == "system" and "retention" in a[0][1] and "name" in a[0][1] and "undelivered: 1" in a[0][1], a)
    ck("the record has no contents", clean(str(db("select * from audit"))))
    post(lead(zk_hp="x")); i = last()[0]; post(lead()); j = last()[0]; db("update leads set created_at=? where id in (?,?)", int(time.time()) - 40 * 86400, i, j); cli("deliver"); ck("suspect leads live shorter", not db("select 1 from leads where id=?", i) and db("select 1 from leads where id=?", j))
    config(chans=hook()); post(lead()); i = last()[0]; cli("delete", f"Z-{i}", "request"); ck("deliveries are deleted with the lead", not db("select 1 from deliveries where lead_id=?", i)); config()
    post(lead(name="ZZUNIQUEZZ")); i = last()[0]; c = sqlite3.connect(DBF()); c.execute("begin"); c.execute("select * from leads").fetchall(); r = cli("delete", f"Z-{i}"); ck("a busy log is reported", "busy" in r.stdout, r.stdout); c.rollback(); c.close(); cli("deliver")
    files = b"".join(open(f, "rb").read() for f in glob.glob(APP + "/storage/leads-*")); ck("deleted data is gone from the files", b"ZZUNIQUEZZ" not in files); c = sqlite3.connect(DBF()); post(lead(name="ZZHELDZZ")); i = last()[0]; cli("delete", f"{i}"); files = b"".join(open(f, "rb").read() for f in glob.glob(APP + "/storage/leads-*")); c.close(); ck("the write-ahead log is truncated after deletion", b"ZZHELDZZ" not in files)
    c0 = count(); bad = [cli("delete", x).returncode for x in ("Z-1-2", "1.5", "abc", "", "0", "Z-999999")]; ck("delete takes exactly one existing number", all(bad) and count() == c0, bad)
    db("insert into audit values(1,'x','old','','')"); cli("deliver"); ck("the log itself expires", not db("select 1 from audit where action='old'")); rows = list(csv.reader(io.StringIO(cli("audit").stdout), delimiter=";")); ck("audit prints the log", rows[0][0] == "time" and any(x[2] == "destroyed" for x in rows), rows[:2])
def storage():
    ck("database is 0600", os.stat(DBF()).st_mode & 0o077 == 0); ck("storage is 0700", os.stat(APP + "/storage").st_mode & 0o077 == 0); ck("database name cannot be guessed", re.search(r"leads-[0-9a-f]{24}\.sqlite$", DBF())); ck("storage has a deny file", "denied" in open(APP + "/storage/.htaccess").read())
    other = T + "/other"; os.makedirs(WEB + "/st", exist_ok=True); os.makedirs(other, exist_ok=True)
    config(storage=f"'{WEB}/st/new'"); ck("refuses storage inside the web root", post(lead())[0] == 500 and not os.path.exists(WEB + "/st/new")); d = cli("doctor"); ck("the command line refuses it too", d.returncode == 1 and "inside the web root" in d.stderr and not os.path.exists(WEB + "/st/new"), d.stderr)
    config(storage=f"'{other}/st'", roots=f"'{WEB}','{other}'"); ck("refuses storage inside another web root", post(lead())[0] == 500 and not os.path.exists(other + "/st"))
    config(roots=f"'{APP}'", storage=f"'{T}/elsewhere'"); ck("refuses the program inside a web root", post(lead())[0] == 500 and not os.path.exists(T + "/elsewhere")); config(roots="'/nonexistent'"); ck("an unknown web root is an error", post(lead())[0] == 500); config(roots=""); ck("web_roots is required", post(lead())[0] == 500)
    os.makedirs(WEB + "/x", exist_ok=True); config(storage=f"'{WEB}/x/st'", roots=f"'{other}'"); ck("DOCUMENT_ROOT counts as a web root", post(lead())[0] == 500 and not os.path.exists(WEB + "/x/st"))
    open(T + "/noroot.php", "w").write("<?php $_SERVER['DOCUMENT_ROOT'] = '/nonexistent';"); p = port(); x = serve(p, WEB, "-d", f"auto_prepend_file={T}/noroot.php"); time.sleep(0.6); s = post(lead(), base=f"http://127.0.0.1:{p}")[0]; ck("the directory of submit.php counts as a web root", s == 500 and not os.path.exists(WEB + "/x/st"), s); config(); s = post(lead(), base=f"http://127.0.0.1:{p}")[0]; ck("a wrong DOCUMENT_ROOT alone is harmless", s == 200, s); os.killpg(x.pid, 9)
    os.makedirs(T + "/real"); os.symlink(T + "/real", WEB + "/ln"); config(storage=f"'{WEB}/ln'"); ck("storage through a link inside the web root is refused", post(lead())[0] == 500 and not os.listdir(T + "/real")); os.unlink(WEB + "/ln")
    config(); os.symlink(APP, WEB + "/link"); d = cli("doctor"); ck("doctor sees a link to the program", d.returncode == 1 and "FAIL  no link" in d.stdout, d.stdout); os.unlink(WEB + "/link"); os.symlink(APP + "/storage", WEB + "/x/s"); d = cli("doctor"); ck("doctor sees a nested link to storage", "FAIL  no link" in d.stdout, d.stdout); os.unlink(WEB + "/x/s")
    config(secret="str_repeat('u',32)"); ck("a changed secret does not start an empty database", post(lead())[0] == 500 and len(glob.glob(APP + "/storage/leads-*.sqlite")) == 1); config(secret="'short'", storage=f"'{T}/short'"); ck("a short secret is refused", post(lead())[0] == 500 and not os.path.exists(T + "/short")); config(consents="'v1'=>' '"); ck("an empty consent text is refused", post(lead())[0] == 500)
    config(head="\ufeff<?php "); s, b, _ = post(lead()); ck("a byte order mark in config is harmless", s == 200 and json.loads(b)["ok"], b)
    config(); d = cli("doctor"); ck("doctor passes on a clean setup", d.returncode == 1 and d.stdout.count("FAIL") == 1 and "FAIL  origin https://example.ru" in d.stdout, d.stdout)
    config(origins="'https://site.ru'"); d = cli("doctor"); ck("doctor passes with a real origin", d.returncode == 0, d.stdout); os.chmod(APP + "/config.php", 0o640); d = cli("doctor"); ck("doctor refuses a group-readable config", d.returncode == 1, d.stdout); config(origins="'https://site.ru'"); os.chmod(APP + "/storage", 0o755); d = cli("doctor"); ck("doctor refuses an open storage", d.returncode == 1); os.chmod(APP + "/storage", 0o700)
    os.chmod(APP + "/config.php", 0); s, b, _ = post(lead()); ck("an unreadable config: 500 without paths", s == 500 and T not in b and "Warning" not in b, b); config()
    shutil.copytree(WEB, REL); p = port(); x = serve(p, REL, env={k: v for k, v in os.environ.items() if k != "ZAYAVKA_DIR"}); time.sleep(0.6); r = [req(f"/{f}.php", b"" if f == "submit" else None, {"Accept": "application/json"}, base=f"http://127.0.0.1:{p}") for f in ("submit", "admin")]; os.killpg(x.pid, 9); ck("a missing ZAYAVKA_DIR: 500 without paths", all(s == 500 and T not in b and "<b>" not in b for s, b, _ in r), r)
    c = sqlite3.connect(DBF(), isolation_level=None); c.execute("begin immediate"); s, b, _ = form(lead()); c.execute("rollback"); c.close(); ck("a locked database: honest 500 in plain words", s == 500 and "не принята" in b, (s, b))
    import fcntl; w = T + "/wait"; os.makedirs(w, 0o700); k = open(w + "/init.lock", "w"); fcntl.flock(k, fcntl.LOCK_EX); config(storage=f"'{w}'")
    with cf.ThreadPoolExecutor(1) as p: f = p.submit(lambda: post(lead())[0]); time.sleep(1.2); early = f.done(); made = glob.glob(w + "/leads-*"); fcntl.flock(k, fcntl.LOCK_UN); k.close(); ck("a request waits while another creates the database", not early and not made and f.result() == 200, (early, made, f.result()))
    for x in glob.glob(w + "/leads-*.sqlite*"): x.endswith(".ready") or os.remove(x)
    ck("a removed database is created again", post(lead())[0] == 200)
    new = T + "/fresh"
    for k in range(3):
        config(storage=f"'{new}{k}'")
        with cf.ThreadPoolExecutor(12) as p: r = [x[0] for x in p.map(lambda _: post(lead()), range(16))]
        ck(f"first parallel requests on an empty setup {k}", r == [200] * 16, r)
    config(); ck("password: 12 characters, not bytes", cli("password", inp="пароль\n").returncode == 1)
def admin():
    J = lambda: urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())); db("delete from hits"); post(lead(name="<script>alert(1)</script>")); a = last()[0]; post(lead(name="=cmd()", phone="+7 901 765-43-21", message="a,=1+1\n@x,-1+2; +(1)|-$A$1,\"=2")); b1 = last()[0]
    tok = lambda b: re.search(r'name="csrf" value="(\w+)"', b).group(1); P = lambda o, path="/admin.php", h=None, **k: req(path, urllib.parse.urlencode(k).encode(), h, op=o); j = J(); NR = {"Sec-Fetch-Dest": "empty", "Sec-Fetch-Site": "same-origin"}
    s, b, h = req(f"/admin.php?id={a}", op=j); ck("no login, no data", "alert(1)" not in b and "900 000" not in b and "password" in b); ck("CSP", "default-src 'none'" in h["Content-Security-Policy"] and "frame-ancestors 'none'" in h["Content-Security-Policy"])
    sc = h["Set-Cookie"]; ck("nothing is stored before login", db("select count(*) from sessions")[0][0] == 0 and not os.path.exists(APP + "/storage/sessions")); ck("cookie flags", "HttpOnly" in sc and "SameSite=Strict" in sc and "path=/admin.php" in sc, sc); config(adm="https://example.ru/admin.php")
    for nm, hd in [("real host", {"Host": "example.ru"}), ("a proxy that says localhost", {"X-Forwarded-For": "1.2.3.4"}), ("no headers at all", {})]: s, _, h = req("/admin.php", headers=hd); ck(f"cookie is Secure: {nm}", "secure" in h["Set-Cookie"].lower(), h["Set-Cookie"])
    config(); p0 = req("/admin.php")[1]; ck("a form token of another browser is refused", P(j, csrf=tok(p0), password=PASS)[0] == 403)
    ck("csrf required", P(j, password=PASS)[0] == 403); ck("no data for export without login", "Анна" not in P(j, csrf=tok(b), export=1)[1]); t = tok(b); ck("wrong password", "Неверный" in P(j, csrf=t, password="wrong")[1])
    CK = lambda o: {c.name: c.value for x in o.handlers if isinstance(x, urllib.request.HTTPCookieProcessor) for c in x.cookiejar}
    s, b, h = P(j, csrf=t, password=PASS); ck("login", "Выгрузить" in b, b[:200]); sid = CK(j)["zk"]; ck("token changes at login", "zk_pre" not in CK(j) and tok(b) != t); ck("the session number is not stored", sid not in open(DBF(), "rb").read().decode("latin1") + (open(DBF() + "-wal", "rb").read().decode("latin1") if os.path.exists(DBF() + "-wal") else "") and db("select count(*) from sessions")[0][0] == 1); t = tok(b)
    ck("login is in the log", db("select count(*) from audit where action='login'")[0][0] == 1 and db("select count(*) from audit where action='login failed'")[0][0] == 1)
    ck("a script of the site cannot read the page", req("/admin.php", headers=NR, op=j)[0] == 403); ck("a cross-site post is refused", P(j, h={"Sec-Fetch-Site": "cross-site"}, csrf=t, find="x")[0] == 403)
    s, b, _ = req(f"/admin.php?id={a}", op=j); ck("output is escaped", "&lt;script&gt;" in b and "<script>" not in b); ck("view is in the log", db("select count(*) from audit where action='view' and lead=? and actor='admin'", f"Z-{a}")[0][0] == 1)
    ck("export needs the password again", P(j, csrf=t, export=1)[0] == 403 and P(j, csrf=t, export=1, password="no")[0] == 403); s, b, _ = P(j, csrf=t, export=1, password=PASS); rows = list(csv.reader(io.StringIO(b.lstrip("\ufeff")), delimiter=";")); r = [x for x in rows if x[0] == f"Z-{b1}"][0]; ck("csv: formulas neutralised, also inside a cell", r[8] == "'=cmd()" and ",'=1+1" in r[11] and "\n'@x" in r[11], r); ck("csv: minus, plus, quote and spaces after a separator", ",'-1+2" in r[11] and "; '+(1)" in r[11] and "|'-$A$1" in r[11] and "\"'=2" in r[11], r[11]); ck("csv: phones stay clean", r[9] == "+7 901 765-43-21", r); ck("export is in the log", db("select count(*) from audit where action='export'")[0][0] == 1)
    s, b, _ = P(j, csrf=t, find="901 76543-21"); ck("search by phone digits", f"Z-{b1}<" in b and f"Z-{a}<" not in b, b[-400:]); s, b, _ = P(j, csrf=t, find="НЕТТАКОГО"); ck("search finds nothing", "?id=" not in b)
    db("update state set v='0' where k='last cron'"); ck("warnings are shown", "Cron" in req("/admin.php", op=j)[1]); post(lead(zk_hp="x")); i = last()[0]; s, b, _ = req("/admin.php", op=j); ck("suspect leads are visible", "подозрительная" in b); P(j, csrf=t, confirm=i); ck("a suspect lead can be confirmed", last()[6] == 0); post(lead()); i = last()[0]; db("update leads set suspect=2 where id=?", i); s, b, _ = req("/admin.php", op=j); ck("leads above the cap are named", "сверх дневного предела" in b)
    P(j, csrf=t, purge_over=1, sure=1, password="no"); P(j, csrf=t, purge_over=1, password=PASS); ck("deleting them all needs the password and a tick", db("select 1 from leads where id=?", i)); P(j, csrf=t, purge_over=1, sure=1, password=PASS); ck("all above the cap are deleted", not db("select 1 from leads where suspect=2") and db("select 1 from leads where id=?", a))
    P(j, csrf=t, delete=b1); ck("delete needs a confirmation", db("select 1 from leads where id=?", b1)); P(j, csrf=t, delete=b1, sure=1, reason="withdrawn"); ck("delete with a reason", not db("select 1 from leads where id=?", b1) and "withdrawn" in db("select info from audit where lead=? and action='destroyed'", f"Z-{b1}")[0][0]); P(j, csrf="x" * 32, delete=a, sure=1); ck("delete needs the token", db("select 1 from leads where id=?", a))
    for i in range(130): db("insert into leads(idem,form,page,fields,consent_id,consent_sha,consent_at,created_at) values(?,?,?,?,?,?,?,?)", f"bulk{i}", "callback", "", "{}", "v1", "x", "x", int(time.time()))
    s, b, _ = req("/admin.php", op=j); s2, b2, _ = req("/admin.php?p=2", op=j); ck("pages", b.count("?id=") == 100 and "Старее" in b and f"Z-{a}<" in b2 + req("/admin.php?p=3", op=j)[1], b.count("?id="))
    c = http.client.HTTPConnection("127.0.0.1", PW); c.request("GET", "/admin.php"); x = c.getresponse(); ckz = x.getheader("Set-Cookie").split(";")[0]; tz = tok(x.read().decode()); c = http.client.HTTPConnection("127.0.0.1", PW); c.request("POST", "//evil.ru/..%2fadmin.php", urllib.parse.urlencode({"csrf": tz, "password": PASS}), {"Content-Type": "application/x-www-form-urlencoded", "Cookie": ckz}); x = c.getresponse(); loc = x.getheader("Location") or ""; ck("redirects never leave the site", x.status in (303, 404) and "evil" not in loc, (x.status, loc)); s, b, h = P(j, csrf=t, logout=1); ck("logout", "Выгрузить" not in req("/admin.php", op=j)[1])
    k = J(); b = req("/admin.php", op=k)[1]; b = P(k, csrf=tok(b), password=PASS)[1]; ck("second login", "Выгрузить" in b); raw0 = db("select * from sessions order by since desc, seen desc")
    for nm, f, age in [("idle for 31 minutes", "seen", 1900), ("9 hours in total", "since", 33000)]:
        db("delete from sessions"); [db("insert into sessions values(?,?,?,?)", *x) for x in raw0]; db(f"update sessions set {f}={f}-{age}"); ck(f"session ends: {nm}", "Выгрузить" not in req("/admin.php", op=k)[1])
    db("delete from sessions"); [db("insert into sessions values(?,?,?,?)", *x) for x in raw0]
    ck("session still valid", "Выгрузить" in req("/admin.php", op=k)[1]); config(phash=cli("password", inp="another long password\n").stdout.strip()); ck("changing the password ends sessions", "Выгрузить" not in req("/admin.php", op=k)[1]); config(phash=""); ck("no password hash: admin page is off", req("/admin.php")[0] == 404); config()
    db("delete from hits"); b = req("/admin.php", op=j)[1]; t = tok(b); c2 = [P(j, csrf=t, password="no")[0] for _ in range(6)]; ck("429 on brute force", c2[-1] == 429 and c2[:5] == [200] * 5, c2); db("delete from hits"); config(proxy="true"); c3 = [P(j, h={"X-Real-IP": f"2001:db8::{i:x}"}, csrf=t, password="no")[0] for i in range(1, 8)]; ck("brute force from one /64", c3[-1] == 429, c3)
    db("delete from hits"); g4 = J(); t4 = tok(req("/admin.php", op=g4)[1]); c4 = [P(g4, h={"X-Real-IP": f"2001:db8:7:{i:x}::1"}, csrf=t4, password="no")[0] for i in range(1, 25)]; ck("brute force from one /48", c4[19] == 200 and c4[20] == 429, c4)
    db("delete from hits"); e = J(); te = tok(req("/admin.php", op=e)[1]); c5 = [P(e, h={"X-Real-IP": f"198.51.{i // 250}.{i % 250 + 1}"}, csrf=te, password="no")[0] for i in range(203)]; ck("everybody together: 200 wrong passwords", c5[199] == 200 and c5[200] == 429, c5[195:])
    f = J(); ck("a browser that never logged in waits", P(f, h={"X-Real-IP": "203.0.113.5"}, csrf=tok(req("/admin.php", op=f)[1]), password=PASS)[0] == 429)
    tk = tok(req("/admin.php", op=k)[1]); s, b, _ = P(k, csrf=tk, password=PASS); ck("the owner is not locked out by strangers", "Выгрузить" in b, (s, b[:120])); tk = tok(b); kt = CK(k)["zk_known"]; ck("the known-browser cookie is not stored as it is", not db("select 1 from known where h=?", kt) and db("select count(*) from known")[0][0] >= 1); ck("and can export", P(k, csrf=tk, export=1, password=PASS)[1].startswith("\ufeff"))
    tk = tok(req("/admin.php", op=k)[1]); P(k, csrf=tk, logout=1); tk = tok(req("/admin.php", op=k)[1]); ck("after a plain logout the owner still gets in", "Выгрузить" in P(k, csrf=tk, password=PASS)[1]); tk = tok(req("/admin.php", op=k)[1]); P(k, csrf=tk, logout=1, forget=1); tk = tok(req("/admin.php", op=k)[1]); ck("a forgotten browser is a stranger again", P(k, csrf=tk, password=PASS)[0] == 429); db("delete from hits"); tk = tok(req("/admin.php", op=k)[1]); P(k, csrf=tk, password=PASS)
    ck("a huge page number is harmless", req("/admin.php?p=9223372036854775807", op=k)[0] == 200); ck("a long password is refused", cli("password", inp="я" * 37 + "\n").returncode == 1 and cli("password", inp="я" * 36 + "\n").returncode == 0); config(); db("delete from hits")
def upgrade():
    sh = ROOT + "/tests/v1.sql"; st = T + "/old"; os.makedirs(st, 0o700); nm = "leads-" + hmac.new(b"t" * 32, b"db", hashlib.sha256).hexdigest()[:24] + ".sqlite"
    c = sqlite3.connect(f"{st}/{nm}"); c.executescript(open(sh).read()); c.close(); open(f"{st}/{nm}.ready", "w").close(); os.makedirs(st + "/sessions"); open(st + "/sessions/sess_abc", "w").close(); os.chmod(f"{st}/{nm}", 0o600)
    config(storage=f"'{st}'", chans=hook())
    with cf.ThreadPoolExecutor(8) as p: r = [x[0] for x in p.map(lambda _: post(lead()), range(16))]
    c = sqlite3.connect(f"{st}/{nm}"); ck("upgrade from 0.1.0: leads stay, requests pass", r == [200] * 16 and c.execute("select count(*) from leads").fetchone()[0] == 18 and c.execute("pragma user_version").fetchone()[0] == 2, r)
    ck("upgrade: queue stays, numbers do not repeat", c.execute("select id, status from deliveries where id<10 order by id").fetchall() == [(1, "sent"), (2, "pending")] and c.execute("select min(id) from deliveries where id>10").fetchone()[0] > 1000000); ck("upgrade: session files are gone", not os.path.exists(st + "/sessions"))
    c.execute("pragma foreign_keys=on"); c.execute("delete from leads where id=1"); ck("upgrade: deleting a lead deletes its deliveries", c.execute("select count(*) from deliveries where lead_id=1").fetchone()[0] == 0); c.rollback(); c.close()
    for x in glob.glob(f"{st}/{nm}*"):
        if not x.endswith(".v2"): os.unlink(x)
    c = sqlite3.connect(f"{st}/{nm}"); c.executescript(open(sh).read()); c.close(); os.chmod(f"{st}/{nm}", 0o600); r = req("/admin.php")[0]; o = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    b = req("/admin.php", op=o)[1]; s, b, _ = req("/admin.php", urllib.parse.urlencode({"csrf": re.search(r'name="csrf" value="(\w+)"', b).group(1), "password": PASS}).encode(), op=o); ck("an old copy put back after the upgrade is converted, login works", r == 200 and "Выгрузить" in b, (r, s, b[:120]))
    c = sqlite3.connect(f"{st}/{nm}"); c.execute("pragma user_version=3"); c.commit(); c.close(); e0 = os.path.getsize(T + "/err"); s = post(lead())[0]; ck("a database of a newer version is refused in plain words", s == 500 and "newer version" in open(T + "/err").read()[e0:]); config()
try:
    for f in (accept, unicode, origins, bodies, traps, repeats, limits, channels, integrations, policy, deletion, storage, admin, upgrade):
        try: f()
        except Exception as e: ck(f"section {f.__name__} finished", False, repr(e)); config()
    ck("no personal data in the error log", clean(open(T + "/err").read()))
finally:
    for s in srv:
        try: os.killpg(s.pid, 9)
        except OSError: pass
    shutil.rmtree(T, ignore_errors=True)
print(f"passed {n[0]}, failed {n[1]}"); raise SystemExit(1 if n[1] else 0)
