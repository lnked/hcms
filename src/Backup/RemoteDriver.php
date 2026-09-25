<?php

declare(strict_types=1);

namespace Cms\Backup;

use RuntimeException;

interface RemoteDriver
{
    public function id(): string;

    public function connected(): bool;

    /**
     * @throws RuntimeException
     */
    public function test(): void;

    /**
     * Upload local file to remote under destKey (relative object name).
     *
     * @throws RuntimeException
     */
    public function upload(string $localPath, string $destKey): void;

    /**
     * Download remote object to local path.
     *
     * @throws RuntimeException
     */
    public function download(string $destKey, string $localPath): void;

    /**
     * @return list<string> remote object keys
     */
    public function listKeys(): array;

    public function delete(string $destKey): void;
}
