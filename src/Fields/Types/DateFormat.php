<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

use InvalidArgumentException;

/**
 * Display pattern for date/datetime fields, using moment-style tokens.
 */
final class DateFormat
{
    private const MAX_LENGTH = 32;

    /** Ordered so that longer tokens win over their prefixes. */
    private const TOKENS = ['YYYY', 'YY', 'MM', 'DD', 'HH', 'mm', 'ss'];

    private const SEPARATORS = ['.', '-', '/', ':', ' ', ',', 'T'];

    public static function assertValid(mixed $format): void
    {
        if (!is_string($format) || trim($format) === '') {
            throw new InvalidArgumentException('Date format must be a non-empty string');
        }
        if (mb_strlen($format) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Date format must not exceed ' . self::MAX_LENGTH . ' characters');
        }

        $rest = $format;
        $hasToken = false;
        while ($rest !== '') {
            $matched = null;
            foreach (self::TOKENS as $token) {
                if (str_starts_with($rest, $token)) {
                    $matched = $token;
                    break;
                }
            }
            if ($matched !== null) {
                $hasToken = true;
                $rest = substr($rest, strlen($matched));

                continue;
            }
            if (!in_array($rest[0], self::SEPARATORS, true)) {
                throw new InvalidArgumentException('Unsupported date format: ' . $format);
            }
            $rest = substr($rest, 1);
        }

        if (!$hasToken) {
            throw new InvalidArgumentException('Date format must contain at least one token');
        }
    }
}
