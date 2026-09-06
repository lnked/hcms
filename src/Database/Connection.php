<?php

declare(strict_types=1);

namespace Cms\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string, charset: string} $config
     */
    public static function connect(array $config, bool $withDatabase = true): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $config['host'],
            $config['port'],
            $config['charset'],
        );
        if ($withDatabase && $config['database'] !== '') {
            $dsn .= ';dbname=' . $config['database'];
        }

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Native MySQL prepares reject reused named placeholders (HY093).
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $rows = $this->select($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId() ?: '';
    }

    public function execRaw(string $sql): void
    {
        $this->pdo->exec($sql);
    }
}
