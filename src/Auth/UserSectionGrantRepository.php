<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Database\Connection;

final class UserSectionGrantRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<string>
     */
    public function forUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT section FROM cms_user_section_grants WHERE user_id = :user_id ORDER BY section ASC',
            ['user_id' => $userId],
        );

        $out = [];
        foreach ($rows as $row) {
            $section = (string) ($row['section'] ?? '');
            if (UserAclPolicy::isValidSection($section)) {
                $out[] = $section;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $sections
     */
    public function replace(int $userId, array $sections): void
    {
        $this->db->execute('DELETE FROM cms_user_section_grants WHERE user_id = :user_id', ['user_id' => $userId]);
        $seen = [];
        foreach ($sections as $section) {
            if (!\is_string($section) || !UserAclPolicy::isValidSection($section)) {
                continue;
            }
            if (isset($seen[$section])) {
                continue;
            }
            $seen[$section] = true;
            $this->db->execute(
                'INSERT INTO cms_user_section_grants (user_id, section) VALUES (:user_id, :section)',
                ['user_id' => $userId, 'section' => $section],
            );
        }
    }
}
