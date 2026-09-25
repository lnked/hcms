<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Auth\RateLimiter;
use Cms\Auth\RateLimitStore;
use Cms\Http\Request;

/**
 * Per-resource spam checks for anonymous public writes (create / update / delete).
 */
final class SpamGuard
{
    public function __construct(
        private readonly ?CaptchaVerifier $captcha = null,
        private readonly ?RateLimitStore $rateLimitStore = null,
    ) {
    }

    /**
     * @param string $action create|update|delete
     * @param array<string, mixed> $settings resource settings
     * @param array<string, mixed> $payload
     * @throws SpamRejected failed spam check
     * @throws RateLimitExceeded too many submissions from this IP
     */
    public function assertWriteAllowed(
        Request $request,
        string $slug,
        array $settings,
        array &$payload,
        string $action = 'create',
    ): void {
        $spam = \is_array($settings['spam'] ?? null) ? $settings['spam'] : [];

        $perMin = max(0, (int) ($spam['rateLimitPerMinute'] ?? 0));
        if ($perMin > 0 && $this->rateLimitStore !== null) {
            $limiter = new RateLimiter($this->rateLimitStore, 60, $perMin);
            $bucket = 'spam:write:' . $slug . ':' . $request->ip;
            if (!$limiter->hit($bucket)) {
                throw new RateLimitExceeded($limiter->retryAfter($bucket), $perMin);
            }
        }

        $honeypot = \is_string($spam['honeypotField'] ?? null) ? trim($spam['honeypotField']) : '';
        if ($honeypot !== '' && \array_key_exists($honeypot, $payload)) {
            $bait = $payload[$honeypot];
            $filled = \is_string($bait) ? trim($bait) !== '' : ($bait !== null && $bait !== false && $bait !== 0 && $bait !== 0.0);
            if ($filled) {
                throw new SpamRejected('honeypot');
            }
        }

        $minMs = isset($spam['minSubmitMs']) ? (int) $spam['minSubmitMs'] : 0;
        if ($minMs > 0 && $payload !== []) {
            $started = $payload['_startedAt'] ?? $request->header('x-form-started-at');
            if (is_numeric($started)) {
                $elapsed = (int) (round(microtime(true) * 1000) - (int) $started);
                if ($elapsed >= 0 && $elapsed < $minMs) {
                    throw new SpamRejected('too_fast');
                }
            }
        }

        $requireCaptcha = (bool) ($spam['requireCaptcha'] ?? false);
        if ($requireCaptcha) {
            $token = null;
            if (isset($payload['captchaToken']) && \is_string($payload['captchaToken'])) {
                $token = $payload['captchaToken'];
            } elseif ($request->header('x-captcha-token') !== null) {
                $token = $request->header('x-captcha-token');
            }
            if ($this->captcha === null || !$this->captcha->isConfigured()) {
                throw new SpamRejected('captcha', 'Captcha verification failed');
            }
            if ($token === null || !$this->captcha->verify($token, $request->ip)) {
                throw new SpamRejected('captcha', 'Captcha verification failed');
            }
        }

        $maxLinks = isset($spam['maxLinks']) ? (int) $spam['maxLinks'] : 0;
        if ($maxLinks > 0 && $payload !== []) {
            $text = $this->flattenText($payload);
            $count = preg_match_all('#https?://#i', $text) ?: 0;
            if ($count > $maxLinks) {
                throw new SpamRejected('links');
            }
        }

        $blocklist = \is_array($spam['blocklist'] ?? null) ? $spam['blocklist'] : [];
        if ($blocklist !== [] && $payload !== []) {
            $text = mb_strtolower($this->flattenText($payload));
            foreach ($blocklist as $term) {
                if (!\is_string($term) || trim($term) === '') {
                    continue;
                }
                if (str_contains($text, mb_strtolower(trim($term)))) {
                    throw new SpamRejected('blocklist');
                }
            }
        }

        if (
            $action !== 'delete'
            && $this->rateLimitStore !== null
            && (bool) ($spam['rejectDuplicates'] ?? true)
            && $payload !== []
        ) {
            $dupLimiter = new RateLimiter($this->rateLimitStore, 600, 1);
            $hash = hash(
                'sha256',
                $slug . '|' . $request->ip . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
            if (!$dupLimiter->hit('spam:dup:' . $hash)) {
                throw new SpamRejected('duplicate');
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $payload
     * @deprecated use assertWriteAllowed
     */
    public function assertCreateAllowed(Request $request, string $slug, array $settings, array $payload): void
    {
        $this->assertWriteAllowed($request, $slug, $settings, $payload, 'create');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function flattenText(array $payload): string
    {
        $parts = [];
        array_walk_recursive($payload, static function (mixed $value) use (&$parts): void {
            if (\is_string($value) || is_numeric($value)) {
                $parts[] = (string) $value;
            }
        });

        return implode(' ', $parts);
    }
}
