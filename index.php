<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}
$pageTitle = 'الرئيسية';
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <p class="eyebrow">منصتك لإدارة المزرعة</p>
    <h1>بيانات مزرعتك، في مكان واحد</h1>
    <p>سجّل محاصيل القمح والطماطم، وتابع الري واستهلاك المياه. هذه نسخة تأسيسية؛ تحليل صور النباتات غير متاح بعد.</p>
    <div class="actions">
        <a class="button" href="register.php">ابدأ بحساب مزارع</a>
        <a class="button button-light" href="login.php">تسجيل الدخول</a>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
