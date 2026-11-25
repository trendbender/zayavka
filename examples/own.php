<?php
/* Own integration. Copy next to config.php as own.php, chmod 600, uncomment and edit.
   $v holds the placeholders: $v['key'], $v['form'], $v['field:name'] and so on. Personal ones are empty until the channel
   has data => full and in_russia => true. Throw an exception to have the delivery repeated. Deliveries can repeat: make the
   write safe to repeat, here by a UNIQUE (number) key in your table. */
return function (array $v): void {
    // $db = new PDO('mysql:host=localhost;dbname=crm;charset=utf8mb4', 'user', 'password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // $db->prepare('INSERT INTO leads (number, name, phone) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE number = number')
    //    ->execute([$v['key'], $v['field:name'], $v['field:phone']]);
    throw new RuntimeException('own.php is not written yet');
};
