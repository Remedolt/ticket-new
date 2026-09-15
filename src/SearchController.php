<?php

declare(strict_types=1);

final class SearchController
{
    public static function index(): void
    {
        $user = Auth::requireUser();
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['tickets' => [], 'work_orders' => [], 'users' => []]);
        }

        $pdo = Database::connection();
        $isAdmin = Auth::isAdmin($user);
        $like = '%' . $q . '%';

        $ticketSql = 'SELECT t.id, t.code, t.title, t.status, t.priority
                      FROM tickets t WHERE (t.title LIKE ? OR t.code LIKE ? OR t.description LIKE ?)';
        $ticketParams = [$like, $like, $like];
        if (!$isAdmin) {
            $ticketSql .= ' AND (t.created_by = ? OR t.assigned_to = ?)';
            $ticketParams[] = $user['id'];
            $ticketParams[] = $user['id'];
        }
        $ticketSql .= ' ORDER BY t.updated_at DESC LIMIT 8';
        $t = $pdo->prepare($ticketSql);
        $t->execute($ticketParams);

        $woSql = 'SELECT w.id, w.title, w.status, t.code AS ticket_code, t.id AS ticket_id
                  FROM work_orders w
                  JOIN tickets t ON t.id = w.ticket_id
                  WHERE w.title LIKE ? OR w.notes LIKE ? OR t.code LIKE ?';
        $woParams = [$like, $like, $like];
        if (!$isAdmin) {
            $woSql .= ' AND (w.assigned_to = ? OR w.created_by = ?)';
            $woParams[] = $user['id'];
            $woParams[] = $user['id'];
        }
        $woSql .= ' ORDER BY w.updated_at DESC LIMIT 5';
        $w = $pdo->prepare($woSql);
        $w->execute($woParams);

        $users = [];
        if ($isAdmin) {
            $u = $pdo->prepare(
                'SELECT u.id, u.name, u.email, r.name AS role_name
                 FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE u.name LIKE ? OR u.email LIKE ?
                 LIMIT 5'
            );
            $u->execute([$like, $like]);
            $users = $u->fetchAll();
        }

        Response::json([
            'tickets' => $t->fetchAll(),
            'work_orders' => $w->fetchAll(),
            'users' => $users,
        ]);
    }
}
