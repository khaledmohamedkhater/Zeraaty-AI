<?php
declare(strict_types=1);

/**
 * Crop.health settings. Keep the API key in the process environment, never here.
 */
$apiKey = getenv('KINDWISE_CROP_HEALTH_API_KEY');

return [
    'api_key' => is_string($apiKey) ? trim($apiKey) : '',
    'endpoint' => 'https://crop.kindwise.com/api/v1/identification',
    'connect_timeout_seconds' => 5,
    'request_timeout_seconds' => 20,
];