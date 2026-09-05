# Architecture

Три независимые сущности:

- **ContentType** — форма контента
- **Field** — стабильный id поля
- **Resource** — публикация типа в `/api/{slug}`

Schema полей — source of truth для SQL, validation, REST, Admin UI, OpenAPI.

Auth: только `Authorization: Bearer`. Типы токенов `admin` и `api` в `cms_tokens` (hash + prefix).

Версии не смешивать:

| Ось | Пример |
|---|---|
| Продукт / админка | `VERSION` = 0.1.0 |
| Schema ContentType | `schema_version` |
| Public API | `/api` ≡ `/api/v1` |
