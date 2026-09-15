<?php

declare(strict_types=1);

final class TicketController
{
    public const CATEGORIES = ['genel', 'ag', 'donanim', 'yazilim', 'hesap', 'diger'];

    public static function index(): void
    {
        $user = Auth::requireUser();
        $pdo = Database::connection();
        $isAdmin = Auth::isAdmin($user);

        $status = trim((string) ($_GET['status'] ?? ''));
        $priority = trim((string) ($_GET['priority'] ?? ''));
        $category = trim((string) ($_GET['category'] ?? ''));
        $q = trim((string) ($_GET['q'] ?? ''));
        $mine = ($_GET['mine'] ?? '') === '1';
        $due = trim((string) ($_GET['due'] ?? ''));

        $sql = 'SELECT t.*,
                       cu.name AS created_by_name,
                       au.name AS assigned_to_name,
                       (SELECT COUNT(*) FROM ticket_comments tc WHERE tc.ticket_id = t.id) AS comment_count,
                       (SELECT COUNT(*) FROM work_orders wo WHERE wo.ticket_id = t.id) AS work_order_count
                FROM tickets t
                JOIN users cu ON cu.id = t.created_by
                LEFT JOIN users au ON au.id = t.assigned_to
                WHERE 1=1';
        $params = [];

        if (!$isAdmin || $mine) {
            $sql .= ' AND (t.created_by = ? OR t.assigned_to = ?)';
            $params[] = $user['id'];
            $params[] = $user['id'];
        }

        if ($status !== '' && in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            $sql .= ' AND t.status = ?';
            $params[] = $status;
        }

        if ($priority !== '' && in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            $sql .= ' AND t.priority = ?';
            $params[] = $priority;
        }

        if ($category !== '' && in_array($category, self::CATEGORIES, true)) {
            $sql .= ' AND t.category = ?';
            $params[] = $category;
        }

        if ($due === 'overdue') {
            $sql .= ' AND t.due_date IS NOT NULL AND t.due_date < date("now")
                      AND t.status IN ("open", "in_progress")';
        } elseif ($due === 'soon') {
            $sql .= ' AND t.due_date IS NOT NULL AND t.due_date <= date("now", "+3 days")
                      AND t.due_date >= date("now")
                      AND t.status IN ("open", "in_progress")';
        }

        if ($q !== '') {
            $sql .= ' AND (t.title LIKE ? OR t.code LIKE ? OR t.description LIKE ? OR t.category LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY
            CASE WHEN t.due_date IS NOT NULL AND t.due_date < date("now")
                      AND t.status IN ("open","in_progress") THEN 0 ELSE 1 END,
            CASE t.priority
                WHEN "critical" THEN 1
                WHEN "high" THEN 2
                WHEN "medium" THEN 3
                ELSE 4
            END,
            t.updated_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        Response::json(['tickets' => $stmt->fetchAll()]);
    }

    public static function export(): void
    {
        $user = Auth::requireUser();
        $_GET['mine'] = Auth::isAdmin($user) ? ($_GET['mine'] ?? '') : '1';

        $pdo = Database::connection();
        $isAdmin = Auth::isAdmin($user);

        $sql = 'SELECT t.code, t.title, t.status, t.priority, t.category, t.due_date,
                       cu.name AS created_by_name, au.name AS assigned_to_name,
                       t.created_at, t.updated_at
                FROM tickets t
                JOIN users cu ON cu.id = t.created_by
                LEFT JOIN users au ON au.id = t.assigned_to
                WHERE 1=1';
        $params = [];
        if (!$isAdmin) {
            $sql .= ' AND (t.created_by = ? OR t.assigned_to = ?)';
            $params = [$user['id'], $user['id']];
        }
        $sql .= ' ORDER BY t.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="remedolt-tickets.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Kod', 'Başlık', 'Durum', 'Öncelik', 'Kategori', 'Bitiş', 'Oluşturan', 'Atanan', 'Oluşturma', 'Güncelleme']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['code'], $r['title'], $r['status'], $r['priority'], $r['category'],
                $r['due_date'] ?? '', $r['created_by_name'], $r['assigned_to_name'] ?? '',
                $r['created_at'], $r['updated_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    public static function show(array $params): void
    {
        $user = Auth::requireUser();
        $pdo = Database::connection();
        $id = (int) $params['id'];

        $stmt = $pdo->prepare(
            'SELECT t.*, cu.name AS created_by_name, au.name AS assigned_to_name
             FROM tickets t
             JOIN users cu ON cu.id = t.created_by
             LEFT JOIN users au ON au.id = t.assigned_to
             WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            Response::error('Ticket bulunamadı.', 404);
        }

        if (!Auth::isAdmin($user)
            && (int) $ticket['created_by'] !== (int) $user['id']
            && (int) ($ticket['assigned_to'] ?? 0) !== (int) $user['id']
        ) {
            Response::error('Bu ticket için yetkiniz yok.', 403);
        }

        $c = $pdo->prepare(
            'SELECT c.*, u.name AS user_name FROM ticket_comments c
             JOIN users u ON u.id = c.user_id
             WHERE c.ticket_id = ? ORDER BY c.created_at ASC'
        );
        $c->execute([$id]);

        $w = $pdo->prepare(
            'SELECT w.*, au.name AS assigned_to_name FROM work_orders w
             LEFT JOIN users au ON au.id = w.assigned_to
             WHERE w.ticket_id = ? ORDER BY w.created_at DESC'
        );
        $w->execute([$id]);

        $a = $pdo->prepare(
            'SELECT a.*, u.name AS user_name FROM activity_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.ticket_id = ? ORDER BY a.created_at DESC LIMIT 30'
        );
        $a->execute([$id]);

        Response::json([
            'ticket' => $ticket,
            'comments' => $c->fetchAll(),
            'work_orders' => $w->fetchAll(),
            'activity' => $a->fetchAll(),
        ]);
    }

    public static function store(): void
    {
        $user = Auth::requireUser();
        $body = json_input();

        $title = trim((string) ($body['title'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $priority = (string) ($body['priority'] ?? 'medium');
        $category = (string) ($body['category'] ?? 'genel');
        $dueDate = trim((string) ($body['due_date'] ?? ''));
        $assignedTo = isset($body['assigned_to']) && $body['assigned_to'] !== ''
            ? (int) $body['assigned_to']
            : null;

        if ($title === '') {
            Response::error('Başlık zorunlu.');
        }
        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            Response::error('Geçersiz öncelik.');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            Response::error('Geçersiz kategori.');
        }
        if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            Response::error('Bitiş tarihi YYYY-AA-GG formatında olmalı.');
        }

        $pdo = Database::connection();
        $code = self::nextCode($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO tickets (code, title, description, status, priority, category, due_date, created_by, assigned_to)
             VALUES (?, ?, ?, "open", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $code, $title, $description, $priority, $category,
            $dueDate !== '' ? $dueDate : null,
            $user['id'], $assignedTo,
        ]);
        $id = (int) $pdo->lastInsertId();
        Activity::log((int) $user['id'], $id, 'created', $code . ' oluşturuldu');

        Response::json(['id' => $id, 'code' => $code], 201);
    }

    public static function update(array $params): void
    {
        $user = Auth::requireUser();
        $pdo = Database::connection();
        $id = (int) $params['id'];
        $body = json_input();

        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            Response::error('Ticket bulunamadı.', 404);
        }

        $isAdmin = Auth::isAdmin($user);
        $isOwner = (int) $ticket['created_by'] === (int) $user['id']
            || (int) ($ticket['assigned_to'] ?? 0) === (int) $user['id'];

        if (!$isAdmin && !$isOwner) {
            Response::error('Bu ticket için yetkiniz yok.', 403);
        }

        $status = (string) ($body['status'] ?? $ticket['status']);
        $priority = (string) ($body['priority'] ?? $ticket['priority']);
        $category = (string) ($body['category'] ?? ($ticket['category'] ?? 'genel'));
        $title = trim((string) ($body['title'] ?? $ticket['title']));
        $description = trim((string) ($body['description'] ?? $ticket['description']));
        $dueDate = array_key_exists('due_date', $body)
            ? (trim((string) $body['due_date']) !== '' ? trim((string) $body['due_date']) : null)
            : ($ticket['due_date'] ?? null);
        $assignedTo = array_key_exists('assigned_to', $body)
            ? ($body['assigned_to'] !== null && $body['assigned_to'] !== '' ? (int) $body['assigned_to'] : null)
            : $ticket['assigned_to'];

        if (!$isAdmin) {
            $assignedTo = $ticket['assigned_to'];
        }

        if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            Response::error('Geçersiz durum.');
        }
        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            Response::error('Geçersiz öncelik.');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            Response::error('Geçersiz kategori.');
        }

        $upd = $pdo->prepare(
            'UPDATE tickets SET title = ?, description = ?, status = ?, priority = ?, category = ?,
             due_date = ?, assigned_to = ?, updated_at = datetime("now") WHERE id = ?'
        );
        $upd->execute([$title, $description, $status, $priority, $category, $dueDate, $assignedTo, $id]);

        $changes = [];
        if ($status !== $ticket['status']) {
            $changes[] = 'durum: ' . $status;
        }
        if ($priority !== $ticket['priority']) {
            $changes[] = 'öncelik: ' . $priority;
        }
        if ((string) ($assignedTo ?? '') !== (string) ($ticket['assigned_to'] ?? '')) {
            $changes[] = 'atama güncellendi';
        }
        Activity::log(
            (int) $user['id'],
            $id,
            'updated',
            $changes ? implode(', ', $changes) : 'ticket güncellendi'
        );

        Response::json(['ok' => true]);
    }

