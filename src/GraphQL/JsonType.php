<?php

declare(strict_types=1);

namespace Cms\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Utils\Utils;

/**
 * Pass-through JSON scalar for media / json / blocks / free-form values.
 */
final class JsonType extends ScalarType
{
    public string $name = 'JSON';

    public ?string $description = 'Arbitrary JSON value';

    public function serialize(mixed $value): mixed
    {
        return $value;
    }

    public function parseValue(mixed $value): mixed
    {
        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null): mixed
    {
        unset($variables);

        throw new Error('JSON literal input is not supported; pass variables instead: ' . Utils::printSafeJson($valueNode));
    }
}
