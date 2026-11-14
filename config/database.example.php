<?php
declare(strict_types=1);

/*
 * Copy this file to database.php and configure environment variables.
 * Never commit real database credentials.
 */

$host = getenv('ZERAATY_DB_HOST');
$name = getenv('ZERAATY_DB_NAME');
$user = getenv('ZERAATY_DB_USER');
$password = getenv('ZERAATY_DB_PASSWORD');

if (
    !is_string($host) || trim($host) === '' ||
    !is_string($name) || trim($name) === '' ||
    !is_string($user) || trim($user) === '' ||
    !is_string($password)
) {
    error_log('Zeraaty AI database environment is incomplete.');
    http_response_code(503);
    exit('Database configuration unavailable.');
}

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    trim($host),
    trim($name)
);

try {
    $pdo = new PDO($dsn, trim($user), $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    error_log('Zeraaty AI database connection failed.');
    http_response_code(503);
    exit('Database connection unavailable.');
}