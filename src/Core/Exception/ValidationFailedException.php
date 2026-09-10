<?php

declare(strict_types=1);

namespace Cms\Core\Exception;

final class ValidationFailedException extends HttpException
{
    /** @param array<string, list<string>>|null $fields */
    public function __construct(
        string $message = 'Validation failed',
        private readonly ?array $fields = null,
        string $errorCode = 'VALIDATION_ERROR',
    ) {
        parent::__construct($message, 422, $errorCode);
    }

    /** @return array<string, list<string>>|null */
    public function fields(): ?array
    {
        return $this->fields;
    }

    public static function field(string $field, string $message, string $summary = 'Validation failed'): self
    {
        return new self($summary, [$field => [$message]]);
    }
}
