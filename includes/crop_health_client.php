<?php
declare(strict_types=1);

/**
 * Small server-side Crop.health client.
 *
 * This client is not connected to an upload page in phase 1. A future caller
 * must first validate the image bytes, dimensions, crop ownership and storage.
 */
final class CropHealthClient
{
    private string $apiKey;
    private string $endpoint;
    private int $connectTimeout;
    private int $requestTimeout;

    public function __construct()
    {
        $config = require __DIR__ . '/../config/crop_health.php';
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->endpoint = (string) ($config['endpoint'] ?? '');
        $this->connectTimeout = (int) ($config['connect_timeout_seconds'] ?? 0);
        $this->requestTimeout = (int) ($config['request_timeout_seconds'] ?? 0);

        if ($this->apiKey === '') {
            throw new RuntimeException('خدمة تحليل الصور غير مهيأة.');
        }
        if ($this->endpoint !== 'https://crop.kindwise.com/api/v1/identification') {
            throw new RuntimeException('إعداد خدمة تحليل الصور غير صالح.');
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('امتداد cURL غير مفعّل في PHP.');
        }
        if ($this->connectTimeout < 1 || $this->requestTimeout < $this->connectTimeout) {
            throw new RuntimeException('مهلة الاتصال بخدمة تحليل الصور غير صالحة.');
        }
    }

    /**
     * Accept a validated JPEG image in binary form.
     * Enforces a strict size and pixel limit before Base64 encoding.
     *
     * @return array{crop_suggestions: array, disease_suggestions: array}
     */
    public function identifyImageBytes(string $imageBytes): array
    {
        if ($imageBytes === '' || strlen($imageBytes) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Invalid analysis image size.');
        }

        $info = @getimagesizefromstring($imageBytes);

        if (!is_array($info)
            || ($info[2] ?? null) !== IMAGETYPE_JPEG
            || !isset($info[0], $info[1])
            || $info[0] < 1
            || $info[1] < 1
            || ($info[0] * $info[1]) > 2000000) {
            throw new InvalidArgumentException('Invalid analysis JPEG image.');
        }

        return $this->identifyBase64Image(base64_encode($imageBytes));
    }
    /**
     * Send Base64 image data to Crop.health.
     * The caller is responsible for validating the image before this method.
     * No user-facing upload flow calls this method in phase 1.
     *
     * @return array{crop_suggestions: array, disease_suggestions: array}
     */
    public function identifyBase64Image(string $imageBase64): array
    {
        $decoded = base64_decode($imageBase64, true);
        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('بيانات الصورة غير صالحة.');
        }

        $payload = self::buildPayload($imageBase64);
        $handle = curl_init($this->endpoint);
        if ($handle === false) {
            throw new RuntimeException('تعذر بدء الاتصال بخدمة تحليل الصور.');
        }

        $optionsSet = curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Api-Key: ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if (!$optionsSet) {
            curl_close($handle);
            throw new RuntimeException('تعذر تجهيز اتصال خدمة تحليل الصور.');
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $failed = $body === false;
        curl_close($handle);

        if ($failed) {
            // Do not log cURL diagnostics or request headers; they may contain sensitive data.
            throw new RuntimeException('تعذر الاتصال بخدمة تحليل الصور.');
        }

        $statusMessage = self::httpStatusError($status);
        if ($statusMessage !== null) {
            throw new RuntimeException($statusMessage);
        }

        return self::parseResponse((string) $body);
    }

    /** Build the documented JSON envelope without sending a request. */
    public static function buildPayload(string $imageBase64): string
    {
        try {
            return json_encode(
                ['images' => [$imageBase64]],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('تعذر تجهيز بيانات الصورة.', 0, $exception);
        }
    }

    /**
     * Parse only the documented result fields needed by the application.
     * No prediction values are supplied by this client itself.
     *
     * @return array{crop_suggestions: array, disease_suggestions: array}
     */
    public static function parseResponse(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('استجابة خدمة تحليل الصور غير مفهومة.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new UnexpectedValueException('استجابة خدمة تحليل الصور لا تحتوي على كائن JSON متوقع.');
        }

        $cropSuggestions = $decoded['result']['crop']['suggestions'] ?? null;
        $diseaseSuggestions = $decoded['result']['disease']['suggestions'] ?? null;
        if (!is_array($cropSuggestions) || !is_array($diseaseSuggestions)) {
            throw new UnexpectedValueException('استجابة خدمة تحليل الصور لا تحتوي على النتائج المتوقعة.');
        }

        return [
            'crop_suggestions' => self::cleanSuggestions($cropSuggestions),
            'disease_suggestions' => self::cleanSuggestions($diseaseSuggestions),
        ];
    }

    /** Return a safe generic error for HTTP failures, or null on success. */
    public static function httpStatusError(int $status): ?string
    {
        if ($status === 200 || $status === 201) {
            return null;
        }
        if ($status === 401 || $status === 403) {
            return 'تعذر التحقق من إعدادات خدمة تحليل الصور.';
        }
        if ($status === 402 || $status === 429) {
            return 'تعذر إكمال التحليل بسبب الرصيد أو حد الاستخدام.';
        }
        if ($status >= 400 && $status < 500) {
            return 'رفضت خدمة تحليل الصور الطلب.';
        }
        if ($status >= 500) {
            return 'خدمة تحليل الصور غير متاحة حاليًا.';
        }

        return 'استجابة خدمة تحليل الصور غير متوقعة.';
    }

    private static function cleanSuggestions(array $suggestions): array
    {
        $clean = [];
        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)
                || !isset($suggestion['name'], $suggestion['probability'])
                || !is_string($suggestion['name'])
                || !is_numeric($suggestion['probability'])) {
                throw new UnexpectedValueException('صيغة اقتراح خدمة تحليل الصور غير متوقعة.');
            }

            $probability = (float) $suggestion['probability'];
            if ($suggestion['name'] === '' || $probability < 0 || $probability > 1) {
                throw new UnexpectedValueException('قيم اقتراح خدمة تحليل الصور غير صالحة.');
            }

            $clean[] = [
                'name' => $suggestion['name'],
                'probability' => $probability,
            ];
        }

        return $clean;
    }
}