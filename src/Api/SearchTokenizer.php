<?php

declare(strict_types=1);

namespace Cms\Api;

/**
 * Splits a search string into meaningful tokens for multi-word matching.
 */
final class SearchTokenizer
{
    /** @var list<string> */
    private const STOP_WORDS = [
        // EN articles / conjunctions / prepositions
        'a', 'an', 'the', 'and', 'or', 'but', 'nor', 'of', 'in', 'on', 'at', 'to', 'for',
        'from', 'by', 'with', 'as', 'into', 'about', 'over', 'after', 'before', 'between',
        'without', 'within', 'through', 'during', 'under', 'above', 'against', 'across',
        'along', 'around', 'behind', 'beside', 'beyond', 'near', 'off', 'onto', 'out',
        'up', 'down', 'via', 'per',
        // RU conjunctions / prepositions
        'а', 'и', 'или', 'но', 'да', 'же', 'ли', 'в', 'во', 'на', 'по', 'за', 'из', 'от',
        'до', 'для', 'при', 'про', 'об', 'обо', 'со', 'ко', 'над', 'под', 'перед', 'через',
        'между', 'без', 'к', 'у', 'о', 'с', 'ради', 'среди', 'возле', 'около', 'после',
        'кроме', 'вместо',
    ];

    /**
     * @return list<string>
     */
    public static function tokens(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $raw = preg_split('/[^\p{L}\p{N}]+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
        if ($raw === false || $raw === []) {
            return [];
        }

        $stop = array_fill_keys(self::STOP_WORDS, true);
        $tokens = [];
        $seen = [];
        foreach ($raw as $part) {
            $token = mb_strtolower($part, 'UTF-8');
            if ($token === '' || isset($stop[$token]) || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $tokens[] = $token;
        }

        if ($tokens !== []) {
            return $tokens;
        }

        // All tokens were stop words — keep the original parts (deduped).
        $seen = [];
        foreach ($raw as $part) {
            $token = mb_strtolower($part, 'UTF-8');
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }
}
