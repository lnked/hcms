<?php

declare(strict_types=1);

namespace Cms\Security;

use Cms\Auth\RateLimiter;
use Cms\Auth\RateLimitStore;
use Cms\Http\Request;
use InvalidArgumentException;

/**
 * Per-resource spam checks for anonymous public writes.
 */
final class SpamGuard
{
    public function __construct(
        private readonly ?CaptchaVerifier $captcha = null,
        private readonly ?RateLimitStore $rateLimitStore = null,
    ) {
    }

    /**
     * @param array<string, mixed> $settings resource settings
     * @param array<string, mixed> $payload
     * @throws InvalidArgumentException
     */
    public function assertCreateAllowed(Request $request, array $settings, array $payload): void
    {
        $spam = is_array($settings['spam'] ?? null) ? $settings['spam'] : [];

        $perMin = max(0, (int) ($spam['rateLimitPerMinute'] ?? 0));
        if ($perMin > 0 && $this->rateLimitStore !== null) {
            $limiter = new RateLimiter($this->rateLimitStore, 60, $perMin);
            if (!$limiter->hit('spam:write:' . $request->ip)) {
                throw new InvalidArgumentException('Too many submissions');
            }
        }

        $honeypot = is_string($spam['honeypotField'] ?? null) ? trim($spam['honeypotField']) : '';
        if ($honeypot !== '' && array_key_exists($honeypot, $payload)) {
            $bait = $payload[$honeypot];
            $filled = is_string($bait) ? trim($bait) !== '' : ($bait !== null && $bait !== false && $bait !== 0 && $bait !== 0.0);
            if ($filled) {
                throw new InvalidArgumentException('Spam check failed');
            }
        }

        $minMs = isset($spam['minSubmitMs']) ? (int) $spam['minSubmitMs'] : 0;
        if ($minMs > 0) {
            $started = $payload['_startedAt'] ?? $request->header('x-form-started-at');
            if (is_numeric($started)) {
                $elapsed = (int) (round(microtime(true) * 1000) - (int) $started);
                if ($elapsed >= 0 && $elapsed < $minMs) {
                    throw new InvalidArgumentException('Submission too fast');
                }
            }
        }

        $requireCaptcha = (bool) ($spam['requireCaptcha'] ?? false);
        if ($requireCaptcha) {
            $token = null;
            if (isset($payload['captchaToken']) && is_string($payload['captchaToken'])) {
                $token = $payload['captchaToken'];
            } elseif ($request->header('x-captcha-token') !== null) {
                $token = $request->header('x-captcha-token');
            }
            if ($this->captcha === null || !$this->captcha->isConfigured()) {
                throw new InvalidArgumentException('Captcha is required but not configured');
            }
            if ($token === null || !$this->captcha->verify($token, $request->ip)) {
                throw new InvalidArgumentException('Captcha verification failed');
            }
        }

        $maxLinks = isset($spam['maxLinks']) ? (int) $spam['maxLinks'] : 0;
        if ($maxLinks > 0) {
            $text = $this->flattenText($payload);
            $count = preg_match_all('#https?://#i', $text) ?: 0;
            if ($count > $maxLinks) {
                throw new InvalidArgumentException('Too many links');
            }
        }

        $blocklist = is_array($spam['blocklist'] ?? null) ? $spam['blocklist'] : [];
        if ($blocklist !== []) {
            $text = mb_strtolower($this->flattenText($payload));
            foreach ($blocklist as $term) {
                if (!is_string($term) || trim($term) === '') {
                    continue;
                }
                if (str_contains($text, mb_strtolower(trim($term)))) {
                    throw new InvalidArgumentException('Content blocked');
                }
            }
        }

        if ($this->rateLimitStore !== null && (bool) ($spam['rejectDuplicates'] ?? true)) {
            $dupLimiter = new RateLimiter($this->rateLimitStore, 600, 1);
            $hash = hash('sha256', $request->ip . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!$dupLimiter->hit('spam:dup:' . $hash)) {
                throw new InvalidArgumentException('Duplicate submission');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function flattenText(array $payload): string
    {
        $parts = [];
        array_walk_recursive($payload, static function (mixed $value) use (&$parts): void {
            if (is_string($value) || is_numeric($value)) {
                $parts[] = (string) $value;
            }
        });

        return implode(' ', $parts);
    }
}
