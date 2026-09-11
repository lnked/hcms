<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Core\Settings;
use Cms\Database\Connection;
use RuntimeException;

final class EntryRevisionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     */
    public function snapshot(
        int $resourceId,
        int $entryId,
        array $before,
        ?array $after = null,
        ?int $actorUserId = null,
    ): int {
        $diff = $after === null ? null : $this->diff($before, $after);
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_entry_revisions (resource_id, entry_id, data_json, diff_json, actor_user_id, created_at)
             VALUES (:resource_id, :entry_id, :data_json, :diff_json, :actor_user_id, :created_at)',
            [
                'resource_id' => $resourceId,
                'entry_id' => $entryId,
                'data_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'diff_json' => $diff === null
                    ? null
                    : json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'actor_user_id' => $actorUserId,
                'created_at' => $now,
            ],
        );
        $id = (int) $this->db->lastInsertId();
        $this->prune($resourceId, $entryId);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $resourceId, int $entryId, int $limit = 50): array
    {
        $limit = min(100, max(1, $limit));
        $rows = $this->db->select(
            'SELECT r.id, r.resource_id, r.entry_id, r.data_json, r.diff_json, r.actor_user_id, r.created_at,
                    u.name AS actor_name, u.email AS actor_email
             FROM cms_entry_revisions r
             LEFT JOIN cms_users u ON u.id = r.actor_user_id
             WHERE r.resource_id = :resource_id AND r.entry_id = :entry_id
             ORDER BY r.id DESC
             LIMIT ' . $limit,
            ['resource_id' => $resourceId, 'entry_id' => $entryId],
        );

        return array_map(fn (array $row): array => $this->serialize($row), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $resourceId, int $entryId, int $revisionId): array
    {
        $row = $this->db->selectOne(
            'SELECT r.id, r.resource_id, r.entry_id, r.data_json, r.diff_json, r.actor_user_id, r.created_at,
                    u.name AS actor_name, u.email AS actor_email
             FROM cms_entry_revisions r
             LEFT JOIN cms_users u ON u.id = r.actor_user_id
             WHERE r.id = :id AND r.resource_id = :resource_id AND r.entry_id = :entry_id',
            ['id' => $revisionId, 'resource_id' => $resourceId, 'entry_id' => $entryId],
        );
        if ($row === null) {
            throw new RuntimeException('Revision not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * @return array<string, mixed> snapshot data to restore
     */
    public function dataForRestore(int $resourceId, int $entryId, int $revisionId): array
    {
        $rev = $this->find($resourceId, $entryId, $revisionId);
        /** @var array<string, mixed> $data */
        $data = \is_array($rev['data']) ? $rev['data'] : [];

        return $data;
    }

    private function prune(int $resourceId, int $entryId): void
    {
        $keep = $this->settings?->int('content.revisions_keep', 50) ?? 50;
        $keep = max(1, $keep);
        $rows = $this->db->select(
            'SELECT id FROM cms_entry_revisions
             WHERE resource_id = :resource_id AND entry_id = :entry_id
             ORDER BY id DESC',
            ['resource_id' => $resourceId, 'entry_id' => $entryId],
        );
        if (\count($rows) <= $keep) {
            return;
        }
        $drop = \array_slice($rows, $keep);
        foreach ($drop as $row) {
            $this->db->execute('DELETE FROM cms_entry_revisions WHERE id = :id', ['id' => (int) $row['id']]);
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $out = [];
        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;
            if ($from !== $to) {
                $out[$key] = ['from' => $from, 'to' => $to];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $data = json_decode((string) $row['data_json'], true);
        $diff = $row['diff_json'] === null ? null : json_decode((string) $row['diff_json'], true);

        return [
            'id' => (int) $row['id'],
            'resourceId' => (int) $row['resource_id'],
            'entryId' => (int) $row['entry_id'],
            'data' => \is_array($data) ? $data : [],
            'diff' => \is_array($diff) ? $diff : null,
            'actorUserId' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            'actor' => $this->serializeActor($row),
            'createdAt' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, email: string}|null
     */
    private function serializeActor(array $row): ?array
    {
        if ($row['actor_user_id'] === null) {
            return null;
        }

        return [
            'id' => (int) $row['actor_user_id'],
            'name' => (string) ($row['actor_name'] ?? ''),
            'email' => (string) ($row['actor_email'] ?? ''),
        ];
    }
}
