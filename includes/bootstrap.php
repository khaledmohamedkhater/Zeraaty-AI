<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        exit('انتهت صلاحية الطلب. أعد تحميل الصفحة وحاول مرة أخرى.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_role(string $role): array
{
    $user = require_login();
    global $pdo;

    $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
        revoke_login_session();
    }

    // Refresh the indexed user row only on role-protected pages.
    try {
        $statement = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $currentRole = $statement->fetchColumn();
    } catch (Throwable $exception) {
        error_log('Zeraaty AI role verification failed.');
        http_response_code(503);
        exit('تعذر التحقق من صلاحية الحساب حاليًا. حاول مرة أخرى.');
    }

    if (!is_string($currentRole) || !in_array($currentRole, ['farmer', 'admin'], true)) {
        revoke_login_session();
    }

    if (($user['role'] ?? null) !== $currentRole) {
        if (!@session_regenerate_id(true)) {
            http_response_code(503);
            exit('تعذر تحديث جلسة الدخول. حاول مرة أخرى.');
        }
        $_SESSION['user']['role'] = $currentRole;
        $user['role'] = $currentRole;
    }

    if ($currentRole !== $role) {
        http_response_code(403);
        exit('ليس لديك صلاحية لعرض هذه الصفحة.');
    }
    return $user;
}

function revoke_login_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
    header('Location: login.php');
    exit;
}
