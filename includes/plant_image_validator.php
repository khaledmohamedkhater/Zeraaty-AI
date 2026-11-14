<?php
declare(strict_types=1);

final class ValidatedPlantImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height
    ) {
    }
}

final class PlantImageValidator
{
    private array $limits;
    private array $supportedInputTypes;

    public function __construct(?array $config = null)
    {
        $this->limits = $config ?? require __DIR__ . '/../config/plant_images.php';
        foreach (['max_file_bytes', 'max_reencoded_bytes', 'max_processing_memory_bytes', 'max_width_px', 'max_height_px', 'max_pixels'] as $key) {
            if (!isset($this->limits[$key]) || !is_int($this->limits[$key]) || $this->limits[$key] < 1) {
                throw new RuntimeException('إعداد حدود الصور غير مكتمل.');
            }
        }
        if (empty($this->limits['allowed_mime_types']) || !is_array($this->limits['allowed_mime_types'])) {
            throw new RuntimeException('قائمة صيغ الصور المسموحة غير صالحة.');
        }
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            throw new RuntimeException('دعم فك الصور وإعادة ترميزها غير متاح في PHP.');
        }
        if (!class_exists(finfo::class) || !function_exists('getimagesizefromstring')) {
            throw new RuntimeException('فاحص الصور المطلوب غير متاح في PHP.');
        }

