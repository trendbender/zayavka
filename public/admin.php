<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex'); header('Cross-Origin-Opener-Policy: same-origin');
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$lib = (getenv('ZAYAVKA_DIR') ?: dirname(__DIR__)) . '/src/lib.php';
try {
    if (!is_file($lib)) throw new RuntimeException($lib . ' not found, set ZAYAVKA_DIR');
    require $lib;
    $c = zk_cfg(); zk_db();
    if (!$c['admin_password_hash']) { http_response_code(404); exit; }
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'none'; $dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document'; $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($dest !== 'document' || ($post && !in_array($site, ['same-origin', 'none'], true))) { http_response_code(403); exit('forbidden'); }    // scripts of the site may not read this page
    if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('session.auto_start must be off');
    $self = '/' . ltrim($_SERVER['SCRIPT_NAME'], '/');                                 // never from REQUEST_URI: //evil.ru/ would become a redirect
    $sd = $c['storage'] . '/sessions';
    if (!is_dir($sd) && !@mkdir($sd, 0700) && !is_dir($sd)) throw new RuntimeException('cannot create sessions');
    $local = in_array(strtok($_SERVER['HTTP_HOST'] ?? '', ':'), ['127.0.0.1', 'localhost'], true);
    session_save_path($sd); session_name('zk'); session_set_cookie_params(['httponly' => true, 'secure' => !$local, 'samesite' => 'Strict', 'path' => $self]);
    foreach (['use_strict_mode' => '1', 'use_only_cookies' => '1', 'gc_probability' => '1', 'gc_divisor' => '50', 'gc_maxlifetime' => '28800'] as $k => $v) ini_set("session.$k", $v);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    if ($post && !hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) { http_response_code(403); exit('csrf'); }
    $mark = hash_hmac('sha256', $c['admin_password_hash'], $c['secret']);              // changing the password ends every session
    if (isset($_SESSION['in']) && (time() - $_SESSION['in'] > 1800 || time() - $_SESSION['since'] > 28800 || !hash_equals($mark, $_SESSION['mark']))) unset($_SESSION['in']);
    $go = function (string $q = '') use ($self): void { header('Location: ' . $self . $q, true, 303); exit; };
    $check = function () use ($c): bool {
        if (zk_limited('login', 5, 900) || zk_limited('login', 200, 900, '*')) { http_response_code(429); exit('Слишком много попыток. Подождите 15 минут.'); }
        $ok = is_string($_POST['password'] ?? null) && password_verify($_POST['password'], $c['admin_password_hash']);
        if ($ok) zk_forgive('login');                                                  // only wrong passwords count
        return $ok;
    };
    $csrf = fn(): string => '<input type="hidden" name="csrf" value="' . $h($_SESSION['csrf']) . '">';
    $top = fn(string $t): string => '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $t . '</title><body style="font:15px system-ui;max-width:900px;margin:24px auto;padding:0 16px">';
    $err = '';
    if (empty($_SESSION['in'])) {
        if ($post && isset($_POST['password'])) {
            if ($check()) { session_regenerate_id(true); $_SESSION = ['in' => time(), 'since' => time(), 'mark' => $mark, 'csrf' => bin2hex(random_bytes(16))]; zk_audit('admin', 'login'); $go(isset($_GET['id']) ? '?id=' . (int) $_GET['id'] : ''); }
            zk_audit('admin', 'login failed'); $err = 'Неверный пароль';
        }
        echo $top('Вход') . '<h1>Заявки</h1><form method="post">' . $csrf() . '<p><label>Пароль<br><input type="password" name="password" autocomplete="current-password" required></label><p><button>Войти</button> <span role="alert">' . $h($err) . '</span></form>';
        exit;
    }
    $_SESSION['in'] = time();
    if ($post && isset($_POST['logout'])) { $_SESSION = []; session_destroy(); $go(); }
    if ($post && isset($_POST['delete'])) {
        if (empty($_POST['sure'])) { http_response_code(422); exit('Отметьте «Да, удалить».'); }
        $id = (int) $_POST['delete']; zk_delete($id, 'admin', is_string($_POST['reason'] ?? null) && isset(['request' => 1, 'withdrawn' => 1, 'spam' => 1, 'goal' => 1][$_POST['reason']]) ? $_POST['reason'] : 'manual');
        if (!zk_checkpoint()) zk_state('checkpoint', 'pending');
        $go();
    }
    if ($post && isset($_POST['confirm'])) {                                           // not spam: the owner lets it through
        zk_q('UPDATE leads SET suspect=0 WHERE id=?', [(int) $_POST['confirm']]); zk_audit('admin', 'marked as real', zk_key((int) $_POST['confirm'])); $go('?id=' . (int) $_POST['confirm']);
    }
    if ($post && isset($_POST['export'])) {
        if (!$check()) { zk_audit('admin', 'export refused'); http_response_code(403); exit('Для выгрузки нужен пароль.'); }
        zk_audit('admin', 'export'); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="leads.csv"');
        echo "\xEF\xBB\xBF" . zk_csv(array_merge(['key', 'created', 'form', 'page', 'consent', 'consent_sha256', 'consent_at', 'suspect'], array_keys($c['fields'])));
        foreach (zk_q('SELECT * FROM leads ORDER BY id') as $l) { $d = json_decode($l['fields'], true); $row = [zk_key((int) $l['id']), date('c', (int) $l['created_at']), $l['form'], $l['page'], $l['consent_id'], $l['consent_sha'], $l['consent_at'], $l['suspect']]; foreach (array_keys($c['fields']) as $k) $row[] = $d[$k] ?? ''; echo zk_csv($row); }
        exit;
    }
    echo $top('Заявки') . '<h1><a href="' . $h($self) . '">Заявки</a></h1>';
    if (isset($_GET['id'])) {
        $l = zk_q('SELECT * FROM leads WHERE id=?', [(int) $_GET['id']])->fetch();
        if (!$l) { http_response_code(404); echo '<p>Заявки нет: удалена или не существует.'; exit; }
        zk_audit('admin', 'view', zk_key((int) $l['id']));
        echo '<h2>' . $h(zk_key((int) $l['id'])) . ($l['suspect'] ? ' (подозрительная)' : '') . '</h2><pre style="white-space:pre-wrap">' . $h(zk_full($l)) . '</pre><p>Отправка: ';
        foreach (zk_q('SELECT * FROM deliveries WHERE lead_id=?', [$l['id']]) as $d) echo $h($d['channel'] . ': ' . $d['status'] . ' (' . $d['note'] . ') ');
        if ($l['suspect']) echo '<form method="post">' . $csrf() . '<button name="confirm" value="' . (int) $l['id'] . '">Это настоящая заявка</button></form>';
        echo '<form method="post">' . $csrf() . '<p><label>Причина <select name="reason"><option value="request">требование человека<option value="withdrawn">отзыв согласия<option value="goal">цель достигнута<option value="spam">спам</select></label> <label><input type="checkbox" name="sure" value="1" required> Да, удалить</label> <button name="delete" value="' . (int) $l['id'] . '">Удалить безвозвратно</button></form>'; exit;
    }
    foreach (zk_warnings() as $w) echo '<p style="background:#fff3cd;padding:8px">' . $h($w);
    $find = is_string($_POST['find'] ?? null) ? mb_substr(trim($_POST['find']), 0, 100) : ''; $page = max(1, (int) ($_GET['p'] ?? 1));
    echo '<form method="post">' . $csrf() . '<input name="find" value="' . $h($find) . '" aria-label="Поиск"> <button>Найти</button></form>';    // POST: the search text must not reach server logs
    $rows = [];
    if ($find !== '') {
        zk_audit('admin', 'search'); $low = mb_strtolower($find); $num = preg_replace('/\D/', '', $find);
        foreach (zk_q('SELECT * FROM leads ORDER BY id DESC') as $l) {
            $all = mb_strtolower(implode("\n", (array) json_decode($l['fields'], true)));
            if (str_contains($all, $low) || (strlen($num) >= 5 && str_contains(preg_replace('/\D/', '', $all), $num))) $rows[] = $l;
            if (count($rows) >= 500) break;
        }
    } else $rows = zk_q('SELECT id,form,created_at,suspect FROM leads ORDER BY id DESC LIMIT 101 OFFSET ?', [($page - 1) * 100])->fetchAll();
    echo '<table cellpadding="6"><tr><th align="left">Номер<th align="left">Дата<th align="left">Форма</tr>';
    foreach ($find === '' ? array_slice($rows, 0, 100) : $rows as $l) echo '<tr><td><a href="' . $h($self) . '?id=' . (int) $l['id'] . '">' . $h(zk_key((int) $l['id'])) . '</a><td>' . date('d.m.Y H:i', (int) $l['created_at']) . '<td>' . $h($c['forms'][$l['form']] ?? $l['form']) . ($l['suspect'] ? ' (подозрительная)' : '') . '</tr>';
    echo '</table><p>' . ($page > 1 ? '<a href="' . $h($self) . '?p=' . ($page - 1) . '">Новее</a> ' : '') . ($find === '' && count($rows) > 100 ? '<a href="' . $h($self) . '?p=' . ($page + 1) . '">Старее</a>' : '');
    echo '<form method="post">' . $csrf() . '<p><input type="password" name="password" aria-label="Пароль для выгрузки" autocomplete="off" placeholder="пароль"> <button name="export" value="1">Выгрузить CSV</button></form><form method="post">' . $csrf() . '<button name="logout" value="1">Выйти</button></form>';
} catch (Throwable $e) { error_log('zayavka admin: ' . get_class($e) . ' ' . $e->getMessage()); if (!headers_sent()) http_response_code(500); echo 'error'; }
