<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/irrigation_amount.php';
$user = require_role('farmer');
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_crop') {
        $name = $_POST['name'] ?? '';
        $fieldNameInput = $_POST['field_name'] ?? null;
        $fieldName = '';
        if (!is_string($fieldNameInput)) {
            $errors[] = 'اسم الحقل يجب أن يكون نصًا.';
        } else {
            $fieldName = trim($fieldNameInput);
            if ($fieldName === '' || mb_strlen($fieldName) > 150) $errors[] = 'اكتب اسمًا صحيحًا للحقل.';
        }
        $areaInput = $_POST['area_m2'] ?? null;
        $area = '';
        if (!is_string($areaInput)) {
            $errors[] = 'المساحة مطلوبة ويجب إدخالها كنص رقمي.';
        } else {
            $area = trim($areaInput);
        }
        $plantedOnInput = $_POST['planted_on'] ?? '';
        $plantedOn = '';
        if (!is_string($plantedOnInput)) {
            $errors[] = 'تاريخ الزراعة يجب أن يكون نصًا عند إرساله.';
        } else {
            $plantedOn = trim($plantedOnInput);
        }
        if (!in_array($name, ['wheat', 'tomato'], true)) $errors[] = 'اختر محصول القمح أو الطماطم.';
        $validAreaFormat = preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $area) === 1;
        $positiveArea = false;
        $withinAreaLimit = false;
        if ($validAreaFormat) {
            $areaParts = explode('.', $area, 2);
            $whole = ltrim($areaParts[0], '0');
            $whole = $whole === '' ? '0' : $whole;
            $fraction = str_pad($areaParts[1] ?? '', 2, '0');
            $maxWhole = '9999999999';
            $wholeComparison = strlen($whole) < strlen($maxWhole) ? -1
                : (strlen($whole) > strlen($maxWhole) ? 1 : strcmp($whole, $maxWhole));
            $positiveArea = $whole !== '0' || $fraction !== '00';
            $withinAreaLimit = $wholeComparison < 0
                || ($wholeComparison === 0 && strcmp($fraction, '99') <= 0);
        }
        if (!$validAreaFormat || !$positiveArea || !$withinAreaLimit) {
            $errors[] = 'المساحة يجب أن تكون أكبر من صفر، وبحد أقصى منزلتين عشريتين، وألا تتجاوز 9999999999.99 م².';
        }
        $plantDate = $plantedOn === '' ? null : DateTime::createFromFormat('!Y-m-d', $plantedOn);
        if ($plantedOn !== '' && (!$plantDate || $plantDate->format('Y-m-d') !== $plantedOn)) $errors[] = 'تاريخ الزراعة غير صحيح.';
        if (!$errors) {
            $statement = $pdo->prepare('INSERT INTO crops (user_id, name, field_name, area_m2, planted_on) VALUES (:user_id, :name, :field_name, :area_m2, :planted_on)');
            $statement->execute(['user_id' => $user['id'], 'name' => $name, 'field_name' => $fieldName, 'area_m2' => $area, 'planted_on' => $plantedOn === '' ? null : $plantedOn]);
            $success = 'تمت إضافة المحصول.';
        }
    } elseif ($action === 'add_irrigation') {
        $cropId = filter_input(INPUT_POST, 'crop_id', FILTER_VALIDATE_INT);
        $liters = $_POST['water_liters'] ?? '';
        if (is_string($liters)) $liters = trim($liters);
        $irrigatedAtInput = $_POST['irrigated_at'] ?? null;
        $irrigatedAt = '';
        $date = false;
        if (!is_string($irrigatedAtInput)) {
            $errors[] = 'تاريخ ووقت الري مطلوبان ويجب أن يكونا نصًا.';
        } else {
            $irrigatedAt = trim($irrigatedAtInput);
            $date = DateTime::createFromFormat('!Y-m-d\TH:i', $irrigatedAt);
        }
        if (!$cropId || $cropId < 1) $errors[] = 'المحصول المحدد غير صحيح.';
        if (!is_valid_irrigation_amount($liters)) $errors[] = 'كمية الري يجب أن تكون أكبر من صفر، وبحد أقصى 9999999999.99 لتر، وبمنزلتين عشريتين كحد أقصى.';
        if (!$date || $date->format('Y-m-d\TH:i') !== $irrigatedAt) $errors[] = 'تاريخ ووقت الري غير صحيح.';
        if (!$errors) {
            $statement = $pdo->prepare('INSERT INTO irrigation_logs (crop_id, irrigated_at, water_liters) SELECT id, :irrigated_at, :water_liters FROM crops WHERE id = :crop_id AND user_id = :user_id');
            $statement->execute(['irrigated_at' => $date->format('Y-m-d H:i:s'), 'water_liters' => $liters, 'crop_id' => $cropId, 'user_id' => $user['id']]);
            if ($statement->rowCount() === 1) $success = 'تم تسجيل الري.';
            else $errors[] = 'المحصول غير موجود أو لا يتبع حسابك.';
        }
    } else {
        $errors[] = 'نوع الطلب غير معروف.';
    }
}

