<?php

declare(strict_types=1);

namespace Cms\Backup;

use RuntimeException;

final class SftpDriver implements RemoteDriver
{
    /**
     * @param array{
     *   enabled: bool,
     *   host: string,
     *   port: int,
     *   username: string,
     *   auth: string,
     *   password: string,
     *   privateKey: string,
     *   passphrase: string,
     *   remotePath: string,
     *   insecureHostKey: bool,
     *   timeoutSec: int
     * } $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $tmpDir,
    ) {
    }

    public function id(): string
    {
        return 'sftp';
    }

    public function connected(): bool
    {
        if (!$this->config['enabled'] || $this->config['host'] === '' || $this->config['username'] === '') {
            return false;
        }
        if ($this->config['auth'] === 'privateKey') {
            return $this->config['privateKey'] !== '';
        }

        return $this->config['password'] !== '';
    }

    public function test(): void
    {
        $this->assertCapable();
        $probe = $this->tmpDir . '/sftp-probe-' . bin2hex(random_bytes(4)) . '.txt';
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0755, true) && !is_dir($this->tmpDir)) {
            throw new RuntimeException('Cannot create temp dir for SFTP probe');
        }
        file_put_contents($probe, 'hcms-sftp-ok');
        try {
            $this->upload($probe, '.hcms-sftp-probe');
            $this->delete('.hcms-sftp-probe');
        } finally {
            @unlink($probe);
        }
    }

    public function upload(string $localPath, string $destKey): void
    {
        $this->assertCapable();
        if (!is_file($localPath)) {
            throw new RuntimeException('Local file missing for SFTP upload');
        }

        $remote = $this->remoteUrl($destKey);
        $handle = $this->initCurl($remote);
        $fp = fopen($localPath, 'rb');
        if ($fp === false) {
            curl_close($handle);

            throw new RuntimeException('Cannot open local file for SFTP upload');
        }
        $size = filesize($localPath);
        curl_setopt_array($handle, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $size === false ? 0 : $size,
        ]);
        $this->exec($handle, 'SFTP upload failed');
        fclose($fp);
    }

    public function download(string $destKey, string $localPath): void
    {
        $this->assertCapable();
        $dir = \dirname($localPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create download dir');
        }
        $fp = fopen($localPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Cannot write local file for SFTP download');
        }
        $handle = $this->initCurl($this->remoteUrl($destKey));
        curl_setopt($handle, CURLOPT_FILE, $fp);
        try {
            $this->exec($handle, 'SFTP download failed');
        } catch (RuntimeException $e) {
            fclose($fp);
            @unlink($localPath);

            throw $e;
        }
        fclose($fp);
    }

    public function listKeys(): array
    {
        // Listing via curl SFTP is unreliable across builds; return empty.
        return [];
    }

    public function delete(string $destKey): void
    {
        $this->assertCapable();
        $handle = $this->initCurl($this->remoteUrl($destKey));
        // libcurl has no DELETE for sftp; quote RM when supported
        curl_setopt($handle, CURLOPT_QUOTE, ['rm ' . $this->remotePath($destKey)]);
        curl_setopt($handle, CURLOPT_NOBODY, true);
        try {
            $this->exec($handle, 'SFTP delete failed');
        } catch (RuntimeException) {
            // ignore — probe delete is best-effort
        }
    }

    private function assertCapable(): void
    {
        if (!$this->connected()) {
            throw new RuntimeException('SFTP is not configured');
        }
        $version = curl_version();
        $protocols = \is_array($version) && isset($version['protocols']) && \is_array($version['protocols'])
            ? $version['protocols']
            : [];
        if (!\in_array('sftp', $protocols, true)) {
            throw new RuntimeException(
                'PHP curl was built without SFTP (libssh2). Enable curl+libssh2 or use a cloud provider.',
            );
        }
    }

    /**
     * @return \CurlHandle
     */
    private function initCurl(string $url)
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Failed to init SFTP client');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeoutSec'],
            CURLOPT_PROTOCOLS => CURLPROTO_SFTP,
            CURLOPT_USERNAME => $this->config['username'],
        ];

        if ($this->config['auth'] === 'privateKey') {
            $keyFile = $this->writeTempKey();
            $options[CURLOPT_SSH_PRIVATE_KEYFILE] = $keyFile;
            $options[CURLOPT_SSH_AUTH_TYPES] = CURLSSH_AUTH_PUBLICKEY;
            if ($this->config['passphrase'] !== '') {
                $options[CURLOPT_KEYPASSWD] = $this->config['passphrase'];
            }
        } else {
            $options[CURLOPT_PASSWORD] = $this->config['password'];
            $options[CURLOPT_SSH_AUTH_TYPES] = CURLSSH_AUTH_PASSWORD;
        }

        if ($this->config['insecureHostKey']) {
            // Skip host key verification when operator opts in (shared hosting often lacks known_hosts).
            $options[CURLOPT_SSH_KNOWNHOSTS] = '';
            if (\defined('CURLOPT_SSH_HOST_PUBLIC_KEY_MD5')) {
                $options[CURLOPT_SSH_HOST_PUBLIC_KEY_MD5] = '';
            }
        }

        curl_setopt_array($handle, $options);

        return $handle;
    }

    /**
     * @param \CurlHandle $handle
     */
    private function exec($handle, string $fallback): void
    {
        $ok = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        $this->cleanupTempKeys();
        if ($ok === false) {
            throw new RuntimeException($error !== '' ? $error : $fallback);
        }
    }

    private function remoteUrl(string $destKey): string
    {
        $path = $this->remotePath($destKey);

        return \sprintf(
            'sftp://%s:%d%s',
            $this->config['host'],
            $this->config['port'],
            $path,
        );
    }

    private function remotePath(string $destKey): string
    {
        $base = rtrim($this->config['remotePath'], '/');
        $key = ltrim(str_replace(['..', '\\'], '', $destKey), '/');

        return $base . '/' . $key;
    }

    private function writeTempKey(): string
    {
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0755, true) && !is_dir($this->tmpDir)) {
            throw new RuntimeException('Cannot create temp dir for SFTP key');
        }
        $path = $this->tmpDir . '/sftp-key-' . bin2hex(random_bytes(8));
        if (file_put_contents($path, $this->config['privateKey']) === false) {
            throw new RuntimeException('Cannot write temp SFTP key');
        }
        chmod($path, 0600);

        return $path;
    }

    private function cleanupTempKeys(): void
    {
        foreach (glob($this->tmpDir . '/sftp-key-*') ?: [] as $file) {
            @unlink($file);
        }
    }
}
