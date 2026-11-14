<?php
$pageTitle = $pageTitle ?? 'Zeraaty AI';
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | Zeraaty AI</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php">زِراعتي <span>AI</span></a>
    <nav aria-label="التنقل الرئيسي">
        <?php if (current_user()): ?>
            <a href="dashboard.php">لوحة التحكم</a>
            <?php if (current_user()['role'] === 'admin'): ?>
                <a href="admin_reports.php">تقارير المدير</a>
            <?php endif; ?>
            <?php if (current_user()['role'] === 'farmer'): ?>
                <a href="crops.php">محاصيلي والري</a>
                <a href="farmer_reports.php">تقارير مياه الري</a>
            <?php endif; ?>
            <form class="nav-form" action="logout.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="link-button" type="submit">تسجيل الخروج</button>
            </form>
        <?php else: ?>
            <a href="login.php">دخول</a>
            <a href="register.php">حساب جديد</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">