<?php

declare(strict_types=1);

namespace Cms\Auth;

use Cms\Http\HttpClient;

/**
 * Thin OpenID Connect Authorization Code client (discovery + token + userinfo).
 *
 * @phpstan-type OidcEndpoints array{
 *   authorizationEndpoint: string,
 *   tokenEndpoint: string,
 *   userinfoEndpoint: string,
 *   issuer: string
 * }
 * @phpstan-type OidcClientConfig array{
 *   clientId: string,
 *   clientSecret: string,
 *   scopes: string,
 *   claimEmail: string,
 *   claimSub: string,
 *   authorizationEndpoint: string,
 *   tokenEndpoint: string,
 *   userinfoEndpoint: string,
 *   issuer: string
 * }
 */
final class OidcClient
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Google OAuth/OIDC endpoints (preset; no discovery round-trip).
     *
     * @return OidcEndpoints
     */
    public static function googlePreset(): array
    {
        return [
            'issuer' => 'https://accounts.google.com',
            'authorizationEndpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'tokenEndpoint' => 'https://oauth2.googleapis.com/token',
            'userinfoEndpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ];
    }

    /**
     * @return OidcEndpoints
     */
    public function discover(string $issuer): array
    {
        $issuer = rtrim(trim($issuer), '/');
        if ($issuer === '' || !preg_match('#^https?://#i', $issuer)) {
            throw new OAuthException('VALIDATION_ERROR', 'OIDC issuer must be an http(s) URL', 422);
        }
        $url = $issuer . '/.well-known/openid-configuration';
        $response = $this->http->request('GET', $url, ['Accept' => 'application/json']);
        $json = $this->decodeJson($response['body']);
        if ($response['status'] >= 400) {
            throw new OAuthException('PROVIDER_ERROR', 'OIDC discovery failed', 502);
        }
        $auth = isset($json['authorization_endpoint']) && \is_string($json['authorization_endpoint'])
            ? $json['authorization_endpoint']
            : '';
        $token = isset($json['token_endpoint']) && \is_string($json['token_endpoint'])
            ? $json['token_endpoint']
            : '';
        $userinfo = isset($json['userinfo_endpoint']) && \is_string($json['userinfo_endpoint'])
            ? $json['userinfo_endpoint']
            : '';
        if ($auth === '' || $token === '' || $userinfo === '') {
            throw new OAuthException(
                'PROVIDER_ERROR',
                'OIDC discovery missing authorization/token/userinfo endpoints',
                502,
            );
        }

        return [
            'issuer' => $issuer,
            'authorizationEndpoint' => $auth,
            'tokenEndpoint' => $token,
            'userinfoEndpoint' => $userinfo,
        ];
    }

    /**
     * Resolve endpoints from explicit config or issuer discovery.
     *
     * @param array{
     *   issuer?: string,
     *   authorizationEndpoint?: string,
     *   tokenEndpoint?: string,
     *   userinfoEndpoint?: string
     * } $partial
     * @return OidcEndpoints
     */
    public function resolveEndpoints(array $partial): array
    {
        $auth = isset($partial['authorizationEndpoint']) && \is_string($partial['authorizationEndpoint'])
            ? trim($partial['authorizationEndpoint'])
            : '';
        $token = isset($partial['tokenEndpoint']) && \is_string($partial['tokenEndpoint'])
            ? trim($partial['tokenEndpoint'])
            : '';
        $userinfo = isset($partial['userinfoEndpoint']) && \is_string($partial['userinfoEndpoint'])
            ? trim($partial['userinfoEndpoint'])
            : '';
        $issuer = isset($partial['issuer']) && \is_string($partial['issuer'])
            ? rtrim(trim($partial['issuer']), '/')
            : '';

        if ($auth !== '' && $token !== '' && $userinfo !== '') {
            return [
                'issuer' => $issuer,
                'authorizationEndpoint' => $auth,
                'tokenEndpoint' => $token,
                'userinfoEndpoint' => $userinfo,
            ];
        }
        if ($issuer === '') {
            throw new OAuthException(
                'VALIDATION_ERROR',
                'OIDC requires issuer or explicit authorization/token/userinfo endpoints',
                422,
            );
        }

        return $this->discover($issuer);
    }

    /**
     * @param OidcClientConfig $config
     * @param array<string, scalar> $extraQuery
     */
    public function authorizeUrl(array $config, string $redirectUri, string $state, array $extraQuery = []): string
    {
        $query = array_merge([
            'client_id' => $config['clientId'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $config['scopes'],
            'state' => $state,
        ], $extraQuery);

        return $config['authorizationEndpoint'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param OidcClientConfig $config
     * @return array{id: string, email: string, emailVerified: bool, name: string}
     */
    public function exchangeCode(array $config, string $code, string $redirectUri): array
    {
        $tokenResponse = $this->http->request(
            'POST',
            $config['tokenEndpoint'],
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            http_build_query([
                'code' => $code,
                'client_id' => $config['clientId'],
                'client_secret' => $config['clientSecret'],
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
        );
        $tokenJson = $this->decodeJson($tokenResponse['body']);
        $accessToken = isset($tokenJson['access_token']) && \is_string($tokenJson['access_token'])
            ? $tokenJson['access_token']
            : '';
        if ($tokenResponse['status'] >= 400 || $accessToken === '') {
            throw new OAuthException('PROVIDER_ERROR', 'OIDC token exchange failed', 502);
        }

        $infoResponse = $this->http->request(
            'GET',
            $config['userinfoEndpoint'],
            ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
        );
        $info = $this->decodeJson($infoResponse['body']);
        if ($infoResponse['status'] >= 400) {
            throw new OAuthException('PROVIDER_ERROR', 'OIDC userinfo failed', 502);
        }

        $subClaim = $config['claimSub'] !== '' ? $config['claimSub'] : 'sub';
        $emailClaim = $config['claimEmail'] !== '' ? $config['claimEmail'] : 'email';
        $id = isset($info[$subClaim]) && (\is_string($info[$subClaim]) || is_numeric($info[$subClaim]))
            ? (string) $info[$subClaim]
            : '';
        $emailRaw = $info[$emailClaim] ?? '';
        $email = \is_string($emailRaw) ? strtolower(trim($emailRaw)) : '';
        $verified = (bool) ($info['email_verified'] ?? true);
        $name = isset($info['name']) && \is_string($info['name']) ? $info['name'] : $email;
        if ($id === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OAuthException('PROVIDER_ERROR', 'OIDC account has no usable email claim', 400);
        }

        return [
            'id' => $id,
            'email' => $email,
            'emailVerified' => $verified,
            'name' => $name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body): array
    {
        $decoded = json_decode($body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
