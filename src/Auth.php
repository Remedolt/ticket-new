<?php

declare(strict_types=1);

final class Auth
{
    public static function userFromToken(?string $token): ?array
    {
        if (!$token) {
            return null;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.is_active, r.slug AS role_slug, r.name AS role_name, r.role_level
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE s.token = ? AND s.expires_at > datetime("now") AND u.is_active = 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function requireUser(): array
    {
        $user = self::userFromToken(self::bearerToken());
        if (!$user) {
            Response::error('Oturum gerekli.', 401);
        }

        return $user;
    }

    public static function isAdmin(array $user): bool
    {
        return (int) $user['role_level'] >= Database::ADMIN_LEVEL
            || ($user['role_slug'] ?? '') === 'admin';
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if (!self::isAdmin($user)) {
            Response::error('Bu işlem için yönetici yetkisi gerekli.', 403);
        }

        return $user;
    }

    /** @deprecated use requireAdmin */
    public static function requireLevel(int $minLevel): array
    {
        if ($minLevel >= Database::ADMIN_LEVEL) {
            return self::requireAdmin();
        }

        return self::requireUser();
    }

    public static function login(string $email, string $password): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.password_hash, u.is_active,
                    r.slug AS role_slug, r.name AS role_name, r.role_level
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.email = ?'
        );
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();

        if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
            Response::error('E-posta veya şifre hatalı.', 401);
        }

        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
        $ins = $pdo->prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)');
        $ins->execute([$token, $user['id'], $expires]);

        unset($user['password_hash']);

        return [
            'token' => $token,
            'expires_at' => $expires,
            'user' => $user,
        ];
    }

    public static function logout(?string $token): void
    {
        if (!$token) {
            return;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE token = ?');
        $stmt->execute([$token]);
    }
}
