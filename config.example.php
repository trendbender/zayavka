<?php
/* Copy to config.php, chmod 600, owner = the user that PHP of the website runs as.
   Keep the whole directory outside the web root. */
return [
    'secret' => '',                                   // php -r 'echo bin2hex(random_bytes(32));'  Do not change later: the database name depends on it
    'allowed_origins' => ['https://example.ru'],      // exactly as in the address bar; add the www variant if you use it
    'web_roots' => ['/var/www/example.ru/public'],    // every directory of this account that is served over HTTP, subdomains too
    'storage' => __DIR__ . '/storage',
    'timezone' => 'Europe/Moscow',
    'retention_days' => 365,                          // older leads are deleted
    'suspect_days' => 30,                             // leads that filled the trap field
    'audit_days' => 1096,                             // records of access and destruction, kept without lead contents
    'rate_limit' => 10, 'rate_window' => 600,         // leads per address
    'max_per_day' => 2000,                            // all leads, protects the disk
    'trust_proxy' => false,                           // true only if your proxy always overwrites X-Real-IP
    'notice_show_form' => true,                       // false hides the form label from Telegram, email and webhook notices
    'deliver_on_submit' => true,                      // works under PHP-FPM and LiteSpeed; everywhere else cron sends
    'thanks_url' => '/spasibo/',
    // Every consent edition you have shown, id => full text. The form sends its id in consent_version.
    'consents' => [
        '2026-12-01' => 'Полный текст согласия на обработку персональных данных. Замените его своим, example.ru.',
    ],
    'forms' => ['callback' => 'Обратный звонок', 'brief' => 'Бриф'],      // the only form names accepted
    'fields' => [
        'name' => ['label' => 'Имя', 'max' => 100],
        'phone' => ['label' => 'Телефон', 'type' => 'phone', 'max' => 25],
        'email' => ['label' => 'E-mail', 'type' => 'email', 'max' => 160],
        'message' => ['label' => 'Сообщение', 'max' => 3000, 'multiline' => true],
    ],
    'require_any' => ['phone', 'email'],
    'admin_password_hash' => '',                      // bin/zayavka password; empty = admin page is off
    'admin_url' => 'https://example.ru/zayavka/admin.php',
    'channels' => [
        // data: 'notice' = number, form label, time and a link behind a login. 'full' = personal data.
        // 'full' also needs in_russia => true, and known foreign mailboxes and services are refused even then.
        // 'mail' => ['type' => 'email', 'to' => 'sales@example.ru', 'from' => 'site@example.ru', 'data' => 'full', 'in_russia' => true,
        //            'smtp' => ['host' => 'smtp.yandex.ru', 'port' => 465, 'user' => '', 'pass' => '']],
        // 'tg'   => ['type' => 'telegram', 'token' => '', 'chat_id' => ''],            // notice only, cannot be changed
        // 'crm'  => ['type' => 'webhook', 'url' => 'https://crm.example.ru/hook', 'secret' => '', 'data' => 'full', 'in_russia' => true],
    ],
];
