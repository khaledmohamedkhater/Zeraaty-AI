<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}
$errors = [];
$name = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $nameInput = $_POST['full_name'] ?? null;
    if (!is_string($nameInput)) {
        $errors[] = 'الاسم يجب أن يكون نصًا.';
    } else {
        $name = trim($nameInput);
        if ($name === '' || mb_strlen($name) > 150) $errors[] = 'اكتب اسمًا صحيحًا.';
    }

    $emailInput = $_POST['email'] ?? null;
    if (!is_string($emailInput)) {
        $errors[] = 'البريد الإلكتروني يجب أن يكون نصًا.';
    } else {
        $email = strtolower(trim($emailInput));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $errors[] = 'اكتب بريدًا إلكترونيًا صحيحًا.';
    }

    $passwordInput = $_POST['password'] ?? null;
    if (!is_string($passwordInput)) {
        $errors[] = 'كلمة المرور يجب أن تكون نصًا.';
        $password = '';
    } else {
        $password = $passwordInput;
        if (strlen($password) < 10) $errors[] = 'كلمة المرور يجب أن تكون 10 أحرف على الأقل.';
    }
    if (!$errors) {
        try {
            $statement = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'farmer',
            ]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int) $pdo->lastInsertId(), 'full_name' => $name, 'email' => $email, 'role' => 'farmer'];
            header('Location: dashboard.php');
            exit;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') $errors[] = 'هذا البريد مسجل من قبل.';
            else throw $exception;
        }
    }
}
$pageTitle = 'إنشاء حساب مزارع';
require __DIR__ . '/includes/header.php';
?>
<section class="card form-card">
    <h1>إنشاء حساب مزارع</h1>
    <p class="muted">الحسابات الجديدة تحصل دائمًا على صلاحية المزارع.</p>
    <?php if ($errors): ?><div class="alert" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" action="register.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>الاسم الكامل<input name="full_name" maxlength="150" autocomplete="name" required value="<?= e($name) ?>"></label>
        <label>البريد الإلكتروني<input type="email" name="email" maxlength="190" autocomplete="email" required value="<?= e($email) ?>"></label>
        <label>كلمة المرور<input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
        <button class="button" type="submit">إنشاء الحساب</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
