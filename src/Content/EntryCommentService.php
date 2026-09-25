<?php

declare(strict_types=1);

namespace Cms\Content;

use Cms\Core\Exception\NotFoundException;
use Cms\Core\Exception\ValidationFailedException;
use Cms\Database\Connection;

final class EntryCommentService
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $resourceId, int $entryId, int $limit = 100): array
    {
        $limit = min(200, max(1, $limit));
        $rows = $this->db->select(
            'SELECT c.id, c.resource_id, c.entry_id, c.user_id, c.body, c.created_at,
                    u.name AS user_name, u.email AS user_email
             FROM cms_entry_comments c
             LEFT JOIN cms_users u ON u.id = c.user_id
             WHERE c.resource_id = :resource_id AND c.entry_id = :entry_id
             ORDER BY c.id ASC
             LIMIT ' . $limit,
            ['resource_id' => $resourceId, 'entry_id' => $entryId],
        );

        return array_map(fn (array $row): array => $this->serialize($row), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $resourceId, int $entryId, int $userId, string $body): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            throw ValidationFailedException::field('body', 'Comment body is required');
        }
        if (mb_strlen($trimmed) > 5000) {
            throw ValidationFailedException::field('body', 'Comment body is too long');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO cms_entry_comments (resource_id, entry_id, user_id, body, created_at)
             VALUES (:resource_id, :entry_id, :user_id, :body, :created_at)',
            [
                'resource_id' => $resourceId,
                'entry_id' => $entryId,
                'user_id' => $userId,
                'body' => $trimmed,
                'created_at' => $now,
            ],
        );
        $id = (int) $this->db->lastInsertId();

        return $this->find($resourceId, $entryId, $id);
    }

    public function delete(int $resourceId, int $entryId, int $commentId, ?int $actorUserId, bool $canModerate): void
    {
        $row = $this->db->selectOne(
            'SELECT id, user_id FROM cms_entry_comments
             WHERE id = :id AND resource_id = :resource_id AND entry_id = :entry_id',
            ['id' => $commentId, 'resource_id' => $resourceId, 'entry_id' => $entryId],
        );
        if ($row === null) {
            throw new NotFoundException('Comment not found');
        }
        $ownerId = (int) ($row['user_id'] ?? 0);
        if (!$canModerate && ($actorUserId === null || $ownerId !== $actorUserId)) {
            throw new NotFoundException('Comment not found');
        }
        $this->db->execute('DELETE FROM cms_entry_comments WHERE id = :id', ['id' => $commentId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $resourceId, int $entryId, int $commentId): array
    {
        $row = $this->db->selectOne(
            'SELECT c.id, c.resource_id, c.entry_id, c.user_id, c.body, c.created_at,
                    u.name AS user_name, u.email AS user_email
             FROM cms_entry_comments c
             LEFT JOIN cms_users u ON u.id = c.user_id
             WHERE c.id = :id AND c.resource_id = :resource_id AND c.entry_id = :entry_id',
            ['id' => $commentId, 'resource_id' => $resourceId, 'entry_id' => $entryId],
        );
        if ($row === null) {
            throw new NotFoundException('Comment not found');
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $userId = (int) ($row['user_id'] ?? 0);

        return [
            'id' => (int) $row['id'],
            'resourceId' => (int) $row['resource_id'],
            'entryId' => (int) $row['entry_id'],
            'userId' => $userId,
            'body' => (string) ($row['body'] ?? ''),
            'createdAt' => $row['created_at'] ?? null,
            'user' => $userId > 0
                ? [
                    'id' => $userId,
                    'name' => (string) ($row['user_name'] ?? ''),
                    'email' => (string) ($row['user_email'] ?? ''),
                ]
                : null,
        ];
    }
}
