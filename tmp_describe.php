<?php
require_once __DIR__ . '/includes/config.php';
$db = getDB();
$stmt = $db->query('DESCRIBE documents');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: text/plain; charset=utf-8');
foreach ($rows as $r) {
    echo $r['Field'] . ':' . $r['Type'] . PHP_EOL;
}

