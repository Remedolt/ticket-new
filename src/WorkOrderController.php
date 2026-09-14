<?php

declare(strict_types=1);

final class WorkOrderController
{
    public static function index(): void
    {
        $user = Auth::requireUser();
        $pdo = Database::connection();

        $sql = 'SELECT w.*, t.code AS ticket_code, t.title AS ticket_title,
                       au.name AS assigned_to_name
                FROM work_orders w
                JOIN tickets t ON t.id = w.ticket_id
                LEFT JOIN users au ON au.id = w.assigned_to';

        $params = [];
        if (!Auth::isAdmin($user)) {
            $sql .= ' WHERE w.assigned_to = ? OR w.created_by = ?';
            $params = [$user['id'], $user['id']];
        }

        $sql .= ' ORDER BY w.updated_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::json(['work_orders' => $stmt->fetchAll()]);
    }

    public static function store(): void
    {
        $user = Auth::requireAdmin();
        $body = json_input();

        $ticketId = (int) ($body['ticket_id'] ?? 0);
        $title = trim((string) ($body['title'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        $assignedTo = isset($body['assigned_to']) && $body['assigned_to'] !== ''
            ? (int) $body['assigned_to']
            : null;

        if ($ticketId < 1 || $title === '') {
            Response::error('Ticket ve başlık zorunlu.');
        }

        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM tickets WHERE id = ?');
        $check->execute([$ticketId]);
        if (!$check->fetch()) {
            Response::error('Ticket bulunamadı.', 404);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO work_orders (ticket_id, title, notes, status, assigned_to, created_by)
             VALUES (?, ?, ?, "pending", ?, ?)'
        );
        $stmt->execute([$ticketId, $title, $notes, $assignedTo, $user['id']]);

        Response::json(['id' => (int) $pdo->lastInsertId()], 201);
    }

    public static function update(array $params): void
    {
        $user = Auth::requireUser();
        $pdo = Database::connection();
        $id = (int) $params['id'];
        $body = json_input();

        $stmt = $pdo->prepare('SELECT * FROM work_orders WHERE id = ?');
        $stmt->execute([$id]);
        $wo = $stmt->fetch();
        if (!$wo) {
            Response::error('İş emri bulunamadı.', 404);
        }

        $isAdmin = Auth::isAdmin($user);
        $isAssignee = (int) ($wo['assigned_to'] ?? 0) === (int) $user['id'];
        if (!$isAdmin && !$isAssignee) {
            Response::error('Bu iş emri için yetkiniz yok.', 403);
        }

        $status = (string) ($body['status'] ?? $wo['status']);
        $notes = trim((string) ($body['notes'] ?? $wo['notes']));
        $title = trim((string) ($body['title'] ?? $wo['title']));
        $assignedTo = $isAdmin && array_key_exists('assigned_to', $body)
            ? ($body['assigned_to'] !== null && $body['assigned_to'] !== '' ? (int) $body['assigned_to'] : null)
            : $wo['assigned_to'];

        if (!in_array($status, ['pending', 'in_progress', 'done', 'cancelled'], true)) {
            Response::error('Geçersiz durum.');
        }

        $upd = $pdo->prepare(
            'UPDATE work_orders SET title = ?, notes = ?, status = ?, assigned_to = ?,
             updated_at = datetime("now") WHERE id = ?'
        );
        $upd->execute([$title, $notes, $status, $assignedTo, $id]);

        Response::json(['ok' => true]);
    }
}