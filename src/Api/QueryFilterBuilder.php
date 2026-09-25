<?php

declare(strict_types=1);

namespace Cms\Api;

use InvalidArgumentException;

/**
 * Builds WHERE/ORDER SQL fragments for list queries.
 * Extracted from QueryEngine; default locale for feature filters is passed in.
 */
final class QueryFilterBuilder
{
    /**
     * @param array<string, string> $query
     */
    public function hasFilterParams(array $query): bool
    {
        foreach ($query as $key => $_) {
            if (str_starts_with($key, 'filter[')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, array<string, mixed>> $fieldMap
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    public function applyFilters(array $query, array $fieldMap, array &$where, array &$params): void
    {
        foreach ($query as $key => $value) {
            if (!str_starts_with($key, 'filter[') || !str_ends_with($key, ']')) {
                continue;
            }
            $inner = substr($key, 7, -1);
            if (str_contains($inner, '][')) {
                [$field, $op] = explode('][', $inner, 2);
            } else {
                $field = $inner;
                $op = 'eq';
            }
            if (!isset($fieldMap[$field]) || !($fieldMap[$field]['spec']['filterable'] ?? false)) {
                throw new InvalidArgumentException('Field not filterable: ' . $field);
            }
            $param = 'f_' . \count($params);
            match ($op) {
                'eq' => [$where[], $params[$param]] = ['`' . $field . '` = :' . $param, $value],
                'neq' => [$where[], $params[$param]] = ['`' . $field . '` <> :' . $param, $value],
                'gt' => [$where[], $params[$param]] = ['`' . $field . '` > :' . $param, $value],
                'gte' => [$where[], $params[$param]] = ['`' . $field . '` >= :' . $param, $value],
                'lt' => [$where[], $params[$param]] = ['`' . $field . '` < :' . $param, $value],
                'lte' => [$where[], $params[$param]] = ['`' . $field . '` <= :' . $param, $value],
                'contains' => [$where[], $params[$param]] = ['`' . $field . '` LIKE :' . $param, '%' . $value . '%'],
                'startsWith' => [$where[], $params[$param]] = ['`' . $field . '` LIKE :' . $param, $value . '%'],
                'endsWith' => [$where[], $params[$param]] = ['`' . $field . '` LIKE :' . $param, '%' . $value],
                'in' => $this->applyIn($field, $value, $where, $params),
                default => throw new InvalidArgumentException('Unknown filter operator: ' . $op),
            };
        }
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    public function applyIn(string $field, string $value, array &$where, array &$params): void
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
        if ($parts === []) {
            throw new InvalidArgumentException('Empty in filter');
        }
        $placeholders = [];
        foreach ($parts as $i => $part) {
            $param = 'in_' . \count($params) . '_' . $i;
            $placeholders[] = ':' . $param;
            $params[$param] = $part;
        }
        $where[] = '`' . $field . '` IN (' . implode(', ', $placeholders) . ')';
    }

    /**
     * Tokenized OR search across searchable fields; returns relevance score SQL (or null).
     *
     * @param array<string, string> $query
     * @param array<string, array<string, mixed>> $fieldMap
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    public function applySearch(array $query, array $fieldMap, array &$where, array &$params): ?string
    {
        $search = trim((string) ($query['search'] ?? ''));
        if ($search === '') {
            return null;
        }

        $fields = [];
        foreach ($fieldMap as $name => $meta) {
            if ($meta['spec']['searchable'] ?? false) {
                $fields[] = $name;
            }
        }
        if ($fields === []) {
            return null;
        }

        $tokens = SearchTokenizer::tokens($search);
        if ($tokens === []) {
            $tokens = [mb_strtolower($search, 'UTF-8')];
        }

        $tokenMatches = [];
        foreach ($tokens as $token) {
            $fieldParts = [];
            foreach ($fields as $name) {
                $param = 's_' . \count($params);
                $fieldParts[] = '`' . $name . '` LIKE :' . $param . " ESCAPE '\\\\'";
                $params[$param] = $this->likeContains($token);
            }
            $tokenMatches[] = '(' . implode(' OR ', $fieldParts) . ')';
        }

        $where[] = '(' . implode(' OR ', $tokenMatches) . ')';

        $scoreParts = [];
        foreach ($tokenMatches as $matchSql) {
            $scoreParts[] = '(CASE WHEN ' . $matchSql . ' THEN 1 ELSE 0 END)';
        }

        return '(' . implode(' + ', $scoreParts) . ')';
    }

    public function likeContains(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return '%' . $escaped . '%';
    }

    /**
     * @param array<string, string> $query
     * @param array<string, array<string, mixed>> $fieldMap
     */
    public function orderSql(array $query, array $fieldMap, ?string $searchScoreSql = null): string
    {
        $sort = $query['sort'] ?? 'id';
        $desc = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');
        if ($field === 'id') {
            return $this->orderSqlWithSearchScore('`id` ' . ($desc ? 'DESC' : 'ASC'), $searchScoreSql);
        }
        if (!isset($fieldMap[$field]) || !($fieldMap[$field]['spec']['sortable'] ?? false)) {
            throw new InvalidArgumentException('Field not sortable: ' . $field);
        }

        return $this->orderSqlWithSearchScore(
            '`' . $field . '` ' . ($desc ? 'DESC' : 'ASC'),
            $searchScoreSql,
        );
    }

    public function orderSqlWithSearchScore(string $secondaryOrder, ?string $searchScoreSql): string
    {
        if ($searchScoreSql === null) {
            return ' ORDER BY ' . $secondaryOrder;
        }

        return ' ORDER BY ' . $searchScoreSql . ' DESC, ' . $secondaryOrder;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $settings
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    public function applyFeatureFilters(
        array $query,
        array $settings,
        bool $public,
        array &$where,
        array &$params,
        string $defaultLocale = 'en',
    ): void {
        $workflow = \is_array($settings['workflow'] ?? null) ? $settings['workflow'] : [];
        if ($public && (bool) ($workflow['enabled'] ?? false)) {
            $where[] = '`status` = :wf_status';
            $params['wf_status'] = 'published';
        }

        $localization = \is_array($settings['localization'] ?? null) ? $settings['localization'] : [];
        if ((bool) ($localization['enabled'] ?? false)) {
            $locale = isset($query['locale']) && \is_string($query['locale']) ? trim($query['locale']) : '';
            if ($locale !== '') {
                $where[] = '`locale` = :feat_locale';
                $params['feat_locale'] = $locale;
            } elseif ($public) {
                $where[] = '`locale` = :feat_locale';
                $params['feat_locale'] = $defaultLocale;
            }
        }
    }
}