        $gd = gd_info();
        $capabilities = [
            'image/jpeg' => ['type' => IMAGETYPE_JPEG, 'gd' => 'JPEG Support'],
            'image/png' => ['type' => IMAGETYPE_PNG, 'gd' => 'PNG Support'],
            'image/gif' => ['type' => IMAGETYPE_GIF, 'gd' => 'GIF Read Support'],
            'image/webp' => ['type' => IMAGETYPE_WEBP, 'gd' => 'WebP Support'],
        ];
        $this->supportedInputTypes = [];
        foreach ($this->limits['allowed_mime_types'] as $mime => $extension) {
            if (isset($capabilities[$mime]) && !empty($gd[$capabilities[$mime]['gd']])) {
                $this->supportedInputTypes[$mime] = $capabilities[$mime]['type'];
            }
        }
        if ($this->supportedInputTypes === []) {
            throw new RuntimeException('لا توجد صيغة صورة مفعّلة للقراءة في GD.');
        }
    }

    /**
     * Validate, fully decode, and re-encode a PHP upload as a metadata-free PNG.
     * The original temporary upload is removed on success and failure.
     * Set requireHttpUpload=false only for isolated local test fixtures.
     */
    public function validateUpload(array $upload, bool $requireHttpUpload = true): ValidatedPlantImage
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadErrorMessage(is_int($error) ? $error : -1));
        }

        $temporaryPath = $upload['tmp_name'] ?? null;
        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_file($temporaryPath) || is_link($temporaryPath)) {
            throw new InvalidArgumentException('ملف الصورة المؤقت غير صالح.');
        }
        if ($requireHttpUpload && !is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('مصدر الصورة غير موثوق.');
        }
        if (!$requireHttpUpload) {
            $realPath = realpath($temporaryPath);
            $testRoot = rtrim((string) realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'zeraaty_image_intake_';
            if (PHP_SAPI !== 'cli' || $realPath === false || !str_starts_with($realPath, $testRoot)) {
                throw new InvalidArgumentException('تجاوز فحص الرفع متاح لملفات الاختبار المحلية فقط.');
            }
        }

        try {
            return $this->decodeAndReencode($temporaryPath);
        } finally {
            // For HTTP requests the path was verified as an actual PHP upload above.
            // Test mode is reserved for files created under the test harness temp folder.
            if (is_file($temporaryPath) && !@unlink($temporaryPath)) {
                throw new RuntimeException('تعذر حذف الملف الأصلي المؤقت بعد المعالجة.');
            }
        }
    }

    private function decodeAndReencode(string $temporaryPath): ValidatedPlantImage
    {
        clearstatcache(true, $temporaryPath);
        $actualSize = filesize($temporaryPath);
        if ($actualSize === false || $actualSize === 0) {
            throw new InvalidArgumentException('ملف الصورة فارغ أو غير قابل للقراءة.');
        }
        if ($actualSize > $this->limits['max_file_bytes']) {
            throw new InvalidArgumentException('حجم الصورة أكبر من الحد المسموح.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $imageInfo = @getimagesize($temporaryPath);
        if (!is_string($mimeType) || !isset($this->supportedInputTypes[$mimeType])
            || !is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1], $imageInfo[2], $imageInfo['mime'])
            || $imageInfo['mime'] !== $mimeType || $imageInfo[2] !== $this->supportedInputTypes[$mimeType]) {
            throw new InvalidArgumentException('محتوى الملف ليس صورة سليمة بصيغة مدعومة.');
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        $this->assertDimensions($width, $height);
        $this->assertMemoryBudget($width, $height, (int) $actualSize);

        $handle = @fopen($temporaryPath, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('تعذر قراءة محتوى الصورة كاملًا.');
        }
        $closed = false;
        try {
            $bytes = stream_get_contents($handle, $this->limits['max_file_bytes'] + 1);
        } finally {
            $closed = fclose($handle);
        }
        clearstatcache(true, $temporaryPath);
        $sizeAfterRead = filesize($temporaryPath);
        if (!$closed || $bytes === false || $bytes === '' || strlen($bytes) > $this->limits['max_file_bytes']
            || $sizeAfterRead === false || strlen($bytes) !== $actualSize || $sizeAfterRead !== $actualSize) {
            throw new InvalidArgumentException('تعذر قراءة محتوى الصورة كاملًا أو تجاوز حجمها الحد المسموح.');
        }

        $bufferMimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $bufferInfo = @getimagesizefromstring($bytes);
        if ($bufferMimeType !== $mimeType || !is_array($bufferInfo)
            || ($bufferInfo['mime'] ?? null) !== $mimeType
            || ($bufferInfo[2] ?? null) !== $this->supportedInputTypes[$mimeType]
            || (int) ($bufferInfo[0] ?? 0) !== $width || (int) ($bufferInfo[1] ?? 0) !== $height) {
            throw new InvalidArgumentException('تغير محتوى الصورة أثناء قراءتها أو لم يطابق بياناتها.');
        }

        $decoded = $this->decodeImage($bytes);
        try {
            $decodedWidth = imagesx($decoded);
            $decodedHeight = imagesy($decoded);
            if ($decodedWidth !== $width || $decodedHeight !== $height) {
                throw new InvalidArgumentException('أبعاد الصورة المفكوكة لا تطابق بياناتها.');
            }
            $pngBytes = $this->encodePng($decoded);
        } finally {
            imagedestroy($decoded);
        }

        if (strlen($pngBytes) > $this->limits['max_reencoded_bytes']) {
            throw new InvalidArgumentException('حجم الصورة بعد تجهيزها أكبر من الحد المسموح.');
        }
        $outputMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($pngBytes);
        $outputInfo = @getimagesizefromstring($pngBytes);
        if ($outputMime !== 'image/png' || !is_array($outputInfo)
            || ($outputInfo['mime'] ?? null) !== 'image/png'
            || (int) ($outputInfo[0] ?? 0) !== $width || (int) ($outputInfo[1] ?? 0) !== $height) {
            throw new RuntimeException('تعذر التحقق من الصورة بعد إعادة ترميزها.');
        }
        $reopened = $this->decodeImage($pngBytes);
        imagedestroy($reopened);

        return new ValidatedPlantImage($pngBytes, 'image/png', $width, $height);
    }

    private function assertDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1
            || $width > $this->limits['max_width_px']
            || $height > $this->limits['max_height_px']
            || $width > intdiv($this->limits['max_pixels'], $height)) {
            throw new InvalidArgumentException('أبعاد الصورة أو عدد بكسلاتها أكبر من الحد المسموح.');
        }
    }

    private function assertMemoryBudget(int $width, int $height, int $sourceBytes): void
    {
        $pixels = $width * $height;
        // Conservative allowance: decoded pixels, two source copies, two output buffers, and fixed GD overhead.
        $estimatedBytes = ($pixels * 8) + ($sourceBytes * 2)
            + ($this->limits['max_reencoded_bytes'] * 2) + (16 * 1024 * 1024);
        if ($estimatedBytes > $this->limits['max_processing_memory_bytes']) {
            throw new InvalidArgumentException('الصورة تحتاج ذاكرة معالجة أكبر من الحد المسموح.');
        }

        $memoryLimit = self::memoryLimitBytes((string) ini_get('memory_limit'));
        if ($memoryLimit !== null && $estimatedBytes > max(0, $memoryLimit - memory_get_usage(true))) {
            throw new InvalidArgumentException('الذاكرة المتاحة لا تكفي لمعالجة الصورة بأمان.');
        }
    }

    private static function memoryLimitBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '-1') {
            return null;
        }
        if (!preg_match('/\\A([0-9]+)\\s*([KMG]?)\\z/i', $value, $matches)) {
            throw new RuntimeException('تعذر قراءة حد ذاكرة PHP.');
        }
        $number = (int) $matches[1];
        $factor = match (strtolower($matches[2])) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };
        return $number * $factor;
    }

    private function decodeImage(string $bytes): GdImage
    {
        set_error_handler(static fn (): bool => true, E_WARNING | E_NOTICE);
        try {
            $image = @imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException('الصورة تالفة أو تعذر فك ترميزها.');
        }
        return $image;
    }

    private function encodePng(GdImage $image): string
    {
        $baseLevel = ob_get_level();
        if (!ob_start()) {
            throw new RuntimeException('تعذر بدء تجهيز الصورة.');
        }
        try {
            $encoded = @imagepng($image, null, 6, PNG_ALL_FILTERS);
            $bytes = ob_get_contents();
        } finally {
            while (ob_get_level() > $baseLevel) {
                if (!@ob_end_clean()) {
                    break;
                }
            }
        }
        if (!$encoded || !is_string($bytes) || $bytes === '') {
            throw new RuntimeException('تعذرت إعادة ترميز الصورة بصيغة آمنة.');
        }
        return $bytes;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم الصورة أكبر من الحد المسموح في إعدادات الخادم.',
            UPLOAD_ERR_PARTIAL => 'وصل جزء فقط من الصورة؛ أعد المحاولة.',
            UPLOAD_ERR_NO_FILE => 'اختر صورة أولًا.',
            UPLOAD_ERR_NO_TMP_DIR => 'مجلد الرفع المؤقت غير مهيأ في PHP.',
            UPLOAD_ERR_CANT_WRITE => 'تعذر حفظ الصورة المؤقتة على الخادم.',
            UPLOAD_ERR_EXTENSION => 'أوقف الخادم استقبال الصورة.',
            default => 'تعذر استقبال ملف الصورة.',
        };
    }
}