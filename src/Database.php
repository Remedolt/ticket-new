<?php

declare(strict_types=1);

final class Database
{
    public const ADMIN_LEVEL = 50;
    public const USER_LEVEL = 10;

    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0775, true);
        }
        if (!is_dir($dataDir) || !is_writable($dataDir)) {
            throw new RuntimeException('data klasörü yok veya yazılamıyor. Plesk’te data için 775 verin.');
        }

        $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'helpdesk.db';
        $needsSeed = !file_exists($dbPath);

        self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON');

        $schema = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'schema.sql');
        self::$pdo->exec($schema);

        if ($needsSeed) {
            self::seed();
        } else {
            self::migrate();
        }

        return self::$pdo;
    }

    private static function migrate(): void
    {
        $pdo = self::$pdo;

        $pdo->exec("INSERT OR IGNORE INTO roles (slug, name, role_level) VALUES
            ('admin', 'Yönetici', " . self::ADMIN_LEVEL . "),
            ('user', 'Kullanıcı', " . self::USER_LEVEL . ")
        ");

        $pdo->exec("UPDATE roles SET name = 'Yönetici', role_level = " . self::ADMIN_LEVEL . " WHERE slug = 'admin'");
        $pdo->exec("UPDATE roles SET name = 'Kullanıcı', role_level = " . self::USER_LEVEL . " WHERE slug = 'user'");

        $adminId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'admin'")->fetchColumn();
        $master = $pdo->query("SELECT id FROM roles WHERE slug = 'master_admin'")->fetchColumn();

        if ($master && $adminId) {
            $pdo->prepare('UPDATE users SET role_id = ? WHERE role_id = ?')->execute([$adminId, $master]);
            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$master]);
        }

        $pdo->exec("UPDATE users SET name = 'Yönetici' WHERE email = 'admin@helpdesk.local' AND name IN ('Ana Yönetici', 'Master Admin')");

        self::ensureColumn('tickets', 'category', "TEXT NOT NULL DEFAULT 'genel'");
        self::ensureColumn('tickets', 'due_date', 'TEXT');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS activity_log (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER,
              ticket_id INTEGER,
              action TEXT NOT NULL,
              detail TEXT NOT NULL DEFAULT "",
              created_at TEXT NOT NULL DEFAULT (datetime("now")),
              FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
              FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
            )'
        );
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        $pdo = self::$pdo;
        $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
        foreach ($cols as $col) {
            if (($col['name'] ?? '') === $column) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private static function seed(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("INSERT INTO roles (slug, name, role_level) VALUES
            ('admin', 'Yönetici', " . self::ADMIN_LEVEL . "),
            ('user', 'Kullanıcı', " . self::USER_LEVEL . ")
        ");

        $users = [
            ['Yönetici', 'admin@helpdesk.local', 'admin123', 'admin'],
            ['Ayşe Demir', 'user@helpdesk.local', 'user123', 'user'],
            ['Mehmet Kaya', 'user2@helpdesk.local', 'user123', 'user'],
            ['Selin Arslan', 'staff@helpdesk.local', 'staff123', 'admin'],
        ];

        $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE slug = ?');
        $userStmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role_id) VALUES (?, ?, ?, ?)'
        );

        foreach ($users as [$name, $email, $password, $roleSlug]) {
            $roleStmt->execute([$roleSlug]);
            $roleId = (int) $roleStmt->fetchColumn();
            $userStmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
        }

        $ticketStmt = $pdo->prepare(
            'INSERT INTO tickets (code, title, description, status, priority, category, due_date, created_by, assigned_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ticketStmt->execute([
            'HD-1001',
            'VPN bağlantı sorunu',
            'Uzaktan çalışan ekip VPN üzerinden erişemiyor.',
            'open',
            'high',
            'ag',
            date('Y-m-d', strtotime('+2 days')),
            1,
            2,
        ]);
        $ticketStmt->execute([
            'HD-1002',
            'Yazıcı sürücü güncellemesi',
            'Kat 3 yazıcısı için sürücü kurulumu gerekli.',
            'in_progress',
            'medium',
            'donanim',
            date('Y-m-d', strtotime('+5 days')),
            4,
            3,
        ]);
        $ticketStmt->execute([
            'HD-1003',
            'Yeni personel hesabı',
            'İK onayıyla yeni kullanıcı hesabı açılacak.',
            'open',
            'low',
            'hesap',
            null,
            2,
            null,
        ]);

        $woStmt = $pdo->prepare(
            'INSERT INTO work_orders (ticket_id, title, notes, status, assigned_to, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $woStmt->execute([1, 'VPN gateway kontrolü', 'Firewall kuralı gözden geçirilecek.', 'pending', 2, 1]);
        $woStmt->execute([2, 'Sürücü kurulumu', 'Windows 11 uyumlu paket.', 'in_progress', 3, 4]);

        $c = $pdo->prepare('INSERT INTO ticket_comments (ticket_id, user_id, body) VALUES (?, ?, ?)');
        $c->execute([1, 1, 'Gateway logları incelenecek.']);
        $c->execute([2, 4, 'Sürücü paketi indirildi.']);
    }
}
