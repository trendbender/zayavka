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
        // Ad tags and the Metrica client id, see examples/form.html
        // 'utm_source' => ['label' => 'utm_source', 'max' => 100], 'utm_medium' => ['label' => 'utm_medium', 'max' => 100], 'utm_campaign' => ['label' => 'utm_campaign', 'max' => 100],
        // 'yclid' => ['label' => 'yclid', 'max' => 100], 'ym_client_id' => ['label' => 'Metrica client id', 'max' => 100],
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

        // ---- Ready integrations. Uncomment one, put your own address and key, then run bin/zayavka doctor. ----
        // map = the body the receiver expects. Placeholders: {key} {delivery} {created_at} {form} {form_label} {link} {page} {text}
        // {consent_id} {consent_sha256} {consent_at} {field:NAME}. A branch whose placeholders are all empty is left out.
        // The templates are off for personal data: in_russia is false, every personal placeholder is empty. Set it to true only
        // after you have read where the receiver keeps data and signed a processing assignment with it.

        // Bitrix24, incoming webhook with the CRM right, method crm.lead.add (https://apidocs.bitrix24.ru/api-reference/crm/leads/crm-lead-add.html)
        // 'bitrix24' => ['type' => 'webhook', 'url' => 'https://YOUR.bitrix24.ru/rest/1/WEBHOOK_CODE/crm.lead.add.json', 'data' => 'full', 'in_russia' => false,
        //     'map' => ['fields' => [
        //         'TITLE' => 'Заявка {key}: {form_label}', 'NAME' => '{field:name}', 'SOURCE_ID' => 'WEB', 'COMMENTS' => '{text}',
        //         'PHONE' => [['VALUE' => '{field:phone}', 'VALUE_TYPE' => 'WORK']], 'EMAIL' => [['VALUE' => '{field:email}', 'VALUE_TYPE' => 'WORK']],
        //         // 'UTM_SOURCE' => '{field:utm_source}', 'UTM_MEDIUM' => '{field:utm_medium}', 'UTM_CAMPAIGN' => '{field:utm_campaign}',
        //     ]]],

        // amoCRM, long-lived token of a private integration, method leads/complex (https://www.amocrm.ru/developers/content/crm_platform/leads-api)
        // 'amocrm' => ['type' => 'webhook', 'url' => 'https://YOUR.amocrm.ru/api/v4/leads/complex', 'data' => 'full', 'in_russia' => false,
        //     'headers' => ['Authorization' => 'Bearer LONG_LIVED_TOKEN'],
        //     'map' => [['name' => 'Заявка {key}: {form_label}', '_embedded' => ['contacts' => [['first_name' => '{field:name}', 'custom_fields_values' => [
        //         ['field_code' => 'PHONE', 'values' => [['enum_code' => 'WORK', 'value' => '{field:phone}']]],
        //         ['field_code' => 'EMAIL', 'values' => [['enum_code' => 'WORK', 'value' => '{field:email}']]],
        //     ]]]]]]],

        // Self-hosted PMS (https://github.com/trendbender/pms)
        // 'pms' => ['type' => 'webhook', 'url' => 'https://pms.example.ru/api/ingest/leads', 'data' => 'full', 'in_russia' => false,
        //     'headers' => ['X-Lead-Token' => 'PROJECT_TOKEN'],
        //     'map' => ['title' => 'Заявка {key}: {form_label}', 'source' => 'site', 'external_id' => 'zayavka-{key}', 'contact_name' => '{field:name}',
        //         'phone' => '{field:phone}', 'email' => '{field:email}', 'source_url' => '{page}', 'data' => ['message' => '{field:message}', 'consent' => '{consent_id} {consent_at}']]],

        // Any system that takes an ordinary form post
        // 'other' => ['type' => 'webhook', 'url' => 'https://crm.example.ru/in', 'format' => 'form', 'data' => 'full', 'in_russia' => false,
        //     'map' => ['name' => '{field:name}', 'phone' => '{field:phone}', 'comment' => '{text}']],

        // Your own code in a file of its own, see examples/own.php. Runs from cron in a separate process, 15 seconds by default.
        // The program cannot see where your code sends data: that is your responsibility.
        // 'own' => ['type' => 'php', 'target' => 'my-database', 'file' => __DIR__ . '/own.php', 'timeout' => 15, 'data' => 'full', 'in_russia' => false],
    ],
];
