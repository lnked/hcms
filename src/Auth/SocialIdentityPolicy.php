<?php

declare(strict_types=1);

namespace Cms\Auth;

final class SocialIdentityPolicy
{
    /**
     * Google / OIDC: identity wins, otherwise match existing CMS user by verified email.
     *
     * @param array<string, mixed>|null $identityUser
     * @param array<string, mixed>|null $emailUser
     * @return array<string, mixed>|null
     */
    public static function emailVerifiedLoginUser(?array $identityUser, ?array $emailUser, bool $emailVerified): ?array
    {
        if ($identityUser !== null) {
            return $identityUser;
        }
        if (!$emailVerified) {
            return null;
        }

        return $emailUser;
    }

    /**
     * @param array<string, mixed>|null $identityUser
     * @param array<string, mixed>|null $emailUser
     * @return array<string, mixed>|null
     */
    public static function googleLoginUser(?array $identityUser, ?array $emailUser, bool $emailVerified): ?array
    {
        return self::emailVerifiedLoginUser($identityUser, $emailUser, $emailVerified);
    }

    /**
     * @param array<string, mixed>|null $identityUser
     * @return array<string, mixed>|null
     */
    public static function telegramLoginUser(?array $identityUser): ?array
    {
        return $identityUser;
    }

    /**
     * @param array<string, mixed>|null $existingForProviderId
     */
    public static function assertCanLink(?array $existingForProviderId, int $userId): void
    {
        if ($existingForProviderId === null) {
            return;
        }
        if ((int) $existingForProviderId['user_id'] === $userId) {
            return;
        }

        throw new OAuthException('IDENTITY_TAKEN', 'This social account is already linked to another user', 409);
    }
}
