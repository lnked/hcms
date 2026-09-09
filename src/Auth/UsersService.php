<?php

declare(strict_types=1);

namespace Cms\Auth;

use InvalidArgumentException;
use RuntimeException;

final class UsersService
{
    public function __construct(
        private readonly UsersRepository $users,
        private readonly ?TokenService $tokens = null,
        private readonly ?UserSectionGrantRepository $sectionGrants = null,
        private readonly ?UserResourceGrantRepository $resourceGrants = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map([$this, 'serialize'], $this->users->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        $row = $this->users->find($id);
        if ($row === null) {
            throw new RuntimeException('User not found', 404);
        }

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $validated = self::validateCreate($payload);
        if ($this->users->findByEmail($validated['email']) !== null) {
            throw new InvalidArgumentException('Email already exists');
        }

        $row = $this->users->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password_hash' => Password::hash($validated['password']),
            'status' => $validated['status'],
            'role' => $validated['role'],
        ]);

        return $this->serialize($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(int $id, array $payload, ?AuthContext $actor = null): array
    {
        $existing = $this->users->find($id);
        if ($existing === null) {
            throw new RuntimeException('User not found', 404);
        }

        $validated = self::validateUpdate($payload);
        if (isset($validated['password'])) {
            $this->assertOwnerCanResetPassword($actor, $existing);
        }

        if (isset($validated['email']) && strcasecmp($validated['email'], (string) $existing['email']) !== 0) {
            $other = $this->users->findByEmail($validated['email']);
            if ($other !== null && (int) $other['id'] !== $id) {
                throw new InvalidArgumentException('Email already exists');
            }
        }

        $data = [];
        if (isset($validated['name'])) {
            $data['name'] = $validated['name'];
        }
        if (isset($validated['email'])) {
            $data['email'] = $validated['email'];
        }
        if (isset($validated['status'])) {
            $data['status'] = $validated['status'];
        }
        if (isset($validated['role'])) {
            $data['role'] = $validated['role'];
        }
        if (isset($validated['password'])) {
            $data['password_hash'] = Password::hash($validated['password']);
        }

        $updated = $this->serialize($this->users->update($id, $data));

        $becameDisabled = isset($validated['status'])
            && $validated['status'] === 'disabled'
            && ($existing['status'] ?? '') !== 'disabled';
        if ($becameDisabled && $this->tokens !== null) {
            $this->tokens->revokeAllForUser($id);
        }

        return $updated;
    }

    public function delete(int $id, int $actorId): void
    {
        if ($id === $actorId) {
            throw new InvalidArgumentException('Cannot delete yourself');
        }
        if ($this->users->find($id) === null) {
            throw new RuntimeException('User not found', 404);
        }

        if ($this->tokens !== null) {
            $this->tokens->revokeAllForUser($id);
        }
        $this->users->delete($id);
    }

    /**
     * @return array{aclEnabled: bool, sections: list<string>, resources: list<array{resourceId: int, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool, tabs: list<string>}>}
     */
    public function getAcl(int $id, ?AuthContext $actor = null): array
    {
        if ($actor !== null) {
            $this->assertActorIsOwner($actor);
        }
        $row = $this->users->find($id);
        if ($row === null) {
            throw new RuntimeException('User not found', 404);
        }
        if ($this->sectionGrants === null || $this->resourceGrants === null) {
            throw new RuntimeException('ACL unavailable', 503);
        }

        return [
            'aclEnabled' => (bool) ($row['acl_enabled'] ?? false),
            'sections' => $this->sectionGrants->forUser($id),
            'resources' => $this->resourceGrants->forUser($id),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{aclEnabled: bool, sections: list<string>, resources: list<array{resourceId: int, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool, tabs: list<string>}>}
     */
    public function setAcl(int $id, array $payload, AuthContext $actor): array
    {
        $this->assertActorIsOwner($actor);
        $existing = $this->users->find($id);
        if ($existing === null) {
            throw new RuntimeException('User not found', 404);
        }
        $targetRole = RolePolicy::normalize(isset($existing['role']) ? (string) $existing['role'] : null);
        if ($targetRole === RolePolicy::OWNER) {
            throw new InvalidArgumentException('Cannot change ACL for an owner');
        }
        if ($this->sectionGrants === null || $this->resourceGrants === null) {
            throw new RuntimeException('ACL unavailable', 503);
        }

        $aclEnabled = !empty($payload['aclEnabled']);
        $sections = $payload['sections'] ?? [];
        if (!is_array($sections)) {
            throw new InvalidArgumentException('sections must be an array');
        }
        $normalizedSections = [];
        foreach ($sections as $section) {
            if (!is_string($section) || !UserAclPolicy::isValidSection($section)) {
                throw new InvalidArgumentException('Invalid section');
            }
            if (!in_array($section, $normalizedSections, true)) {
                $normalizedSections[] = $section;
            }
        }

        $resources = $payload['resources'] ?? [];
        if (!is_array($resources)) {
            throw new InvalidArgumentException('resources must be an array');
        }
        $normalizedResources = [];
        foreach ($resources as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid resource grant');
            }
            $resourceId = isset($item['resourceId']) ? (int) $item['resourceId'] : 0;
            if ($resourceId < 1) {
                throw new InvalidArgumentException('resourceId is required');
            }
            $tabsRaw = $item['tabs'] ?? [];
            if (!is_array($tabsRaw)) {
                throw new InvalidArgumentException('tabs must be an array');
            }
            $tabs = [];
            foreach ($tabsRaw as $tab) {
                if (!is_string($tab) || !UserAclPolicy::isValidTab($tab)) {
                    throw new InvalidArgumentException('Invalid tab');
                }
                if (!in_array($tab, $tabs, true)) {
                    $tabs[] = $tab;
                }
            }
            $normalizedResources[] = [
                'resourceId' => $resourceId,
                'canRead' => !empty($item['canRead']),
                'canCreate' => !empty($item['canCreate']),
                'canUpdate' => !empty($item['canUpdate']),
                'canDelete' => !empty($item['canDelete']),
                'tabs' => $tabs,
            ];
        }

        $this->users->setAclEnabled($id, $aclEnabled);
        $this->sectionGrants->replace($id, $normalizedSections);
        $this->resourceGrants->replace($id, $normalizedResources);

        return $this->getAcl($id);
    }

    /**
     * Snapshot for /auth/me.
     *
     * @param array<string, mixed> $user
     * @return array{aclEnabled: bool, sections: list<string>, resourceGrants: list<array{resourceId: int, canRead: bool, canCreate: bool, canUpdate: bool, canDelete: bool, tabs: list<string>}>}
     */
    public function aclSnapshot(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $role = RolePolicy::normalize(isset($user['role']) ? (string) $user['role'] : null);
        if ($role === RolePolicy::OWNER || $userId < 1) {
            return [
                'aclEnabled' => false,
                'sections' => UserAclPolicy::SECTIONS,
                'resourceGrants' => [],
            ];
        }
        $aclEnabled = (bool) ($user['acl_enabled'] ?? false);
        if (!$aclEnabled || $this->sectionGrants === null || $this->resourceGrants === null) {
            return [
                'aclEnabled' => false,
                'sections' => UserAclPolicy::SECTIONS,
                'resourceGrants' => [],
            ];
        }

        return [
            'aclEnabled' => true,
            'sections' => $this->sectionGrants->forUser($userId),
            'resourceGrants' => $this->resourceGrants->forUser($userId),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name: string, email: string, password: string, status: string, role: string}
     */
    public static function validateCreate(array $payload): array
    {
        $name = isset($payload['name']) && is_string($payload['name']) ? trim($payload['name']) : '';
        $email = isset($payload['email']) && is_string($payload['email']) ? trim($payload['email']) : '';
        $password = isset($payload['password']) && is_string($payload['password']) ? $payload['password'] : '';
        $status = isset($payload['status']) && is_string($payload['status']) ? trim($payload['status']) : 'active';
        $role = isset($payload['role']) && is_string($payload['role']) ? trim($payload['role']) : RolePolicy::ADMIN;

        if ($name === '') {
            throw new InvalidArgumentException('Name is required');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Valid email is required');
        }
        if (!Password::meetsPolicy($password)) {
            throw new InvalidArgumentException(Password::policyMessage());
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        if (!in_array(RolePolicy::normalize($role), RolePolicy::ROLES, true)) {
            throw new InvalidArgumentException('Invalid role');
        }

        return [
            'name' => $name,
            'email' => strtolower($email),
            'password' => $password,
            'status' => $status,
            'role' => RolePolicy::normalize($role),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name?: string, email?: string, password?: string, status?: string, role?: string}
     */
    public static function validateUpdate(array $payload): array
    {
        $out = [];
        if (array_key_exists('name', $payload)) {
            $name = is_string($payload['name']) ? trim($payload['name']) : '';
            if ($name === '') {
                throw new InvalidArgumentException('Name is required');
            }
            $out['name'] = $name;
        }
        if (array_key_exists('email', $payload)) {
            $email = is_string($payload['email']) ? trim($payload['email']) : '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Valid email is required');
            }
            $out['email'] = strtolower($email);
        }
        if (array_key_exists('password', $payload) && $payload['password'] !== null && $payload['password'] !== '') {
            $password = is_string($payload['password']) ? $payload['password'] : '';
            if (!Password::meetsPolicy($password)) {
                throw new InvalidArgumentException(Password::policyMessage());
            }
            $out['password'] = $password;
        }
        if (array_key_exists('status', $payload)) {
            $status = is_string($payload['status']) ? trim($payload['status']) : '';
            if (!in_array($status, ['active', 'disabled'], true)) {
                throw new InvalidArgumentException('Invalid status');
            }
            $out['status'] = $status;
        }
        if (array_key_exists('role', $payload)) {
            $role = is_string($payload['role']) ? trim($payload['role']) : '';
            if (!in_array(RolePolicy::normalize($role), RolePolicy::ROLES, true)) {
                throw new InvalidArgumentException('Invalid role');
            }
            $out['role'] = RolePolicy::normalize($role);
        }

        if ($out === []) {
            throw new InvalidArgumentException('No fields to update');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function assertOwnerCanResetPassword(?AuthContext $actor, array $existing): void
    {
        if ($actor === null || $actor->user === null) {
            throw new InvalidArgumentException('Only an owner can reset passwords');
        }
        $actorRole = RolePolicy::normalize(isset($actor->user['role']) ? (string) $actor->user['role'] : null);
        if ($actorRole !== RolePolicy::OWNER) {
            throw new InvalidArgumentException('Only an owner can reset passwords');
        }
        $targetRole = RolePolicy::normalize(isset($existing['role']) ? (string) $existing['role'] : null);
        if ($targetRole === RolePolicy::OWNER && $actor->userId() !== (int) $existing['id']) {
            throw new InvalidArgumentException('Cannot reset another owner password');
        }
    }

    private function assertActorIsOwner(AuthContext $actor): void
    {
        if ($actor->user === null) {
            throw new InvalidArgumentException('Only an owner can manage ACL');
        }
        $role = RolePolicy::normalize(isset($actor->user['role']) ? (string) $actor->user['role'] : null);
        if ($role !== RolePolicy::OWNER) {
            throw new InvalidArgumentException('Only an owner can manage ACL');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'status' => $row['status'],
            'role' => RolePolicy::normalize(isset($row['role']) ? (string) $row['role'] : null),
            'aclEnabled' => (bool) ($row['acl_enabled'] ?? false),
            'totpEnabled' => (bool) ($row['totp_enabled'] ?? false),
            'lastLoginAt' => $row['last_login_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }
}
