<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
function db(): PDO{
    static $pdo = null;
    if($pdo instanceof PDO) return $pdo;


    // Xampp defaults: user 'root' empty password.

    $host = 'localhost';
    $db = 'venture_magnate_db';
    $user = 'root';
    $password = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $opts =[
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try{
        $pdo = new PDO($dsn, $user, $password, $opts);
        return $pdo;
    }
    catch(PDOException $e){
        http_response_code(500);
        // For production, log this instead of echoing details
        exit('Database connection failed.');
    }
    }

?>