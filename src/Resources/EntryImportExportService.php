<?php

declare(strict_types=1);

namespace Cms\Resources;

use Cms\Api\QueryEngine;
use InvalidArgumentException;
use Throwable;

final class EntryImportExportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;

    private const SYSTEM_FIELDS = ['id', 'createdAt', 'updatedAt', 'created_at', 'updated_at'];

    public function __construct(
        private readonly QueryEngine $query,
    ) {
    }

    /**
     * @param list<string>|null $fields
     * @return array{body: string, contentType: string, filename: string}
     */
    public function export(string $slug, string $format, ?array $fields = null): array
    {
        $format = $this->normalizeFormat($format);
        $rows = $this->query->listAll($slug);
        $columns = $this->resolveExportColumns($rows, $fields);
        $projected = array_map(
            fn (array $row): array => $this->projectRow($row, $columns),
            $rows,
        );

        if ($format === 'json') {
            $body = json_encode($projected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($body === false) {
                throw new InvalidArgumentException('Failed to encode JSON export');
            }

            return [
                'body' => $body . "\n",
                'contentType' => 'application/json; charset=utf-8',
                'filename' => $slug . '-entries.json',
            ];
        }

        return [
            'body' => $this->encodeCsv($projected, $columns),
            'contentType' => 'text/csv; charset=utf-8',
            'filename' => $slug . '-entries.csv',
        ];
    }

    /**
     * @return array{created: int, failed: int, errors: list<array{row: int, message: string}>}
     */
    public function import(string $slug, string $format, string $content): array
    {
        $format = $this->normalizeFormat($format);
        if (\strlen($content) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Import payload exceeds 5MB limit');
        }

        $rows = $format === 'json' ? $this->parseJson($content) : $this->parseCsv($content);
        if (\count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Import exceeds ' . self::MAX_ROWS . ' rows limit');
        }

        $created = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            try {
                $payload = $this->prepareImportRow($row);
                $this->query->create($slug, $payload);
                $created++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'created' => $created,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     */
    public function encodeCsv(array $rows, array $columns): string
    {
        $lines = [$this->csvLine($columns)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->csvCell($row[$column] ?? null);
            }
            $lines[] = $this->csvLine($cells);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseCsv(string $content): array
    {
        $content = $this->stripBom($content);
        if (trim($content) === '') {
            return [];
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidArgumentException('Failed to parse CSV');
        }
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false || $header === [null]) {
            fclose($handle);

            throw new InvalidArgumentException('CSV must include a header row');
        }

        $columns = array_map(
            static fn (mixed $col): string => trim((string) $col),
            $header,
        );
        if (\in_array('', $columns, true) || \count($columns) !== \count(array_unique($columns))) {
            fclose($handle);

            throw new InvalidArgumentException('CSV header has empty or duplicate columns');
        }

        $rows = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($cells === [null] || $this->csvRowEmpty($cells)) {
                continue;
            }
            $row = [];
            foreach ($columns as $i => $name) {
                $row[$name] = \array_key_exists($i, $cells) ? $cells[$i] : null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseJson(string $content): array
    {
        $content = $this->stripBom($content);
        if (trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!\is_array($decoded)) {
            throw new InvalidArgumentException('Invalid JSON import payload');
        }

        if (array_is_list($decoded)) {
            $rows = $decoded;
        } elseif (isset($decoded['data']) && \is_array($decoded['data']) && array_is_list($decoded['data'])) {
            $rows = $decoded['data'];
        } else {
            throw new InvalidArgumentException('JSON import must be an array of objects or { "data": [...] }');
        }

        $out = [];
        foreach ($rows as $i => $row) {
            if (!\is_array($row)) {
                throw new InvalidArgumentException('JSON row ' . ($i + 1) . ' must be an object');
            }
            /** @var array<string, mixed> $row */
            $out[] = $row;
        }

        return $out;
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if ($format !== 'csv' && $format !== 'json') {
            throw new InvalidArgumentException('format must be csv or json');
        }

        return $format;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null $fields
     * @return list<string>
     */
    private function resolveExportColumns(array $rows, ?array $fields): array
    {
        if ($fields !== null && $fields !== []) {
            $columns = [];
            foreach ($fields as $field) {
                $name = trim($field);
                if ($name !== '') {
                    $columns[] = $name;
                }
            }
            if ($columns === []) {
                throw new InvalidArgumentException('fields must list at least one column');
            }

            return array_values(array_unique($columns));
        }

        if ($rows === []) {
            return ['id', 'createdAt', 'updatedAt'];
        }

        return array_keys($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private function projectRow(array $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            $out[$column] = $row[$column] ?? null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function prepareImportRow(array $row): array
    {
        $payload = [];
        foreach ($row as $key => $value) {
            $name = (string) $key;
            if (\in_array($name, self::SYSTEM_FIELDS, true)) {
                continue;
            }
            if ($value === '' || $value === null) {
                $payload[$name] = null;
                continue;
            }
            if (\is_string($value)) {
                $trimmed = trim($value);
                if (
                    ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '['))
                    && json_validate($trimmed)
                ) {
                    $decoded = json_decode($trimmed, true);
                    $payload[$name] = $decoded;
                    continue;
                }
            }
            $payload[$name] = $value;
        }

        return $payload;
    }

    /**
     * @param list<string> $cells
     */
    private function csvLine(array $cells): string
    {
        return implode(',', array_map(fn (string $cell): string => $this->escapeCsv($cell), $cells));
    }

    private function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, '"') || str_contains($value, ',') || str_contains($value, "\n") || str_contains($value, "\r")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    /**
     * @param list<string|null> $cells
     */
    private function csvRowEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }
}
