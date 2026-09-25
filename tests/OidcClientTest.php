<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Auth\OAuthException;
use Cms\Auth\OidcClient;
use Cms\Http\HttpClient;
use PHPUnit\Framework\TestCase;

final class OidcClientTest extends TestCase
{
    public function testGooglePresetHasEndpoints(): void
    {
        $preset = OidcClient::googlePreset();
        $this->assertStringContainsString('accounts.google.com', $preset['authorizationEndpoint']);
        $this->assertNotSame('', $preset['tokenEndpoint']);
        $this->assertNotSame('', $preset['userinfoEndpoint']);
    }

    public function testAuthorizeUrlBuildsQuery(): void
    {
        $client = new OidcClient(new class () implements HttpClient {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                unset($method, $url, $headers, $body);

                return ['status' => 500, 'body' => ''];
            }
        });
        $url = $client->authorizeUrl([
            'clientId' => 'cid',
            'clientSecret' => 'sec',
            'scopes' => 'openid email',
            'claimEmail' => 'email',
            'claimSub' => 'sub',
            'authorizationEndpoint' => 'https://idp.example/auth',
            'tokenEndpoint' => 'https://idp.example/token',
            'userinfoEndpoint' => 'https://idp.example/userinfo',
            'issuer' => 'https://idp.example',
        ], 'https://app.example/callback', 'state-token');

        $this->assertStringStartsWith('https://idp.example/auth?', $url);
        $this->assertStringContainsString('client_id=cid', $url);
        $this->assertStringContainsString('state=state-token', $url);
        $this->assertStringContainsString('scope=openid', $url);
    }

    public function testResolveEndpointsUsesExplicitWhenComplete(): void
    {
        $client = new OidcClient(new class () implements HttpClient {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                unset($method, $url, $headers, $body);

                return ['status' => 500, 'body' => 'should not discover'];
            }
        });
        $endpoints = $client->resolveEndpoints([
            'issuer' => 'https://idp.example',
            'authorizationEndpoint' => 'https://idp.example/a',
            'tokenEndpoint' => 'https://idp.example/t',
            'userinfoEndpoint' => 'https://idp.example/u',
        ]);
        $this->assertSame('https://idp.example/a', $endpoints['authorizationEndpoint']);
    }

    public function testDiscoverParsesWellKnown(): void
    {
        $http = new class () implements HttpClient {
            public string $seenUrl = '';

            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                unset($method, $headers, $body);
                $this->seenUrl = $url;

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'authorization_endpoint' => 'https://idp.example/auth',
                        'token_endpoint' => 'https://idp.example/token',
                        'userinfo_endpoint' => 'https://idp.example/userinfo',
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        };
        $client = new OidcClient($http);
        $endpoints = $client->discover('https://idp.example/');
        $this->assertStringContainsString('/.well-known/openid-configuration', $http->seenUrl);
        $this->assertSame('https://idp.example/auth', $endpoints['authorizationEndpoint']);
        $this->assertSame('https://idp.example', $endpoints['issuer']);
    }

    public function testExchangeCodeReadsUserinfoClaims(): void
    {
        $client = new OidcClient(new class () implements HttpClient {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                if (str_contains($url, '/token')) {
                    return [
                        'status' => 200,
                        'body' => json_encode(['access_token' => 'atok'], JSON_THROW_ON_ERROR),
                    ];
                }

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'sub' => 'user-1',
                        'email' => 'Ada@Example.com',
                        'email_verified' => true,
                        'name' => 'Ada',
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        });
        $profile = $client->exchangeCode([
            'clientId' => 'cid',
            'clientSecret' => 'sec',
            'scopes' => 'openid email',
            'claimEmail' => 'email',
            'claimSub' => 'sub',
            'authorizationEndpoint' => 'https://idp.example/auth',
            'tokenEndpoint' => 'https://idp.example/token',
            'userinfoEndpoint' => 'https://idp.example/userinfo',
            'issuer' => 'https://idp.example',
        ], 'code', 'https://app/callback');

        $this->assertSame('user-1', $profile['id']);
        $this->assertSame('ada@example.com', $profile['email']);
        $this->assertTrue($profile['emailVerified']);
    }

    public function testDiscoverRejectsBadIssuer(): void
    {
        $client = new OidcClient(new class () implements HttpClient {
            public function request(string $method, string $url, array $headers = [], ?string $body = null): array
            {
                unset($method, $url, $headers, $body);

                return ['status' => 500, 'body' => ''];
            }
        });
        $this->expectException(OAuthException::class);
        $client->discover('not-a-url');
    }
}
