<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_role('farmer');

$statsStatement = $pdo->prepare(
    'SELECT
        COUNT(DISTINCT c.id) AS crop_count,
        COALESCE(SUM(c.area_m2), 0) AS total_area
     FROM crops c
     WHERE c.user_id = :user_id'
);
$statsStatement->execute(['user_id' => $user['id']]);
$stats = $statsStatement->fetch();

$waterStatement = $pdo->prepare(
    'SELECT
        COALESCE(SUM(i.water_liters), 0) AS total_water,
        COUNT(i.id) AS irrigation_count,
        COALESCE(SUM(
            CASE
                WHEN i.irrigated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                THEN i.water_liters
                ELSE 0
            END
        ), 0) AS water_last_7_days,
        MAX(i.irrigated_at) AS last_irrigation
     FROM crops c
     LEFT JOIN irrigation_logs i ON i.crop_id = c.id
     WHERE c.user_id = :user_id'
);
$waterStatement->execute(['user_id' => $user['id']]);
$water = $waterStatement->fetch();

$weeklyStatement = $pdo->prepare(
    'SELECT
        DATE(i.irrigated_at) AS irrigation_day,
        COALESCE(SUM(i.water_liters), 0) AS daily_water
     FROM crops c
     INNER JOIN irrigation_logs i ON i.crop_id = c.id
     WHERE c.user_id = :user_id
       AND DATE(i.irrigated_at) >= :start_date
       AND DATE(i.irrigated_at) <= :end_date
     GROUP BY DATE(i.irrigated_at)
     ORDER BY irrigation_day ASC'
);

$today = new DateTimeImmutable('today');
$startDay = $today->modify('-6 days');

$weeklyStatement->execute([
    'user_id' => $user['id'],
    'start_date' => $startDay->format('Y-m-d'),
    'end_date' => $today->format('Y-m-d'),
]);

$weeklyRows = $weeklyStatement->fetchAll();
$waterByDay = [];

foreach ($weeklyRows as $row) {
    $waterByDay[$row['irrigation_day']] = (float) $row['daily_water'];
}

$arabicDays = [
    1 => 'الاثنين',
    2 => 'الثلاثاء',
    3 => 'الأربعاء',
    4 => 'الخميس',
    5 => 'الجمعة',
    6 => 'السبت',
    7 => 'الأحد',
];

$weeklyChart = [];

for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
    $day = $startDay->modify('+' . $dayOffset . ' days');
    $dateKey = $day->format('Y-m-d');

    $weeklyChart[] = [
        'label' => $arabicDays[(int) $day->format('N')],
        'water' => $waterByDay[$dateKey] ?? 0,
    ];
}

$weeklyMax = max(
    1,
    max(array_column($weeklyChart, 'water'))
);
$cropsStatement = $pdo->prepare(
    'SELECT
        c.id,
        c.name,
        c.field_name,
        c.area_m2,
        c.planted_on,
        COALESCE(w.total_water, 0) AS total_water,
        w.last_irrigation
     FROM crops c
     LEFT JOIN (
        SELECT
            crop_id,
            SUM(water_liters) AS total_water,
            MAX(irrigated_at) AS last_irrigation
        FROM irrigation_logs
        GROUP BY crop_id
     ) w ON w.crop_id = c.id
     WHERE c.user_id = :user_id
     ORDER BY c.created_at DESC'
);
$cropsStatement->execute(['user_id' => $user['id']]);
$crops = $cropsStatement->fetchAll();

$pageTitle = 'لوحة المزارع';
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/farmer-dashboard.css">

