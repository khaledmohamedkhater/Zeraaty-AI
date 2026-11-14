<?php
declare(strict_types=1);

final class PlantImageAnalysisImage
{
    public static function toJpegBytes(
        ValidatedPlantImage $image,
        int $maxPixels = 1500000,
        int $quality = 85
    ): string {
        if ($maxPixels < 1 || $maxPixels > 2000000) {
            throw new InvalidArgumentException('Invalid analysis image pixel limit.');
        }

        if ($quality < 60 || $quality > 90) {
            throw new InvalidArgumentException('Invalid JPEG quality.');
        }

        if (!function_exists('imagecreatefromstring')
            || !function_exists('imagecreatetruecolor')
            || !function_exists('imagecopyresampled')
            || !function_exists('imagejpeg')) {
            throw new RuntimeException('GD image processing is unavailable.');
        }

        $source = @imagecreatefromstring($image->bytes);
        if (!$source instanceof GdImage) {
            throw new InvalidArgumentException('Validated image could not be decoded.');
        }

        $target = null;

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            if ($width < 1 || $height < 1
                || $width !== $image->width
                || $height !== $image->height) {
                throw new InvalidArgumentException('Image dimensions do not match.');
            }

            $pixels = $width * $height;
            $scale = $pixels > $maxPixels
                ? sqrt($maxPixels / $pixels)
                : 1.0;

            $newWidth = max(1, (int) floor($width * $scale));
            $newHeight = max(1, (int) floor($height * $scale));

            $target = imagecreatetruecolor($newWidth, $newHeight);
            if (!$target instanceof GdImage) {
                throw new RuntimeException('Could not allocate analysis image.');
            }

            $white = imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $newWidth, $newHeight, $white);

            if (!imagecopyresampled(
                $target,
                $source,
                0,
                0,
                0,
                0,
                $newWidth,
                $newHeight,
                $width,
                $height
            )) {
                throw new RuntimeException('Could not resize analysis image.');
            }

            ob_start();
            $written = imagejpeg($target, null, $quality);
            $jpeg = ob_get_clean();

            if ($written !== true || !is_string($jpeg) || $jpeg === '') {
                throw new RuntimeException('Could not encode analysis image.');
            }

            $info = @getimagesizefromstring($jpeg);
            if (!is_array($info)
                || ($info[2] ?? null) !== IMAGETYPE_JPEG
                || strlen($jpeg) > 5 * 1024 * 1024) {
                throw new RuntimeException('Generated analysis image is invalid.');
            }

            return $jpeg;
        } finally {
            if ($target instanceof GdImage) {
                imagedestroy($target);
            }
            imagedestroy($source);
        }
    }
}