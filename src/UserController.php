<?php

declare(strict_types=1);

final class UserController
{
    public static function index(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();
        $rows = $pdo->query(
            'SELECT u.id, u.name, u.email, u.is_active, u.created_at,
                    r.slug AS role_slug, r.name AS role_name, r.role_level
             FROM users u
             JOIN roles r ON r.id = u.role_id
             ORDER BY r.role_level DESC, u.name ASC'
        )->fetchAll();

        Response::json(['users' => $rows]);
    }

    public static function staff(): void
    {
        Auth::requireUser();
        $pdo = Database::connection();
        $rows = $pdo->query(
            'SELECT u.id, u.name, u.email, r.slug AS role_slug, r.name AS role_name
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1
             ORDER BY u.name ASC'
        )->fetchAll();

        Response::json(['users' => $rows]);
    }

    public static function store(): void
    {
        Auth::requireAdmin();
        $body = json_input();

        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $roleSlug = (string) ($body['role_slug'] ?? 'user');

        if ($name === '' || $email === '' || strlen($password) < 6) {
            Response::error('Ad, e-posta ve en az 6 karakter şifre gerekli.');
        }

        if (!in_array($roleSlug, ['admin', 'user'], true)) {
            Response::error('Geçersiz rol. Yalnızca yönetici veya kullanıcı.');
        }

        $pdo = Database::connection();
        $role = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
        $role->execute([$roleSlug]);
        $roleRow = $role->fetch();
        if (!$roleRow) {
            Response::error('Rol bulunamadı.');
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role_id) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $roleRow['id']]);
        } catch (PDOException $e) {
            Response::error('Bu e-posta zaten kayıtlı.', 409);
        }

        Response::json(['id' => (int) $pdo->lastInsertId()], 201);
    }

    public static function update(array $params): void
    {
        $actor = Auth::requireAdmin();
        $pdo = Database::connection();
        $id = (int) $params['id'];
        $body = json_input();

        $stmt = $pdo->prepare(
            'SELECT u.*, r.role_level, r.slug AS role_slug FROM users u
             JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            Response::error('Kullanıcı bulunamadı.', 404);
        }

        if ((int) $target['id'] === (int) $actor['id']
            && array_key_exists('is_active', $body)
            && !(bool) $body['is_active']
        ) {
            Response::error('Kendi hesabınızı pasifleştiremezsiniz.');
        }

        $name = trim((string) ($body['name'] ?? $target['name']));
        $isActive = array_key_exists('is_active', $body) ? (int) (bool) $body['is_active'] : (int) $target['is_active'];
        $roleSlug = (string) ($body['role_slug'] ?? $target['role_slug']);

        if (!in_array($roleSlug, ['admin', 'user'], true)) {
            Response::error('Geçersiz rol.');
        }

        $role = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
        $role->execute([$roleSlug]);
        $roleRow = $role->fetch();
        if (!$roleRow) {
            Response::error('Rol bulunamadı.');
        }

        $upd = $pdo->prepare('UPDATE users SET name = ?, is_active = ?, role_id = ? WHERE id = ?');
        $upd->execute([$name, $isActive, $roleRow['id'], $id]);

        if (!empty($body['password']) && strlen((string) $body['password']) >= 6) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash((string) $body['password'], PASSWORD_DEFAULT), $id]);
        }

        Response::json(['ok' => true]);
    }
}
