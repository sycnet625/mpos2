<?php
// Migración no destructiva: permite hashes de PIN con password_hash().
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/db.php';
$pdo->exec('ALTER TABLE pos_cashiers MODIFY pin VARCHAR(255) NOT NULL');
$rows = $pdo->query('SELECT id, pin FROM pos_cashiers')->fetchAll(PDO::FETCH_ASSOC);
$update = $pdo->prepare('UPDATE pos_cashiers SET pin = ? WHERE id = ? AND pin = ?');
$migrated = 0;
foreach ($rows as $row) {
    $stored = (string)$row['pin'];
    if (password_get_info($stored)['algo'] !== null) continue;
    $update->execute([password_hash($stored, PASSWORD_DEFAULT), (int)$row['id'], $stored]);
    $migrated += $update->rowCount();
}
echo "pos_cashiers.pin ampliado; PINes existentes migrados a hash: {$migrated}.\n";
