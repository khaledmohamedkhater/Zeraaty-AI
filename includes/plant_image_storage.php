<?php
declare(strict_types=1);

require_once __DIR__ . '/plant_image_validator.php';
require_once __DIR__ . '/plant_image_guard.php';
require_once __DIR__ . '/plant_image_storage_writer.php';

final class PlantImageStorage
{
    private string $directory;
    private int $maxBytes;
    private int $maxWidth;
    private int $maxHeight;
    private int $maxPixels;
    private PlantImageStorageWriter $writer;

    public function __construct(array $config, string $webRoot, ?PlantImageStorageWriter $writer = null)
    {
        $this->writer = $writer ?? new NativePlantImageStorageWriter();
        $configuredDirectory = $config['storage_dir'] ?? '';
        if (!is_string($configuredDirectory) || $configuredDirectory === '' || $webRoot === '') {
            throw new RuntimeException('مسار التخزين المعتمد أو مسار الويب غير مضبوط.');
        }
        foreach (['max_reencoded_bytes', 'max_width_px', 'max_height_px', 'max_pixels'] as $key) {
            if (!isset($config[$key]) || !is_int($config[$key]) || $config[$key] < 1) {
                throw new RuntimeException('حدود التخزين غير مكتملة.');
            }
        }
        if (!is_dir($configuredDirectory) || is_link($configuredDirectory)) {
            throw new RuntimeException('مجلد التخزين المعتمد يجب أن يكون موجودًا وغير رمزي.');
        }
        $realDirectory = realpath($configuredDirectory);
        $realWebRoot = realpath($webRoot);
        if ($realDirectory === false || $realWebRoot === false || !is_dir($realWebRoot)) {
            throw new RuntimeException('تعذر التحقق من مساري التخزين والويب.');
        }

        $normalizedDirectory = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $realDirectory), DIRECTORY_SEPARATOR);
        $normalizedWebRoot = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $realWebRoot), DIRECTORY_SEPARATOR);
        $directoryLower = strtolower($normalizedDirectory);
        $webRootLower = strtolower($normalizedWebRoot);
        if ($directoryLower === $webRootLower
            || str_starts_with($directoryLower, $webRootLower . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('يجب وضع الصور خارج مجلد الويب العام.');
        }
        if (!is_writable($normalizedDirectory)) {
            throw new RuntimeException('مجلد التخزين المعتمد غير قابل للكتابة.');
        }

        $this->directory = $normalizedDirectory;
        $this->maxBytes = $config['max_reencoded_bytes'];
        $this->maxWidth = $config['max_width_px'];
        $this->maxHeight = $config['max_height_px'];
        $this->maxPixels = $config['max_pixels'];
    }

    /** Run one decode/store operation at a time across PHP workers using this storage directory. */
    public function withExclusiveUploadLock(callable $operation): mixed
    {
        $lockPath = $this->directory . DIRECTORY_SEPARATOR . '.plant-image-upload.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('ملف قفل التخزين غير صالح.');
        }
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('تعذر فتح قفل معالجة الصور.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new PlantImageBusy('توجد معالجة صورة أخرى؛ أعد المحاولة لاحقًا.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Store only a canonical PNG produced by PlantImageValidator, within configured bounds. */
    public function store(ValidatedPlantImage $image): string
    {
        $info = @getimagesizefromstring($image->bytes);
        if ($image->mimeType !== 'image/png'
            || strlen($image->bytes) > $this->maxBytes
            || (new finfo(FILEINFO_MIME_TYPE))->buffer($image->bytes) !== 'image/png'
            || !is_array($info) || ($info['mime'] ?? null) !== 'image/png'
            || (int) ($info[0] ?? 0) !== $image->width || (int) ($info[1] ?? 0) !== $image->height
            || $image->width < 1 || $image->height < 1
            || $image->width > $this->maxWidth || $image->height > $this->maxHeight
            || $image->width > intdiv($this->maxPixels, $image->height)) {
            throw new InvalidArgumentException('التخزين يقبل PNG متحققة وضمن حدود الإعداد فقط.');
        }
        $decoded = @imagecreatefromstring($image->bytes);
        if (!$decoded instanceof GdImage) {
            throw new InvalidArgumentException('تعذر فك صورة PNG قبل التخزين.');
        }
        $decodedMatches = imagesx($decoded) === $image->width && imagesy($decoded) === $image->height;
        imagedestroy($decoded);
        if (!$decodedMatches) {
            throw new InvalidArgumentException('أبعاد صورة PNG لا تطابق بياناتها قبل التخزين.');
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $filename = bin2hex(random_bytes(24)) . '.png';
            $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
            $handle = $this->writer->openExclusive($path);
            if ($handle === false) {
                continue;
            }

            $committed = false;
            $writer = $this->writer;
            register_shutdown_function(static function () use ($path, &$committed, $writer): void {
                if (!$committed) {
                    try {
                        if ($writer->isFile($path)) {
                            $writer->delete($path);
                        }
                    } catch (Throwable) {
                        // Shutdown cleanup is best-effort; the normal path reports cleanup failures.
                    }
                }
            });

            try {
                $written = 0;
                $length = strlen($image->bytes);
                while ($written < $length) {
                    $chunkWritten = $this->writer->write($handle, substr($image->bytes, $written));
                    if ($chunkWritten === false || $chunkWritten === 0) {
                        throw new RuntimeException('تعذر كتابة الصورة كاملة في مجلدها الخاص.');
                    }
                    $written += $chunkWritten;
                }
                $flushSucceeded = $this->writer->flush($handle);
                $closeSucceeded = $this->writer->close($handle);
                $handle = false;
                if (!$flushSucceeded || !$closeSucceeded) {
                    throw new RuntimeException('تعذر إغلاق ملف الصورة بعد الكتابة.');
                }

                clearstatcache(true, $path);
                if (!$this->writer->isFile($path) || $this->writer->size($path) !== $length
                    || $this->writer->mimeType($path) !== 'image/png') {
                    throw new RuntimeException('تعذر التحقق من ملف الصورة المكتوب.');
                }
                $committed = true;
                return $filename;
            } catch (Throwable $exception) {
                if ($this->writer->isOpen($handle)) {
                    try {
                        $this->writer->close($handle);
                    } catch (Throwable) {
                        // Continue to remove the request-owned partial file.
                    }
                }
                if ($this->writer->isFile($path) && !$this->writer->delete($path)) {
                    throw new RuntimeException('فشل التخزين وتعذر تنظيف الملف الجزئي.');
                }
                throw $exception;
            }
        }

        throw new RuntimeException('تعذر إنشاء ملف آمن في مسار التخزين المعتمد.');
    }

    /** Delete only a file created by this storage class. */
    public function deleteStoredImage(string $filename): bool
    {
        $path = $this->absolutePathForInternalUse($filename);

        if (is_link($path)) {
            throw new RuntimeException('Stored image path must not be a symbolic link.');
        }

        if (!is_file($path)) {
            return true;
        }

        if (!$this->writer->isFile($path)) {
            throw new RuntimeException('Stored image could not be verified before deletion.');
        }

        if (!$this->writer->delete($path)) {
            throw new RuntimeException('Stored image cleanup failed.');
        }

        return true;
    }
    public function absolutePathForInternalUse(string $filename): string
    {
        if (!preg_match('/\A[a-f0-9]{48}\.png\z/', $filename)) {
            throw new InvalidArgumentException('اسم ملف الصورة غير صالح.');
        }
        return $this->directory . DIRECTORY_SEPARATOR . $filename;
    }
}