<div class="farmer-dashboard">

    <section class="dashboard-welcome">
        <div>
            <p class="eyebrow">لوحة المزارع الذكية</p>
            <h1>مرحبًا، <?= e($user['full_name']) ?> 🌱</h1>
            <p>تابع محاصيلك واستهلاك المياه وسجلات الري من مكان واحد.</p>

            <div class="dashboard-actions">
                <a class="button" href="crops.php">إدارة المحاصيل والري</a>
                <a class="button button-light" href="farmer_reports.php">تقارير المياه</a>
            </div>
        </div>
        <div class="welcome-mark" aria-hidden="true">🌾</div>
    </section>

    <section class="dashboard-stats" aria-label="إحصائيات المزرعة">

        <article class="stat-card">
            <span class="stat-label">عدد المحاصيل</span>
            <strong class="stat-value"><?= e((string) $stats['crop_count']) ?></strong>
            <span class="stat-note">المحاصيل المسجلة في حسابك</span>
        </article>

        <article class="stat-card">
            <span class="stat-label">إجمالي المساحة</span>
            <strong class="stat-value"><?= e(number_format((float) $stats['total_area'], 2)) ?></strong>
            <span class="stat-note">متر مربع</span>
        </article>

        <article class="stat-card">
            <span class="stat-label">إجمالي المياه</span>
            <strong class="stat-value"><?= e(number_format((float) $water['total_water'], 2)) ?></strong>
            <span class="stat-note">لتر مسجل في سجلات الري</span>
        </article>

        <article class="stat-card">
            <span class="stat-label">مرات الري</span>
            <strong class="stat-value"><?= e((string) $water['irrigation_count']) ?></strong>
            <span class="stat-note">إجمالي عمليات الري المسجلة</span>
        </article>

    </section>

    <section class="dashboard-grid">

        <div class="dashboard-panel">
            <div class="panel-heading">
                <h2>محاصيلك</h2>
                <span class="stat-note"><?= e((string) count($crops)) ?> محصول</span>
            </div>

            <?php if (!$crops): ?>
                <div class="dashboard-empty">
                    <div style="font-size:38px" aria-hidden="true">🌱</div>
                    <strong>لم تسجل أي محاصيل بعد</strong>
                    <p>ابدأ بإضافة أول محصول لمتابعة المساحة والري واستهلاك المياه.</p>
                    <a class="button" href="crops.php">إضافة أول محصول</a>
                </div>
            <?php else: ?>
                <div class="dashboard-crop-list">
                    <?php foreach ($crops as $crop): ?>
                        <article class="dashboard-crop">
                            <div style="display:flex;align-items:center;gap:12px">
                                <span class="crop-symbol" aria-hidden="true">
                                    <?= $crop['name'] === 'wheat' ? '🌾' : '🍅' ?>
                                </span>
                                <div class="crop-info">
                                    <strong><?= $crop['name'] === 'wheat' ? 'قمح' : 'طماطم' ?></strong>
                                    <span><?= e($crop['field_name']) ?> · <?= e(number_format((float) $crop['area_m2'], 2)) ?> م²</span>
                                </div>
                            </div>
                            <div class="crop-info" style="text-align:left">
                                <strong><?= e(number_format((float) $crop['total_water'], 2)) ?> لتر</strong>
                                <span>
                                    <?= $crop['last_irrigation']
                                        ? 'آخر ري: ' . e($crop['last_irrigation'])
                                        : 'لم يسجل ري بعد' ?>
                                </span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="dashboard-actions">
                <a class="button button-light" href="crops.php">عرض كل تفاصيل المحاصيل والري</a>
            </div>
        </div>

        <aside class="dashboard-panel">
            <div class="panel-heading">
                <h2>متابعة المياه</h2>
            </div>

            <span class="stat-label">استهلاك آخر 7 أيام</span>
            <strong class="stat-value">
                <?= e(number_format((float) $water['water_last_7_days'], 2)) ?>
            </strong>
            <span class="stat-note">لتر خلال آخر سبعة أيام</span>

            <div class="dashboard-alert">
                <strong>آخر عملية ري</strong>
                <p>
                    <?= $water['last_irrigation']
                        ? e($water['last_irrigation'])
                        : 'لا توجد عمليات ري مسجلة حتى الآن.' ?>
                </p>
            </div>

            <div class="dashboard-alert">
                <strong>سجلات الري</strong>
                <p>تم تسجيل <?= e((string) $water['irrigation_count']) ?> عملية ري في محاصيل حسابك.</p>
            </div>

            <a class="button" href="farmer_reports.php" style="margin-top:18px">
                عرض تقارير المياه
            </a>
        </aside>

    </section>

    <section class="dashboard-water-chart">
    <div class="panel-heading">
        <div>
            <h2>استهلاك المياه الأسبوعي</h2>
            <p>آخر 7 أيام — بيانات فعلية من سجلات الري</p>
        </div>
        <span class="chart-badge">لتر</span>
    </div>

    <?php if (array_sum(array_column($weeklyChart, 'water')) <= 0): ?>
        <p class="chart-empty-message">لا توجد عمليات ري مسجلة خلال هذا الأسبوع.</p>
    <?php endif; ?>

    <div class="real-water-chart">
        <?php foreach ($weeklyChart as $chartDay): ?>
            <?php
                $barHeight = $chartDay['water'] > 0
                    ? max(5, ($chartDay['water'] / $weeklyMax) * 100)
                    : 5;
            ?>
            <div class="real-chart-column">
                <strong><?= e(number_format($chartDay['water'], 0)) ?></strong>
                <div class="real-chart-track">
                    <div
                        class="real-chart-bar"
                        style="height: <?= e((string) $barHeight) ?>%"
                        title="<?= e(number_format($chartDay['water'], 2)) ?> لتر"
                    ></div>
                </div>
                <span><?= e($chartDay['label']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<section class="dashboard-analysis">
        <strong>تحليل صور النباتات</strong>
        <p>يمكنك متابعة حالة تحليل الصور من صفحة المحاصيل. لا نعرض نتائج تشخيص غير متاحة أو غير مؤكدة.</p>
        <a href="crops.php">الانتقال إلى المحاصيل والري</a>
    </section>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>




