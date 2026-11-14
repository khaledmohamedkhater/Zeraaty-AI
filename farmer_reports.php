<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
$user = require_role('farmer');
require_once __DIR__ . '/includes/report_period.php';

$today = new DateTimeImmutable('today');
$defaultEnd = $today->format('Y-m-d');
$defaultStart = $today->sub(new DateInterval('P29D'))->format('Y-m-d');
$hasFilters = array_key_exists('start_date', $_GET) || array_key_exists('end_date', $_GET);
$startInput = $hasFilters ? ($_GET['start_date'] ?? null) : $defaultStart;
$endInput = $hasFilters ? ($_GET['end_date'] ?? null) : $defaultEnd;
$startValue = is_string($startInput) ? $startInput : '';
$endValue = is_string($endInput) ? $endInput : '';
$period = parse_irrigation_report_period($startInput, $endInput);
$errors = [];
$crops = [];
$summary = ['total_water' => '0.00', 'irrigation_count' => 0, 'average_water' => null];
$queryFailed = false;

if ($period === null) {
    $errors[] = 'التاريخ غير صالح، أو البداية بعد النهاية، أو الفترة أطول من 366 يومًا.';
} else {
    try {
        $summaryStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(i.water_liters), 0) AS total_water,
                    COUNT(i.id) AS irrigation_count,
                    AVG(i.water_liters) AS average_water
             FROM crops c
             LEFT JOIN irrigation_logs i
               ON i.crop_id = c.id
              AND i.irrigated_at >= :start_at
              AND i.irrigated_at < :end_exclusive
             WHERE c.user_id = :user_id'
        );
        $summaryStatement->execute([
            'start_at' => $period['start_at'],
            'end_exclusive' => $period['end_exclusive'],
            'user_id' => $user['id'],
        ]);
        $summary = $summaryStatement->fetch() ?: $summary;

        $cropsStatement = $pdo->prepare(
            'SELECT c.id, c.name, c.field_name, c.area_m2,
                    COALESCE(SUM(i.water_liters), 0) AS total_water,
                    COUNT(i.id) AS irrigation_count,
                    AVG(i.water_liters) AS average_water
             FROM crops c
             LEFT JOIN irrigation_logs i
               ON i.crop_id = c.id
              AND i.irrigated_at >= :start_at
              AND i.irrigated_at < :end_exclusive
             WHERE c.user_id = :user_id
             GROUP BY c.id, c.name, c.field_name, c.area_m2
             ORDER BY c.name, c.field_name, c.id'
        );
        $cropsStatement->execute([
            'start_at' => $period['start_at'],
            'end_exclusive' => $period['end_exclusive'],
            'user_id' => $user['id'],
        ]);
        $crops = $cropsStatement->fetchAll();
    } catch (Throwable $exception) {
        error_log('Zeraaty AI farmer water report query failed.');
        http_response_code(503);
        $queryFailed = true;
        $errors[] = 'تعذر تحميل التقرير حاليًا. حاول مرة أخرى لاحقًا.';
    }
}

$pageTitle = 'تقرير استهلاك مياه الري';
require __DIR__ . '/includes/header.php';
?>
<section class="page-heading">
    <p class="eyebrow">تقارير المزرعة</p>
    <h1>تقرير استهلاك مياه الري</h1>
    <p>ملخص كميات المياه المسجلة لمحاصيل حسابك خلال الفترة المختارة. الحد الأقصى للفترة 366 يومًا.</p>
</section>

<?php if ($errors): ?><div class="alert" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="card">
    <form method="get" action="farmer_reports.php" class="irrigation-form">
        <label>من تاريخ <input type="date" name="start_date" value="<?= e($startValue) ?>" required></label>
        <label>إلى تاريخ <input type="date" name="end_date" value="<?= e($endValue) ?>" required></label>
        <button class="button" type="submit">عرض التقرير</button>
    </form>
</section>

<?php if ($period !== null && !$queryFailed): ?>
<section class="stats" aria-label="مؤشرات استهلاك المياه">
    <article class="card"><span>إجمالي المياه</span><strong><?= e((string) $summary['total_water']) ?></strong><span>لتر</span></article>
    <article class="card"><span>عدد عمليات الري</span><strong><?= e((string) $summary['irrigation_count']) ?></strong></article>
    <article class="card"><span>متوسط المياه للعملية</span><strong><?= $summary['average_water'] === null ? '—' : e(number_format((float) $summary['average_water'], 2, '.', '')) ?></strong><span><?= $summary['average_water'] === null ? '' : 'لتر' ?></span></article>
</section>

<section class="card">
    <h2>تفاصيل المحاصيل</h2>
    <p class="muted">الفترة من <?= e($period['start_date']) ?> إلى <?= e($period['end_date']) ?> شاملة اليومين.</p>
    <?php if (!$crops): ?>
        <p class="muted">لا توجد محاصيل مسجلة في حسابك حتى الآن.</p>
    <?php else: ?>
        <?php if ((int) $summary['irrigation_count'] === 0): ?><p class="notice" role="status">لا توجد سجلات ري خلال الفترة المحددة؛ تظهر المحاصيل بإجمالي وعدد صفر.</p><?php endif; ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>المحصول</th><th>الحقل</th><th>المساحة (م²)</th><th>إجمالي المياه (لتر)</th><th>عمليات الري</th><th>متوسط العملية (لتر)</th></tr></thead>
                <tbody>
                <?php foreach ($crops as $crop): ?>
                    <?php $cropName = $crop['name'] === 'wheat' ? 'قمح' : ($crop['name'] === 'tomato' ? 'طماطم' : 'غير معروف'); ?>
                    <tr>
                        <td><?= e($cropName) ?></td>
                        <td><?= e((string) $crop['field_name']) ?></td>
                        <td><?= e((string) $crop['area_m2']) ?></td>
                        <td><?= e((string) $crop['total_water']) ?></td>
                        <td><?= e((string) $crop['irrigation_count']) ?></td>
                        <td><?= $crop['average_water'] === null ? '—' : e(number_format((float) $crop['average_water'], 2, '.', '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
