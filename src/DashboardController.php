<?php

declare(strict_types=1);

final class DashboardController
{
    public static function stats(): void
    {
        $user = Auth::requireUser();
        $pdo = Database::connection();
        $isAdmin = Auth::isAdmin($user);

        if ($isAdmin) {
            $tickets = $pdo->query(
                "SELECT
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
                    COUNT(*) AS total
                 FROM tickets"
            )->fetch();

            $workOrders = $pdo->query(
                "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                    SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_count,
                    COUNT(*) AS total
                 FROM work_orders"
            )->fetch();

            $users = $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();

            $overdue = (int) $pdo->query(
                'SELECT COUNT(*) FROM tickets
                 WHERE due_date IS NOT NULL AND due_date < date("now")
                   AND status IN ("open","in_progress")'
            )->fetchColumn();

            $overdueList = $pdo->query(
                'SELECT t.id, t.code, t.title, t.due_date, t.priority, au.name AS assigned_to_name
                 FROM tickets t
                 LEFT JOIN users au ON au.id = t.assigned_to
                 WHERE t.due_date IS NOT NULL AND t.due_date < date("now")
                   AND t.status IN ("open","in_progress")
                 ORDER BY t.due_date ASC LIMIT 6'
            )->fetchAll();

            $recent = $pdo->query(
                'SELECT t.id, t.code, t.title, t.status, t.priority, t.due_date, t.updated_at, au.name AS assigned_to_name
                 FROM tickets t
                 LEFT JOIN users au ON au.id = t.assigned_to
                 ORDER BY t.updated_at DESC LIMIT 6'
            )->fetchAll();

            $activity = $pdo->query(
                'SELECT c.id, c.body, c.created_at, u.name AS user_name, t.id AS ticket_id, t.code AS ticket_code
                 FROM ticket_comments c
                 JOIN users u ON u.id = c.user_id
                 JOIN tickets t ON t.id = c.ticket_id
                 ORDER BY c.created_at DESC LIMIT 8'
            )->fetchAll();
        } else {
            $stmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
                    COUNT(*) AS total
                 FROM tickets
                 WHERE created_by = ? OR assigned_to = ?"
            );
            $stmt->execute([$user['id'], $user['id']]);
            $tickets = $stmt->fetch();

            $w = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                    SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_count,
                    COUNT(*) AS total
                 FROM work_orders
                 WHERE assigned_to = ? OR created_by = ?"
            );
            $w->execute([$user['id'], $user['id']]);
            $workOrders = $w->fetch();
            $users = null;

            $r = $pdo->prepare(
                'SELECT t.id, t.code, t.title, t.status, t.priority, t.due_date, t.updated_at, au.name AS assigned_to_name
                 FROM tickets t
                 LEFT JOIN users au ON au.id = t.assigned_to
                 WHERE t.created_by = ? OR t.assigned_to = ?
                 ORDER BY t.updated_at DESC LIMIT 6'
            );
            $r->execute([$user['id'], $user['id']]);
            $recent = $r->fetchAll();

            $ov = $pdo->prepare(
                'SELECT COUNT(*) FROM tickets
                 WHERE due_date IS NOT NULL AND due_date < date("now")
                   AND status IN ("open","in_progress")
                   AND (created_by = ? OR assigned_to = ?)'
            );
            $ov->execute([$user['id'], $user['id']]);
            $overdue = (int) $ov->fetchColumn();

            $ovList = $pdo->prepare(
                'SELECT t.id, t.code, t.title, t.due_date, t.priority, au.name AS assigned_to_name
                 FROM tickets t
                 LEFT JOIN users au ON au.id = t.assigned_to
                 WHERE t.due_date IS NOT NULL AND t.due_date < date("now")
                   AND t.status IN ("open","in_progress")
                   AND (t.created_by = ? OR t.assigned_to = ?)
                 ORDER BY t.due_date ASC LIMIT 6'
            );
            $ovList->execute([$user['id'], $user['id']]);
            $overdueList = $ovList->fetchAll();

            $a = $pdo->prepare(
                'SELECT c.id, c.body, c.created_at, u.name AS user_name, t.id AS ticket_id, t.code AS ticket_code
                 FROM ticket_comments c
                 JOIN users u ON u.id = c.user_id
                 JOIN tickets t ON t.id = c.ticket_id
                 WHERE t.created_by = ? OR t.assigned_to = ?
                 ORDER BY c.created_at DESC LIMIT 8'
            );
            $a->execute([$user['id'], $user['id']]);
            $activity = $a->fetchAll();
        }

        Response::json([
            'tickets' => $tickets,
            'work_orders' => $workOrders,
            'active_users' => $users,
            'overdue_count' => $overdue ?? 0,
            'overdue_tickets' => $overdueList ?? [],
            'recent_tickets' => $recent,
            'activity' => $activity,
            'role' => [
                'slug' => $user['role_slug'],
                'name' => $user['role_name'],
            ],
        ]);
    }
}
