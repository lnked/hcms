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
