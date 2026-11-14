<?php
declare(strict_types=1);

$readLimit = static function (string $environmentName, int $default, int $hardMaximum): int {
    $value = getenv($environmentName);
    if ($value === false || $value === '') {
        return $default;
    }
    if (!ctype_digit($value)) {
        throw new RuntimeException('إعداد حدود الصور غير صالح.');
    }
    $limit = (int) $value;
    if ($limit < 1 || $limit > $hardMaximum) {
        throw new RuntimeException('إعداد حدود الصور خارج النطاق المسموح.');
    }
    return $limit;
};

return [
    // Must point to an existing, writable directory outside Apache's document root.
    'storage_dir' => (string) (getenv('ZERAATY_IMAGE_STORAGE_DIR') ?: ''),
    'upload_enabled' => getenv('ZERAATY_IMAGE_UPLOAD_ENABLED') === '1',
    'max_file_bytes' => $readLimit('ZERAATY_IMAGE_MAX_BYTES', 5 * 1024 * 1024, 20 * 1024 * 1024),
    'max_reencoded_bytes' => $readLimit('ZERAATY_IMAGE_MAX_REENCODED_BYTES', 10 * 1024 * 1024, 20 * 1024 * 1024),
    'max_processing_memory_bytes' => $readLimit('ZERAATY_IMAGE_MAX_MEMORY_BYTES', 128 * 1024 * 1024, 256 * 1024 * 1024),
    'max_width_px' => $readLimit('ZERAATY_IMAGE_MAX_WIDTH', 4096, 8192),
    'max_height_px' => $readLimit('ZERAATY_IMAGE_MAX_HEIGHT', 4096, 8192),
    'max_pixels' => $readLimit('ZERAATY_IMAGE_MAX_PIXELS', 4 * 1000 * 1000, 8 * 1000 * 1000),
    'allowed_mime_types' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ],
];