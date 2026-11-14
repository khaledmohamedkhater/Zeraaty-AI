<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/database.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    return trim((string) fgets(STDIN));
}

$name = prompt('اسم المدير: ');
$email = strtolower(prompt('البريد الإلكتروني: '));
$password = prompt('كلمة المرور (10 أحرف على الأقل): ');
if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || strlen($password) < 10) {
    fwrite(STDERR, "البيانات غير صحيحة. لم يتم إنشاء الحساب.\n");
    exit(1);
}

try {
    $statement = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
    $statement->execute(['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'admin']);
    fwrite(STDOUT, "تم إنشاء حساب المدير.\n");
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        fwrite(STDERR, "البريد مسجل مسبقًا؛ لم يتغير أي حساب.\n");
        exit(1);
    }
    throw $exception;
}
