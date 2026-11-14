<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_role('admin');
$counts = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'crops' => (int) $pdo->query('SELECT COUNT(*) FROM crops')->fetchColumn(),
    'irrigation' => (int) $pdo->query('SELECT COUNT(*) FROM irrigation_logs')->fetchColumn(),
];
$totalWater = (string) $pdo->query('SELECT COALESCE(SUM(water_liters), 0) FROM irrigation_logs')->fetchColumn();
$pageTitle = 'لوحة المدير';
require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <p class="eyebrow">لوحة المدير</p>
    <h1>مرحبًا، <?= e($user['full_name']) ?></h1>
    <p>تعرض هذه اللوحة ملخصًا عامًا. إدارة المستخدمين والتقارير التفصيلية ستضاف لاحقًا.</p>
</section>
<section class="stats" aria-label="ملخص المنصة">
    <article class="card"><span>المستخدمون</span><strong><?= $counts['users'] ?></strong></article>
    <article class="card"><span>المحاصيل</span><strong><?= $counts['crops'] ?></strong></article>
    <article class="card"><span>سجلات الري</span><strong><?= $counts['irrigation'] ?></strong></article>
    <article class="card"><span>إجمالي المياه المسجلة (لتر)</span><strong><?= e($totalWater) ?></strong></article>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