    public static function destroy(array $params): void
    {
        $user = Auth::requireAdmin();
        $pdo = Database::connection();
        $id = (int) $params['id'];

        $stmt = $pdo->prepare('SELECT code FROM tickets WHERE id = ?');
        $stmt->execute([$id]);
        $code = $stmt->fetchColumn();
        if (!$code) {
            Response::error('Ticket bulunamadı.', 404);
        }

        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        Activity::log((int) $user['id'], null, 'deleted', (string) $code . ' silindi');
        Response::json(['ok' => true]);
    }

    public static function comment(array $params): void
    {
        $user = Auth::requireUser();
        $id = (int) $params['id'];
        $body = json_input();
        $text = trim((string) ($body['body'] ?? ''));

        if ($text === '') {
            Response::error('Yorum boş olamaz.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            Response::error('Ticket bulunamadı.', 404);
        }

        if (!Auth::isAdmin($user)
            && (int) $ticket['created_by'] !== (int) $user['id']
            && (int) ($ticket['assigned_to'] ?? 0) !== (int) $user['id']
        ) {
            Response::error('Bu ticket için yetkiniz yok.', 403);
        }

        $ins = $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body) VALUES (?, ?, ?)');
        $ins->execute([$id, $user['id'], $text]);
        $pdo->prepare('UPDATE tickets SET updated_at = datetime("now") WHERE id = ?')->execute([$id]);
        Activity::log((int) $user['id'], $id, 'comment', mb_substr($text, 0, 120));

        Response::json(['id' => (int) $pdo->lastInsertId()], 201);
    }

