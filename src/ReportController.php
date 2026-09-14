<?php

declare(strict_types=1);

final class ReportController
{
    public static function index(): void
    {
        Auth::requireAdmin();
        $pdo = Database::connection();

        $byCategory = $pdo->query(
            'SELECT category, COUNT(*) AS total,
                    SUM(CASE WHEN status IN ("open","in_progress") THEN 1 ELSE 0 END) AS open_total
             FROM tickets GROUP BY category ORDER BY total DESC'
        )->fetchAll();

        $byPriority = $pdo->query(
            'SELECT priority, COUNT(*) AS total FROM tickets GROUP BY priority'
        )->fetchAll();

        $byAssignee = $pdo->query(
            'SELECT u.name, COUNT(*) AS total,
                    SUM(CASE WHEN t.status IN ("open","in_progress") THEN 1 ELSE 0 END) AS open_total
             FROM tickets t
             JOIN users u ON u.id = t.assigned_to
             GROUP BY t.assigned_to
             ORDER BY open_total DESC, total DESC
             LIMIT 10'
        )->fetchAll();

        $overdue = $pdo->query(
            'SELECT t.id, t.code, t.title, t.due_date, t.priority, u.name AS assigned_to_name
             FROM tickets t
             LEFT JOIN users u ON u.id = t.assigned_to
             WHERE t.due_date IS NOT NULL
               AND t.due_date < date("now")
               AND t.status IN ("open","in_progress")
             ORDER BY t.due_date ASC
             LIMIT 15'
        )->fetchAll();

        $recentActivity = $pdo->query(
            'SELECT a.*, u.name AS user_name, t.code AS ticket_code
             FROM activity_log a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN tickets t ON t.id = a.ticket_id
             ORDER BY a.created_at DESC
             LIMIT 20'
        )->fetchAll();

        $resolvedLast7 = (int) $pdo->query(
            'SELECT COUNT(*) FROM tickets
             WHERE status IN ("resolved","closed")
               AND updated_at >= datetime("now", "-7 days")'
        )->fetchColumn();

        $createdLast7 = (int) $pdo->query(
            'SELECT COUNT(*) FROM tickets
             WHERE created_at >= datetime("now", "-7 days")'
        )->fetchColumn();

        Response::json([
            'by_category' => $byCategory,
            'by_priority' => $byPriority,
            'by_assignee' => $byAssignee,
            'overdue' => $overdue,
            'recent_activity' => $recentActivity,
            'created_last_7' => $createdLast7,
            'resolved_last_7' => $resolvedLast7,
        ]);
    }
}
