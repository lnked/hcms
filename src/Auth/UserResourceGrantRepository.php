<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;

final class UserResourceGrantRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array{resourceId: int, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool, tabs: list<string>, fieldAcl: array<string, array{readable: bool, writable: bool}>, ownEntriesOnly: bool}>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT resource_id, can_read, can_create, can_update, can_delete, tabs_json, field_acl_json, own_entries_only
             FROM cms_user_resource_grants WHERE user_id = :user_id ORDER BY id ASC',
            ['user_id' => $userId],
        );

        return array_map([$this, 'serializeRow'], $rows);
    }

    /**
     * @param list<array{resourceId: int, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool, tabs: list<string>, fieldAcl?: array<string, array{readable: bool, writable: bool}>, ownEntriesOnly?: bool}> $grants
     */
    public function replace(int $userId, array $grants): void
    {
        $this->db->execute('DELETE FROM cms_user_resource_grants WHERE user_id = :user_id', ['user_id' => $userId]);
        $seen = [];
        foreach ($grants as $grant) {
            $resourceId = (int) ($grant['resourceId'] ?? 0);
            if ($resourceId < 1 || isset($seen[$resourceId])) {
                continue;
            }
            $seen[$resourceId] = true;
            $tabs = $this->normalizeTabs($grant['tabs'] ?? []);
            $fieldAcl = FieldAcl::normalize($grant['fieldAcl'] ?? []);
            $this->db->execute(
                'INSERT INTO cms_user_resource_grants
                 (user_id, resource_id, can_read, can_create, can_update, can_delete, tabs_json, field_acl_json, own_entries_only)
                 VALUES (:user_id, :resource_id, :can_read, :can_create, :can_update, :can_delete, :tabs_json, :field_acl_json, :own_entries_only)',
                [
                    'user_id' => $userId,
                    'resource_id' => $resourceId,
                    'can_read' => !empty($grant['canRead']) ? 1 : 0,
                    'can_create' => !empty($grant['canCreate']) ? 1 : 0,
                    'can_update' => !empty($grant['canUpdate']) ? 1 : 0,
                    'can_delete' => !empty($grant['canDelete']) ? 1 : 0,
                    'tabs_json' => json_encode($tabs, JSON_THROW_ON_ERROR),
                    'field_acl_json' => $fieldAcl === [] ? null : json_encode($fieldAcl, JSON_THROW_ON_ERROR),
                    'own_entries_only' => !empty($grant['ownEntriesOnly']) ? 1 : 0,
                ],
            );
        }
    }

    /**
     * @return list<int>
     */
    public function resourceIdsForUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT resource_id FROM cms_user_resource_grants WHERE user_id = :user_id',
            ['user_id' => $userId],
        );

        return array_map(static fn (array $row): int => (int) $row['resource_id'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{resourceId: int, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool, tabs: list<string>, fieldAcl: array<string, array{readable: bool, writable: bool}>, ownEntriesOnly: bool}
     */
    private function serializeRow(array $row): array
    {
        $tabsRaw = $row['tabs_json'] ?? '[]';
        $decoded = \is_string($tabsRaw) ? json_decode($tabsRaw, true) : $tabsRaw;
        $tabs = \is_array($decoded) ? $this->normalizeTabs($decoded) : [];

        return [
            'resourceId' => (int) $row['resource_id'],
            'canRead' => (bool) $row['can_read'],
            'canCreate' => (bool) $row['can_create'],
            'canUpdate' => (bool) $row['can_update'],
            'canDelete' => (bool) $row['can_delete'],
            'tabs' => $tabs,
            'fieldAcl' => FieldAcl::normalize($row['field_acl_json'] ?? null),
            'ownEntriesOnly' => (bool) ($row['own_entries_only'] ?? false),
        ];
    }

    /**
     * @param mixed $tabs
     * @return list<string>
     */
    private function normalizeTabs(mixed $tabs): array
    {
        if (!\is_array($tabs)) {
            return [];
        }
        $out = [];
        foreach ($tabs as $tab) {
            if (\is_string($tab) && UserAclPolicy::isValidTab($tab) && !\in_array($tab, $out, true)) {
                $out[] = $tab;
            }
        }

        return $out;
    }
}
