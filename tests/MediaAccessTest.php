<?php

declare(strict_types=1);

namespace Cms\Tests;

use Cms\Media\MediaAccess;
use Cms\Media\MediaAclScope;
use PHPUnit\Framework\TestCase;

final class MediaAccessTest extends TestCase
{
    public function testUnrestrictedSeesEverything(): void
    {
        $scope = MediaAclScope::unrestricted(1);
        self::assertTrue(MediaAccess::isVisible($scope, [9], null));
        self::assertTrue(MediaAccess::canMutate($scope, [9], null));
    }

    public function testRestrictedSeesAccessibleRefs(): void
    {
        $scope = new MediaAclScope([1, 2], 7);
        self::assertTrue(MediaAccess::isVisible($scope, [2, 9], null));
        self::assertFalse(MediaAccess::isVisible($scope, [9], null));
    }

    public function testRestrictedSeesOwnOrphansOnly(): void
    {
        $scope = new MediaAclScope([1], 7);
        self::assertTrue(MediaAccess::isVisible($scope, [], 7));
        self::assertFalse(MediaAccess::isVisible($scope, [], 8));
        self::assertFalse(MediaAccess::isVisible($scope, [], null));
    }

    public function testCannotMutateSharedWithForbiddenResource(): void
    {
        $scope = new MediaAclScope([1], 7);
        self::assertTrue(MediaAccess::canMutate($scope, [1], null));
        self::assertFalse(MediaAccess::canMutate($scope, [1, 2], null));
        self::assertFalse(MediaAccess::canMutate($scope, [], 8));
        self::assertTrue(MediaAccess::canMutate($scope, [], 7));
    }

    public function testEmptyGrantsOnlyOwnOrphans(): void
    {
        $scope = new MediaAclScope([], 3);
        self::assertFalse(MediaAccess::isVisible($scope, [1], 3));
        self::assertTrue(MediaAccess::isVisible($scope, [], 3));
        self::assertFalse(MediaAccess::canMutate($scope, [1], 3));
    }
}
