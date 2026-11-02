<?php
return static function (PDO $pdo): void {
    if ((int) $pdo->query('SELECT COUNT(*) FROM ticket_print_templates')->fetchColumn() > 0) {
        return;
    }
    $fields = json_encode(['logo', 'offer', 'username', 'password', 'duration', 'price', 'ssid', 'instructions']);
    $templates = [
        ['Ticket individuel', 'single', 0],
        ['Planche de 10', 'sheet10', 0],
        ['Planche de 20', 'sheet20', 1],
        ['Planche de 40', 'sheet40', 0],
        ['Thermique 58 mm', 'thermal58', 0],
        ['Thermique 80 mm', 'thermal80', 0],
        ['Format A4', 'a4', 0],
    ];
    $stmt = $pdo->prepare('INSERT INTO ticket_print_templates (name, format, fields, options, is_default) VALUES (?,?,?,?,?)');
    foreach ($templates as [$name, $format, $default]) {
        $stmt->execute([$name, $format, $fields, json_encode(['accent' => '#D7192A', 'show_qr' => false]), $default]);
    }
};
