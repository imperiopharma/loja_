<?php
// inc/config.php
// (1) Config de BD
$dbHost = 'localhost';
$dbName = 'imperiopharma_loja_db';
$dbUser = 'imperiopharma_loja_user';
$dbPass = 'Miguel22446688';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Exemplo: forçar time_zone se necessário
    // $pdo->exec("SET time_zone='-03:00'");
} catch (Exception $e) {
    die("Erro ao conectar ao BD: " . $e->getMessage());
}
