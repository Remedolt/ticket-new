<?php

declare(strict_types=1);

final class ProfileController
{
    public static function update(): void
    {
        $user = Auth::requireUser();
        $body = json_input();
        $pdo = Database::connection();

        $name = trim((string) ($body['name'] ?? $user['name']));
        if ($name === '') {
            Response::error('Ad zorunlu.');
        }

        $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);

        if (!empty($body['password'])) {
            $password = (string) $body['password'];
            $current = (string) ($body['current_password'] ?? '');
            if (strlen($password) < 6) {
                Response::error('Yeni şifre en az 6 karakter olmalı.');
            }

            $row = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $row->execute([$user['id']]);
            $hash = (string) $row->fetchColumn();
            if (!$current || !password_verify($current, $hash)) {
                Response::error('Mevcut şifre hatalı.', 403);
            }

            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        $fresh = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.is_active, r.slug AS role_slug, r.name AS role_name, r.role_level
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?'
        );
        $fresh->execute([$user['id']]);

        Response::json(['user' => $fresh->fetch()]);
    }
}
