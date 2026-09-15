<?php

declare(strict_types=1);

final class NotificationController
{
    public static function index(): void
    {
        $user = Auth::requireUser();
        $pdo = Database::connection();
        $items = [];

        $tickets = $pdo->prepare(
            "SELECT id, code, title, status, priority, updated_at
             FROM tickets
             WHERE assigned_to = ?
               AND status IN ('open', 'in_progress')
             ORDER BY
               CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
               updated_at DESC
             LIMIT 12"
        );
        $tickets->execute([$user['id']]);
        foreach ($tickets->fetchAll() as $t) {
            $items[] = [
                'type' => 'ticket',
                'id' => (int) $t['id'],
                'title' => $t['title'],
                'meta' => $t['code'] . ' · ' . $t['status'],
                'priority' => $t['priority'],
                'href' => '/tickets/' . $t['id'],
                'at' => $t['updated_at'],
            ];
        }

        $wo = $pdo->prepare(
            "SELECT w.id, w.title, w.status, w.updated_at, t.code AS ticket_code, t.id AS ticket_id
             FROM work_orders w
             JOIN tickets t ON t.id = w.ticket_id
             WHERE w.assigned_to = ?
               AND w.status IN ('pending', 'in_progress')
             ORDER BY w.updated_at DESC
             LIMIT 8"
        );
        $wo->execute([$user['id']]);
        foreach ($wo->fetchAll() as $w) {
            $items[] = [
                'type' => 'work_order',
                'id' => (int) $w['id'],
                'title' => $w['title'],
                'meta' => $w['ticket_code'] . ' · iş emri',
                'priority' => null,
                'href' => '/tickets/' . $w['ticket_id'],
                'at' => $w['updated_at'],
            ];
        }

        usort($items, static fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        Response::json([
            'count' => count($items),
            'items' => array_slice($items, 0, 12),
        ]);
    }
}
