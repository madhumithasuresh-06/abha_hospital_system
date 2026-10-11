<?php
// Temporary DB schema check - delete after use
$pdo = new PDO('mysql:host=localhost;dbname=abha_hospital_db;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== TABLES ===\n";
$r = $pdo->query('SHOW TABLES');
while ($row = $r->fetch(PDO::FETCH_NUM)) { echo $row[0] . "\n"; }

foreach (['prescriptions', 'prescription_items', 'consultations', 'doctors'] as $table) {
    echo "\n=== $table ===\n";
    $r = $pdo->query("DESCRIBE $table");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | Null:' . $row['Null'] . ' | Key:' . $row['Key'] . ' | Default:' . ($row['Default'] ?? 'NULL') . ' | ' . $row['Extra'] . "\n";
    }
}

echo "\n=== EXISTING PRESCRIPTION DATA COUNT ===\n";
$r = $pdo->query('SELECT COUNT(*) as cnt FROM prescriptions');
echo "Prescriptions: " . $r->fetch(PDO::FETCH_ASSOC)['cnt'] . "\n";
$r = $pdo->query('SELECT COUNT(*) as cnt FROM prescription_items');
echo "Prescription Items: " . $r->fetch(PDO::FETCH_ASSOC)['cnt'] . "\n";
