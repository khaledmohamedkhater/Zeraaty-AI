<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_role('admin');

$report = [
    'farmers' => 0,
    'crops' => 0,
    'irrigation_count' => 0,
    'total_water' => '0.00',
];
$queryFailed = false;

try {
    $report['farmers'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'farmer'"
    )->fetchColumn();
    $report['crops'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM crops'
    )->fetchColumn();

    $irrigationSummary = $pdo->query(
        'SELECT COUNT(*) AS irrigation_count,
                CAST(COALESCE(SUM(water_liters), 0) AS DECIMAL(65,2)) AS total_water
         FROM irrigation_logs'
    )->fetch();
    if (!is_array($irrigationSummary)) {
        throw new RuntimeException('Admin report aggregate query returned no row.');
    }

    $report['irrigation_count'] = (int) $irrigationSummary['irrigation_count'];
    $report['total_water'] = (string) $irrigationSummary['total_water'];
} catch (Throwable $exception) {
    error_log('Zeraaty AI admin report query failed.');
    http_response_code(503);
    $queryFailed = true;
}

$pageTitle = 'تقارير المدير';
require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <p class="eyebrow">تقارير المنصة</p>
    <h1>ملخص تقارير المدير</h1>
    <p>إحصاءات إجمالية للمزارعين والمحاصيل وسجلات الري المسجلة في المنصة.</p>
</section>

<?php if ($queryFailed): ?>
    <div class="alert" role="alert">تعذر تحميل التقرير حاليًا. حاول مرة أخرى لاحقًا.</div>
<?php else: ?>
    <section class="stats" aria-label="إحصاءات المنصة">
        <article class="card"><span>المزارعون</span><strong><?= e((string) $report['farmers']) ?></strong></article>
        <article class="card"><span>المحاصيل</span><strong><?= e((string) $report['crops']) ?></strong></article>
        <article class="card"><span>عمليات الري</span><strong><?= e((string) $report['irrigation_count']) ?></strong></article>
        <article class="card"><span>إجمالي المياه المسجلة (لتر)</span><strong><?= e($report['total_water']) ?></strong></article>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
