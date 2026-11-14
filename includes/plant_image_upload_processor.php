<?php
declare(strict_types=1);

require_once __DIR__ . '/plant_image_guard.php';
require_once __DIR__ . '/plant_image_validator.php';
require_once __DIR__ . '/plant_image_storage.php';
require_once __DIR__ . '/plant_image_analysis_image.php';
require_once __DIR__ . '/crop_health_client.php';

final class PlantImageUploadProcessor
{
    public function storeForCrop(
        PDO $pdo,
        array $user,
        int $cropId,
        array $upload,
        array $config,
        string $webRoot,
        bool $requireHttpUpload = true
    ): array {
        $farmerId = PlantImageGuard::farmerId($user);
        PlantImageGuard::requireCropOwnership($pdo, $farmerId, $cropId);

        $cropStatement = $pdo->prepare(
            'SELECT name FROM crops
             WHERE id = :crop_id AND user_id = :user_id
             LIMIT 1'
        );
        $cropStatement->execute([
            ':crop_id' => $cropId,
            ':user_id' => $farmerId,
        ]);

        $selectedCrop = $cropStatement->fetchColumn();

        if (!in_array($selectedCrop, ['wheat', 'tomato'], true)) {
            throw new RuntimeException('The selected crop type is invalid.');
        }

        $storage = new PlantImageStorage($config, $webRoot);

        [$validated, $analysisBytes] = $storage->withExclusiveUploadLock(
            static function () use ($upload, $config, $requireHttpUpload): array {
                $validator = new PlantImageValidator($config);
                $validated = $validator->validateUpload($upload, $requireHttpUpload);
                $analysisBytes = PlantImageAnalysisImage::toJpegBytes($validated);

                return [$validated, $analysisBytes];
            }
        );

        $client = new CropHealthClient();
        $analysis = $client->identifyImageBytes($analysisBytes);

        if (!isset($analysis['crop_suggestions'], $analysis['disease_suggestions'])
            || !is_array($analysis['crop_suggestions'])
            || !is_array($analysis['disease_suggestions'])) {
            throw new RuntimeException('Crop.health returned an invalid analysis result.');
        }

        $compatible = self::isCropCompatible(
            $selectedCrop,
            $analysis['crop_suggestions']
        );

        if (!$compatible) {
            $analysis['disease_suggestions'] = [];
        }

        $analysis['disease_suggestions_suppressed'] = !$compatible;

        $cropJson = json_encode(
            $analysis['crop_suggestions'],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        $diseaseJson = json_encode(
            $analysis['disease_suggestions'],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        return $storage->withExclusiveUploadLock(
            static function () use (
                $storage,
                $validated,
                $pdo,
                $cropId,
                $cropJson,
                $diseaseJson,
                $analysis
            ): array {
                $filename = $storage->store($validated);

                try {
                    $statement = $pdo->prepare(
                        'INSERT INTO plant_image_analyses
                            (crop_id, image_filename, crop_suggestions, disease_suggestions)
                         VALUES
                            (:crop_id, :image_filename, :crop_suggestions, :disease_suggestions)'
                    );

                    $statement->execute([
                        ':crop_id' => $cropId,
                        ':image_filename' => $filename,
                        ':crop_suggestions' => $cropJson,
                        ':disease_suggestions' => $diseaseJson,
                    ]);

                    return [
                        'image_id' => (int) $pdo->lastInsertId(),
                        'image_filename' => $filename,
                        'analysis' => $analysis,
                    ];
                } catch (Throwable $exception) {
                    try {
                        $storage->deleteStoredImage($filename);
                    } catch (Throwable $cleanupException) {
                        error_log('Plant image cleanup failed after database error: ' . $cleanupException->getMessage());
                    }

                    throw $exception;
                }
            }
        );
    }

    private static function isCropCompatible(
        string $selectedCrop,
        array $suggestions
    ): bool {
        if ($suggestions === []) {
            return false;
        }

        $topSuggestion = null;
        $topProbability = -1.0;

        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)
                || !isset($suggestion['name'], $suggestion['probability'])
                || !is_string($suggestion['name'])
                || !is_numeric($suggestion['probability'])) {
                continue;
            }

            $probability = (float) $suggestion['probability'];

            if ($probability > $topProbability) {
                $topProbability = $probability;
                $topSuggestion = strtolower(trim($suggestion['name']));
            }
        }

        if ($topSuggestion === null) {
            return false;
        }

        $topSuggestion = preg_replace('/[^a-z0-9 ]/', ' ', $topSuggestion);
        $topSuggestion = preg_replace('/\s+/', ' ', trim($topSuggestion));

        if ($selectedCrop === 'wheat') {
            return str_contains($topSuggestion, 'wheat')
                || str_contains($topSuggestion, 'triticum');
        }

        if ($selectedCrop === 'tomato') {
            return str_contains($topSuggestion, 'tomato')
                || str_contains($topSuggestion, 'solanum lycopersicum');
        }

        return false;
    }
}