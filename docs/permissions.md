# Permissions

## API tokens

Grants в `cms_token_grants` (`read/create/update/delete` на Resource).

Системные ключи могут использовать wildcard `*` (полный доступ ко всем ресурсам). Обычным токенам выдают явные grants на конкретные resources.

Дополнительно каждый API-токен можно ограничить по месту использования — таблица `cms_token_policies`
(`allowed_origins`, `require_origin`, `allowed_ips`). Пустая политика не хранится вовсе, поэтому старые
токены остаются без ограничений. Детали и семантика: [api.md](./api.md#per-token-restrictions).

## Admin roles (RBAC)

Колонка `cms_users.role`: `owner` | `admin` | `editor` | `viewer`.

| Capability | viewer | editor | admin | owner |
|---|---|---|---|---|
| read admin API | yes | yes | yes | yes |
| entries / media write | — | yes | yes | yes |
| schema / resources write | — | — | yes | yes |
| users / settings / tokens | — | — | yes | yes |
| system self-update | — | — | — | yes |

Первый пользователь при установке получает `owner`. Миграция `007_cms_user_roles` повышает `MIN(id)` до `owner`.

## Admin user ACL (поверх ролей)

Миграция `013_cms_user_acl`: `cms_users.acl_enabled`, `cms_user_section_grants`, `cms_user_resource_grants`.

- **Роль = потолок**, ACL — дополнительный allowlist (сужает доступ).
- **`owner` всегда bypass** ACL.
- `acl_enabled = 0` → поведение только по роли (как раньше).
- `acl_enabled = 1` → доступны только выданные секции админки и ресурсы.

Секции: `dashboard`, `resources`, `media`, `logs`, `docs`, `changelog`, `tokens`, `webhooks`, `users`, `integrations`, `system`, `account` (`account` и `/admin/api/auth/*` всегда доступны).

На ресурс: `canRead` / `canCreate` / `canUpdate` / `canDelete` + список табов (`overview`, `schema`, `data`, `settings`, `api`, `export`). Создание новых ресурсов / package import при включённом ACL запрещены.

### Управление (только owner)

```http
GET  /admin/api/users/{id}/acl
PATCH /admin/api/users/{id}/acl
# { "aclEnabled": true, "sections": ["resources","media"], "resources": [
#   { "resourceId": 1, "canRead": true, "canCreate": true, "canUpdate": false, "canDelete": false,
#     "tabs": ["overview","data"] }
# ] }

PATCH /admin/api/users/{id}   # password — сброс пароля только owner; нельзя сбросить другому owner
```

`/admin/api/auth/me` отдаёт `aclEnabled`, `sections`, `resourceGrants` для UI (nav + табы).
