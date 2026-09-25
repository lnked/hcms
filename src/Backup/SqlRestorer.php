<?php

declare(strict_types=1);

namespace Cms\Backup;

use Cms\Database\Connection;
use RuntimeException;

final class SqlRestorer
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function restoreFromFile(string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('SQL dump not found: ' . $path);
        }
        $sql = file_get_contents($path);
        if ($sql === false || $sql === '') {
            throw new RuntimeException('Empty SQL dump: ' . $path);
        }

        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->splitStatements($sql) as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $len = \strlen($sql);

        for ($i = 0; $i < $len; ++$i) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if (!$inSingle && !$inDouble && !$inBacktick && $ch === '-' && $next === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    ++$i;
                }
                continue;
            }

            if ($ch === "'" && !$inDouble && !$inBacktick) {
                if ($inSingle && $next === "'") {
                    $buffer .= "''";
                    ++$i;
                    continue;
                }
                $inSingle = !$inSingle;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trim = trim($buffer);
                if ($trim !== '') {
                    $statements[] = $trim;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $trim = trim($buffer);
        if ($trim !== '') {
            $statements[] = $trim;
        }

        return $statements;
    }
}
