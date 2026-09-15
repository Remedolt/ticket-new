<?php

declare(strict_types=1);

final class Activity
{
    public static function log(?int $userId, ?int $ticketId, string $action, string $detail = ''): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO activity_log (user_id, ticket_id, action, detail) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $ticketId, $action, $detail]);
    }
}
