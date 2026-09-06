<?php

declare(strict_types=1);

namespace Cms\Install;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $fields
     */
    public function __construct(
        private readonly array $fields,
        string $message = 'Validation failed',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
