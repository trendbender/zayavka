<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$ct = strtolower(trim(strtok($_SERVER['CONTENT_TYPE'] ?? '', ';') ?: ''));
$json = $ct === 'application/json' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function zk_reply(int $code, array $d, bool $json, string $thanks = '/'): void {
    if ($json) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d); return; }
    if ($code === 200) { header('Location: ' . $thanks, true, 303); return; }
    http_response_code($code); header('Content-Type: text/plain; charset=utf-8');
    $t = [409 => 'Страница устарела. Вернитесь, обновите её и отправьте заявку ещё раз.', 413 => 'Слишком длинное сообщение.', 422 => 'Вернитесь и проверьте поля формы.', 429 => 'Заявка не принята: слишком много заявок подряд. Попробуйте позже.', 503 => 'Заявка не принята: на сайте закончилось место. Свяжитесь с нами другим способом.'];
    echo $code === 422 && ($d['error'] ?? '') === 'consent' ? 'Вернитесь и отметьте согласие на обработку персональных данных.' : ($t[$code] ?? 'Заявка не принята: ошибка на сайте. Свяжитесь с нами другим способом.');
}

$lib = (getenv('ZAYAVKA_DIR') ?: dirname(__DIR__)) . '/src/lib.php';
if (!is_file($lib)) { error_log('zayavka: ' . $lib . ' not found, set ZAYAVKA_DIR'); zk_reply(500, ['ok' => false, 'error' => 'server'], $json); exit; }

try {
    require $lib;
    $c = zk_cfg(); zk_guard();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); zk_reply(405, ['ok' => false, 'error' => 'method'], $json); exit; }
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o === '' && preg_match('~^https?://[^/?#]+~i', $_SERVER['HTTP_REFERER'] ?? '', $m)) $o = $m[0];
    // a page with "no-referrer" sends no usable origin; the browser still says the request came from the same site
    if (($o === '' || $o === 'null') && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin') $o = (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http://' : 'https://') . ($_SERVER['HTTP_HOST'] ?? '');
    $o = zk_origin($o);
    if ($o === null || !in_array($o, $c['allowed_origins'], true)) {
        zk_reply(403, ['ok' => false, 'error' => 'origin'], $json);
        zk_count('refused: origin'); if ($o !== null && strlen($o) < 100) zk_state('refused origin', $o);
        exit;
    }
    $len = $_SERVER['CONTENT_LENGTH'] ?? '';
    if ($ct !== 'application/json' && $ct !== 'application/x-www-form-urlencoded') [$code, $d] = [415, ['ok' => false, 'error' => 'type']];
    elseif (!ctype_digit((string) $len)) [$code, $d] = [411, ['ok' => false, 'error' => 'length']];
    elseif ((int) $len > $c['max_bytes']) [$code, $d] = [413, ['ok' => false, 'error' => 'size']];
    else {
        $in = $_POST;
        if ($ct === 'application/json') $in = json_decode((string) file_get_contents('php://input', false, null, 0, $c['max_bytes']), true);
        [$code, $d] = is_array($in) ? zk_accept($in) : [400, ['ok' => false, 'error' => 'json']];
    }
    zk_reply($code, $d, $json, $c['thanks_url']);
    $async = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
    if ($async) $async();
    // the lead is already stored: nothing below may turn the answer into an error
    try {
        if ($code !== 200 && $code !== 422) zk_count("refused: $code");
        if ($code === 200) { zk_count('accepted'); if (!$c['trust_proxy'] && (isset($_SERVER['HTTP_X_REAL_IP']) || isset($_SERVER['HTTP_X_FORWARDED_FOR']))) zk_count('via proxy'); }
        zk_purge();
    } catch (Throwable $e) { error_log('zayavka purge: ' . get_class($e) . ' ' . $e->getMessage()); }
    try {
        if ($code === 200 && $async && $c['deliver_on_submit']) zk_deliver(20);       // without a finish function the visitor would wait: cron sends
    } catch (Throwable $e) { error_log('zayavka deliver: ' . get_class($e) . ' ' . $e->getMessage()); }
} catch (Throwable $e) {
    error_log('zayavka: ' . get_class($e) . ' ' . $e->getMessage());
    if (!headers_sent()) zk_reply(500, ['ok' => false, 'error' => 'server'], $json);
}