if ($success !== '') {
    $_SESSION['flash_success'] = $success;
    header('Location: crops.php');
    exit;
}
$success = (string) ($_SESSION['flash_success'] ?? '');
unset($_SESSION['flash_success']);

$statement = $pdo->prepare('SELECT c.id, c.name, c.field_name, c.area_m2, c.planted_on, COALESCE(SUM(i.water_liters), 0) AS total_water, COUNT(i.id) AS irrigation_count, (SELECT MAX(i2.irrigated_at) FROM irrigation_logs i2 WHERE i2.crop_id = c.id) AS last_irrigation, (SELECT COALESCE(SUM(i3.water_liters), 0) FROM irrigation_logs i3 WHERE i3.crop_id = c.id AND i3.irrigated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS water_last_7_days FROM crops c LEFT JOIN irrigation_logs i ON i.crop_id = c.id WHERE c.user_id = :user_id GROUP BY c.id ORDER BY c.created_at DESC');
$statement->execute(['user_id' => $user['id']]);
$crops = $statement->fetchAll();
$latestAnalyses = [];
$analysisStatement = $pdo->prepare(
    'SELECT a.crop_id, a.crop_suggestions, a.disease_suggestions, a.created_at
     FROM plant_image_analyses a
     INNER JOIN crops c ON c.id = a.crop_id
     WHERE c.user_id = :user_id
     ORDER BY a.created_at DESC, a.id DESC'
);
$analysisStatement->execute(['user_id' => $user['id']]);

