<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Auth\AuthContext;
use Cms\Auth\UserAclGuard;

/**
 * ACL view of the media library for the current admin.
 *
 * null allowedResourceIds = unrestricted (owner / ACL off).
 * empty list = ACL on, no resource grants (own orphans only).
 */
final class MediaAclScope
{
    /**
     * @param list<int>|null $allowedResourceIds
     */
    public function __construct(
        public readonly ?array $allowedResourceIds,
        public readonly ?int $userId,
    ) {
    }

    public static function unrestricted(?int $userId = null): self
    {
        return new self(null, $userId);
    }

    public static function fromAuth(AuthContext $auth, ?UserAclGuard $userAcl): self
    {
        $userId = $auth->userId();
        if ($userAcl === null) {
            return self::unrestricted($userId);
        }
        $allowed = $userAcl->allowedResourceIds($auth);

        return new self($allowed, $userId);
    }

    public function isRestricted(): bool
    {
        return $this->allowedResourceIds !== null;
    }
}
