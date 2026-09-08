<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\OAuthException;
use Cms\Auth\OAuthService;
use Cms\Auth\SocialIdentityPolicy;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase
{
    public function testTelegramHashRoundTrip(): void
    {
        $botToken = '123456:ABC-DEF';
        $payload = [
            'id' => 42,
            'first_name' => 'Ada',
            'username' => 'ada',
            'auth_date' => time(),
        ];
        $payload['hash'] = OAuthService::telegramHash($payload, $botToken);

        $this->assertTrue(OAuthService::verifyTelegramAuth($payload, $botToken));
        $payload['hash'] = str_repeat('a', 64);
        $this->assertFalse(OAuthService::verifyTelegramAuth($payload, $botToken));
    }

    public function testTelegramRejectsStaleAuthDate(): void
    {
        $botToken = '123456:ABC-DEF';
        $payload = [
            'id' => 1,
            'auth_date' => time() - 90_000,
        ];
        $payload['hash'] = OAuthService::telegramHash($payload, $botToken);

        $this->assertFalse(OAuthService::verifyTelegramAuth($payload, $botToken));
    }

    public function testStateRoundTripAndRejectsTamper(): void
    {
        $secret = 'app-secret';
        $state = OAuthService::signState(['intent' => 'link', 'userId' => 7], $secret);
        $decoded = OAuthService::parseState($state, $secret);

        $this->assertSame('link', $decoded['intent']);
        $this->assertSame(7, $decoded['userId']);

        $this->expectException(OAuthException::class);
        OAuthService::parseState($state . 'x', $secret);
    }

    public function testGoogleLoginPrefersIdentityThenVerifiedEmail(): void
    {
        $identity = ['id' => 1, 'email' => 'ada@example.com'];
        $emailUser = ['id' => 2, 'email' => 'ada@example.com'];

        $this->assertSame($identity, SocialIdentityPolicy::googleLoginUser($identity, $emailUser, true));
        $this->assertSame($emailUser, SocialIdentityPolicy::googleLoginUser(null, $emailUser, true));
        $this->assertNull(SocialIdentityPolicy::googleLoginUser(null, $emailUser, false));
        $this->assertNull(SocialIdentityPolicy::googleLoginUser(null, null, true));
    }

    public function testTelegramLoginRequiresIdentity(): void
    {
        $this->assertNull(SocialIdentityPolicy::telegramLoginUser(null));
        $user = ['id' => 3];
        $this->assertSame($user, SocialIdentityPolicy::telegramLoginUser($user));
    }

    public function testCannotLinkIdentityOwnedBySomeoneElse(): void
    {
        SocialIdentityPolicy::assertCanLink(null, 1);
        SocialIdentityPolicy::assertCanLink(['user_id' => 1], 1);

        $this->expectException(OAuthException::class);
        SocialIdentityPolicy::assertCanLink(['user_id' => 2], 1);
    }
}
