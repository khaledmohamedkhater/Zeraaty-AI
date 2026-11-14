<?php
declare(strict_types=1);

final class PlantImageAccessDenied extends RuntimeException
{
}

final class PlantImageBusy extends RuntimeException
{
}

final class PlantImageGuard
{
    public static function farmerId(?array $user): int
    {
        if ($user === null || ($user['role'] ?? null) !== 'farmer') {
            throw new PlantImageAccessDenied('يلزم تسجيل الدخول بحساب مزارع.');
        }
        $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            throw new PlantImageAccessDenied('حساب المزارع غير صالح.');
        }
        return $userId;
    }

    public static function verifyCsrf(string $sessionToken, mixed $submittedToken): void
    {
        if ($sessionToken === '' || !is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
            throw new RuntimeException('انتهت صلاحية الطلب؛ أعد تحميل الصفحة وحاول مرة أخرى.');
        }
    }

    public static function requireCropOwnership(PDO $pdo, int $farmerId, int $cropId): void
    {
        if ($farmerId < 1 || $cropId < 1) {
            throw new InvalidArgumentException('رقم المحصول غير صالح.');
        }
        $statement = $pdo->prepare('SELECT id FROM crops WHERE id = :crop_id AND user_id = :user_id LIMIT 1');
        $statement->execute(['crop_id' => $cropId, 'user_id' => $farmerId]);
        if ($statement->fetchColumn() === false) {
            throw new PlantImageAccessDenied('المحصول غير موجود أو لا يتبع حسابك.');
        }
    }
}