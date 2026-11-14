<?php
declare(strict_types=1);

$config = require __DIR__ . '/config/plant_images.php';
if (($config['upload_enabled'] ?? false) !== true) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('معالج رفع الصور غير مفعّل.');
}

header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('الطريقة غير مسموحة.');
}

$contentLength = $_SERVER['CONTENT_LENGTH'] ?? '';
if (is_string($contentLength) && ctype_digit($contentLength)
    && (int) $contentLength > $config['max_file_bytes'] + 65536) {
    http_response_code(413);
    exit('حجم الطلب أكبر من الحد المسموح.');
}

require_once __DIR__ . '/includes/bootstrap.php';
$user = require_role('farmer');
verify_csrf();

$cropId = filter_var($_POST['crop_id'] ?? null, FILTER_VALIDATE_INT);
if ($cropId === false || $cropId === null || $cropId < 1) {
    http_response_code(400);
    exit('رقم المحصول غير صالح.');
}
if (count($_FILES) !== 1 || !isset($_FILES['plant_image']) || !is_array($_FILES['plant_image'])) {
    http_response_code(400);
    exit('أرسل صورة واحدة فقط.');
}

require_once __DIR__ . '/includes/plant_image_upload_processor.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $result = (new PlantImageUploadProcessor())->storeForCrop(
        $pdo,
        $user,
        $cropId,
        $_FILES['plant_image'],
        $config,
        __DIR__
    );

    http_response_code(201);
    echo json_encode([
        'stored' => true,
        'image_id' => $result['image_id'],
        'mime_type' => 'image/png',
        'analysis' => $result['analysis'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PlantImageAccessDenied) {
    http_response_code(404);
    echo json_encode(['error' => 'المحصول غير موجود أو لا يتبع حسابك.'], JSON_UNESCAPED_UNICODE);
} catch (PlantImageBusy) {
    http_response_code(429);
    echo json_encode(['error' => 'توجد صورة أخرى قيد المعالجة؛ أعد المحاولة.'], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    error_log('Plant image processing failed; request details omitted.');
    http_response_code(503);
    echo json_encode(['error' => 'تعذرت معالجة الصورة حاليًا.'], JSON_UNESCAPED_UNICODE);
}