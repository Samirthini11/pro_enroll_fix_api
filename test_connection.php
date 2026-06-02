<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$result = ['db' => 'FAIL', 'tables' => [], 'php' => phpversion()];

try {
    $pdo = new PDO(
        'mysql:host=sql201.infinityfree.com;port=3306;dbname=if0_42015172_pro_enroll;charset=utf8mb4',
        'if0_42015172',
        'hXxxTBpbzI',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $result['db'] = 'OK';
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $result['tables'] = $tables;
} catch (Exception $e) {
    $result['error'] = $e->getMessage();
}

echo json_encode($result, JSON_PRETTY_PRINT);
