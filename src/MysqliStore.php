<?php
declare(strict_types=1);
namespace QrStaging;
require_once __DIR__ . '/App.php';

final class MysqliStore implements ScanStore
{
    private \mysqli $connection;

    public function __construct(array $database)
    {
        // Defense in depth: never accept a remote DB or the archived production DB.
        if (!in_array($database['host'], ['localhost', '127.0.0.1'], true) ||
            $database['name'] !== 'qr_staging' || $database['port'] !== 3306) {
            throw new \RuntimeException('Staging database guard rejected configuration.');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $connection = mysqli_init();
        if ($connection === false) { throw new \RuntimeException('Storage initialization failed.'); }
        $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        $connection->real_connect($database['host'], $database['username'], $database['password'],
            $database['name'], $database['port']);
        $connection->set_charset('utf8mb4');
        $connection->query("SET time_zone = '+00:00'");
        $this->connection = $connection;
    }

    public function insert(string $qr, array $actor, string $requestId): int
    {
        $db = $this->connection;
        $db->begin_transaction();
        try {
            $statement = $db->prepare('INSERT INTO random_scans (qr_data, scanned_at) VALUES (?, NOW())');
            $statement->bind_param('s', $qr);
            $statement->execute();
            $id = (int)$db->insert_id;
            $statement->close();
            $action = 'scan.created';
            $subject = $actor['subject'];
            $audit = $db->prepare('INSERT INTO audit_events (request_id, actor_id, action, record_id, created_at) VALUES (?, ?, ?, ?, NOW())');
            $audit->bind_param('sssi', $requestId, $subject, $action, $id);
            $audit->execute();
            $audit->close();
            $db->commit();
            return $id;
        } catch (\Throwable $error) {
            try { $db->rollback(); } catch (\Throwable) {}
            throw $error;
        }
    }

    public function page(int $limit, ?int $before): array
    {
        $take = $limit + 1;
        if ($before === null) {
            $statement = $this->connection->prepare('SELECT id, qr_data, scanned_at FROM random_scans ORDER BY id DESC LIMIT ?');
            $statement->bind_param('i', $take);
        } else {
            $statement = $this->connection->prepare('SELECT id, qr_data, scanned_at FROM random_scans WHERE id < ? ORDER BY id DESC LIMIT ?');
            $statement->bind_param('ii', $before, $take);
        }
        $statement->execute();
        $result = $statement->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $rows[] = $row;
        }
        $statement->close();
        return $rows;
    }
    // Intentionally no delete, employee-write, or attendance implementation.
}