foreach ($analysisStatement->fetchAll(PDO::FETCH_ASSOC) as $analysisRow) {
    $analysisCropId = (int) $analysisRow['crop_id'];

    if (isset($latestAnalyses[$analysisCropId])) {
        continue;
    }

    try {
        $cropSuggestions = json_decode(
            (string) $analysisRow['crop_suggestions'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $diseaseSuggestions = json_decode(
            (string) $analysisRow['disease_suggestions'],
            true,
            32,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($cropSuggestions) || !is_array($diseaseSuggestions)) {
            continue;
        }

        $latestAnalyses[$analysisCropId] = [
            'crop_suggestions' => $cropSuggestions,
            'disease_suggestions' => $diseaseSuggestions,
            'created_at' => (string) $analysisRow['created_at'],
        ];
    } catch (JsonException) {
        error_log('Invalid stored plant image analysis JSON.');
    }
}
$pageTitle = 'محاصيلي والري';
require __DIR__ . '/includes/header.php';
?>
<section class="page-heading"><p class="eyebrow">إدارة المزرعة</p><h1>محاصيلي والري</h1><p>كل الاستعلامات هنا مقيدة بحساب المزارع الحالي.</p></section>
<?php if ($errors): ?><div class="alert" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><p class="success" role="status"><?= e($success) ?></p><?php endif; ?>
<section class="card form-card crop-form"><h2>إضافة محصول</h2><form method="post" action="crops.php">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_crop">
    <label>نوع المحصول<select name="name" required><option value="">اختر</option><option value="wheat">قمح</option><option value="tomato">طماطم</option></select></label>
    <label>اسم الحقل<input name="field_name" maxlength="150" required></label>
    <label>المساحة بالمتر المربع<input type="number" name="area_m2" min="0.01" step="0.01" required></label>
    <label>تاريخ الزراعة (اختياري)<input type="date" name="planted_on"></label>
    <button class="button" type="submit">حفظ المحصول</button>
</form></section>
<section class="crop-list">
<?php foreach ($crops as $crop): ?>
    <article class="card crop-card">
        <h2><?= $crop['name'] === 'wheat' ? 'قمح' : 'طماطم' ?> — <?= e($crop['field_name']) ?></h2>
        <p>المساحة: <?= e((string) $crop['area_m2']) ?> م² · تاريخ الزراعة: <?= e($crop['planted_on'] ?? 'غير محدد') ?></p>
        <p>إجمالي المياه: <strong><?= e((string) $crop['total_water']) ?> لتر</strong> · عدد مرات الري: <?= e((string) $crop['irrigation_count']) ?></p>
        <section class="irrigation-insights">
            <h3>مؤشرات الري الذكي</h3>
            <p>آخر موعد للري:
                <strong><?= !empty($crop['last_irrigation']) ? e(date('Y-m-d H:i', strtotime($crop['last_irrigation']))) : 'لم يتم تسجيل ري بعد' ?></strong>
            </p>
            <p>استهلاك المياه خلال آخر 7 أيام:
                <strong><?= e((string) ($crop['water_last_7_days'] ?? '0.00')) ?> لتر</strong>
            </p>
        
        <?php if (empty($crop['last_irrigation'])): ?>
            <p class="irrigation-alert">تنبيه متابعة: لا يوجد سجل ري لهذا المحصول حتى الآن. سجّل عمليات الري لمتابعة الاستهلاك.</p>
        <?php elseif (strtotime((string) $crop['last_irrigation']) < strtotime('-7 days')): ?>
            <p class="irrigation-alert">مرّ أكثر من 7 أيام منذ آخر تسجيل. راجع حالة المحصول وسجل الري قبل اتخاذ قرار الري.</p>
        <?php else: ?>
            <p class="irrigation-status">يوجد سجل ري خلال آخر 7 أيام. استمر في متابعة حالة المحصول.</p>
        <?php endif; ?>
        <div class="irrigation-recommendation">
            <h4>التوصية الذكية</h4>
            <?php if (empty($crop['last_irrigation'])): ?>
                <p>ابدأ بتسجيل عمليات الري حتى يتمكن النظام من متابعة استهلاك المياه.</p>
            <?php elseif (strtotime((string) $crop['last_irrigation']) < strtotime('-7 days')): ?>
                <p>راجع حالة التربة والمحصول والطقس المحلي قبل اتخاذ قرار الري.</p>
            <?php else: ?>
                <p>استمر في متابعة استهلاك المياه وسجّل كل عملية ري جديدة.</p>
            <?php endif; ?>
            <p>ملاحظة: تحديد كمية الري المناسبة يحتاج بيانات التربة والطقس ومرحلة نمو المحصول.</p>
        </div>
</section>
        <form method="post" action="crops.php" class="irrigation-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_irrigation"><input type="hidden" name="crop_id" value="<?= e((string) $crop['id']) ?>">
            <label>كمية المياه باللتر<input type="number" name="water_liters" min="0.01" step="0.01" required></label>
            <label>وقت الري<input type="datetime-local" name="irrigated_at" required></label>
            <button class="button" type="submit">تسجيل الري</button>
        </form>
        <section class="plant-analysis-section">
            <h3>تحليل صورة المحصول</h3>

            <?php if (isset($latestAnalyses[(int) $crop['id']])): ?>
                <?php $savedAnalysis = $latestAnalyses[(int) $crop['id']]; ?>

                <p>
                    آخر تحليل:
                    <?= e($savedAnalysis['created_at']) ?>
                </p>

                <h4>اقتراحات المحصول</h4>
                <?php if ($savedAnalysis['crop_suggestions']): ?>
                    <ul>
                        <?php foreach ($savedAnalysis['crop_suggestions'] as $suggestion): ?>
                            <?php if (is_array($suggestion) && isset($suggestion['name'], $suggestion['probability'])): ?>
                                <li>
                                    <?= e((string) $suggestion['name']) ?>
                                    — <?= e((string) round((float) $suggestion['probability'] * 100, 1)) ?>%
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>لا توجد اقتراحات للمحصول.</p>
                <?php endif; ?>

                <?php if (!$savedAnalysis['disease_suggestions'] && !$savedAnalysis['crop_suggestions']): ?>
                    <p class="alert" role="status">تنبيه: لم يتم التأكد من توافق الصورة مع نوع المحصول المسجل. لذلك لم نعرض اقتراحات الأمراض. يُرجى التأكد من تصوير المحصول الصحيح.</p>
                <?php endif; ?>

                <h4>اقتراحات الأمراض</h4>
                <?php if (!$savedAnalysis['crop_suggestions']): ?>
                    <p class="alert" role="status">تنبيه: لم يتم التأكد من توافق الصورة مع نوع المحصول المسجل، لذلك تم إخفاء اقتراحات الأمراض لهذا التحليل القديم.</p>
                <?php elseif ($savedAnalysis['disease_suggestions']): ?>
                    <ul>
                        <?php foreach ($savedAnalysis['disease_suggestions'] as $suggestion): ?>
                            <?php if (is_array($suggestion) && isset($suggestion['name'], $suggestion['probability'])): ?>
                                <li>
                                    <?= e((string) $suggestion['name']) ?>
                                    — <?= e((string) round((float) $suggestion['probability'] * 100, 1)) ?>%
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>لا توجد اقتراحات للأمراض.</p>
                <?php endif; ?>
            <?php else: ?>
                <p>لم يتم تحليل صورة لهذا المحصول حتى الآن.</p>
            <?php endif; ?>

            <form method="post"
                  action="plant_image_upload.php"
                  enctype="multipart/form-data"
                  data-plant-upload>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="crop_id" value="<?= e((string) $crop['id']) ?>">

                <label>
                    اختر صورة واضحة للمحصول
                    <input type="file"
                           name="plant_image"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           required>
                </label>

                <button class="button" type="submit">تحليل الصورة</button>
                <p data-upload-status role="status" aria-live="polite"></p>
            </form>
        </section>    </article>
<?php endforeach; ?>
</section>
<script>
document.querySelectorAll('[data-plant-upload]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('[data-upload-status]');
        const fileInput = form.querySelector('input[type="file"]');

        if (!fileInput || !fileInput.files || fileInput.files.length !== 1) {
            status.textContent = 'اختر صورة واحدة أولاً.';
            return;
        }

        button.disabled = true;
        status.textContent = 'جارٍ إرسال الصورة ومعالجتها...';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            const result = await response.json();

            if (!response.ok || result.stored !== true || !result.analysis) {
                throw new Error(result.error || 'تعذر إتمام التحليل.');
            }

            const analysis = result.analysis;
            const lines = [];

            const addSuggestions = (heading, suggestions) => {
                lines.push(heading);

                if (!Array.isArray(suggestions) || suggestions.length === 0) {
                    lines.push('لا توجد اقتراحات.');
                    return;
                }

                suggestions.forEach((item) => {
                    if (item && typeof item.name === 'string' &&
                        Number.isFinite(Number(item.probability))) {
                        lines.push(
                            item.name + ' — ' +
                            (Number(item.probability) * 100).toFixed(1) + '%'
                        );
                    }
                });
            };

            addSuggestions('اقتراحات المحصول:', analysis.crop_suggestions);

            if (analysis.disease_suggestions_suppressed === true) {
                lines.push('تنبيه: لم يتم التأكد من توافق الصورة مع نوع المحصول المسجل، لذلك لم نعرض اقتراحات الأمراض. يُرجى التأكد من تصوير المحصول الصحيح.');
            } else {
                addSuggestions('اقتراحات الأمراض:', analysis.disease_suggestions);
            }

            status.textContent = 'تم التحليل. ' + lines.join(' | ');
            form.reset();
        } catch (error) {
            status.textContent = error instanceof Error
                ? error.message
                : 'حدث خطأ أثناء التحليل.';
        } finally {
            button.disabled = false;
        }
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
