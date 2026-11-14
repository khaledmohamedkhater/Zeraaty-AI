<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $emailInput = $_POST['email'] ?? null;
    $passwordInput = $_POST['password'] ?? null;

    if (is_string($emailInput)) {
        $email = strtolower(trim($emailInput));
    }

    if (is_string($emailInput) && is_string($passwordInput) && $email !== '' && $passwordInput !== '') {
        $statement = $pdo->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if ($user && password_verify($passwordInput, $user['password_hash'])) {
            session_regenerate_id(true);
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            header('Location: dashboard.php');
            exit;
        }
    }
    $error = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
}
$pageTitle = 'تسجيل الدخول';
require __DIR__ . '/includes/header.php';
?>
<section class="card form-card">
    <h1>تسجيل الدخول</h1>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="login.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>البريد الإلكتروني<input type="email" name="email" autocomplete="email" required value="<?= e($email) ?>"></label>
        <label>كلمة المرور<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="button" type="submit">دخول</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