    public static function duplicate(array $params): void
    {
        $user = Auth::requireUser();
        $pdo = Database::connection();
        $id = (int) $params['id'];

        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE id = ?');
        $stmt->execute([$id]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            Response::error('Ticket bulunamadı.', 404);
        }

        if (!Auth::isAdmin($user)
            && (int) $ticket['created_by'] !== (int) $user['id']
            && (int) ($ticket['assigned_to'] ?? 0) !== (int) $user['id']
        ) {
            Response::error('Bu ticket için yetkiniz yok.', 403);
        }

        $code = self::nextCode($pdo);
        $ins = $pdo->prepare(
            'INSERT INTO tickets (code, title, description, status, priority, category, due_date, created_by, assigned_to)
             VALUES (?, ?, ?, "open", ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $code,
            $ticket['title'] . ' (kopya)',
            $ticket['description'],
            $ticket['priority'],
            $ticket['category'] ?? 'genel',
            $ticket['due_date'] ?? null,
            $user['id'],
            $ticket['assigned_to'],
        ]);
        $newId = (int) $pdo->lastInsertId();
        Activity::log((int) $user['id'], $newId, 'duplicated', $ticket['code'] . ' → ' . $code);

        Response::json(['id' => $newId, 'code' => $code], 201);
    }

    public static function bulk(): void
    {
        $user = Auth::requireAdmin();
        $body = json_input();
        $ids = $body['ids'] ?? [];
        $status = (string) ($body['status'] ?? '');

        if (!is_array($ids) || !$ids) {
            Response::error('Ticket seçilmedi.');
        }
        if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            Response::error('Geçersiz durum.');
        }

        $pdo = Database::connection();
        $upd = $pdo->prepare(
            'UPDATE tickets SET status = ?, updated_at = datetime("now") WHERE id = ?'
        );
        $count = 0;
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id < 1) {
                continue;
            }
            $upd->execute([$status, $id]);
            if ($upd->rowCount() > 0) {
                Activity::log((int) $user['id'], $id, 'bulk_status', 'toplu: ' . $status);
                $count++;
            }
        }

        Response::json(['updated' => $count]);
    }

    private static function nextCode(PDO $pdo): string
    {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() + 1001;
        return 'RT-' . $n;
    }
}
