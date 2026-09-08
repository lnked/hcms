# Счётчик скачиваний на лендинге

Лендинг [2js.ru](https://2js.ru) считает скачивания `install.php` через **обычный публичный Content API** нашей же CMS на `api.2js.ru`. Отдельного кода в бэкенде нет: клик пишет запись в ресурс, счётчик читает `meta.total`.

```text
2js.ru  ──POST /api/downloads──►  api.2js.ru   (публичный create)
2js.ru  ──GET  /api/downloads?limit=1──►       (читаем только meta.total)
```

Константы в [`landing/app.js`](../landing/app.js):

```js
const API_BASE = 'https://api.2js.ru';
const DOWNLOADS_SLUG = 'downloads';
```

## 1. Ресурс в админке

`/admin/resources/new` → content type `downloads`, затем **Schema** и **Publish**.

| поле | тип | что лежит |
|------|-----|-----------|
| `asset` | string | всегда `install.php` |
| `version` | string | версия из GitHub latest release (`0.50.0`), пустая если API GitHub недоступен |
| `source` | string | `button` — клик по кнопке, `curl` — копирование команды |
| `referrer` | string | хост реферера, пустой при прямом заходе |

Все поля — `string`, не `enum`: неизвестное значение не должно ронять запись в 422 и терять клик.

## 2. Settings ресурса

`/admin/resources/{id}` → **Settings**.

| настройка | значение | почему |
|-----------|----------|--------|
| `apiEnabled` | on | иначе публичный API ресурса отключён |
| `public.read` | on | лендингу нужен `meta.total` без токена |
| `public.create` | on | анонимная запись клика |
| `public.update` / `public.delete` | off | наружу только append |
| `search` / `sorting` / `filtering` | off | публичный GET — только счётчик, не query-инструмент |
| `deleteStrategy` | hard | ревизии/корзина счётчику не нужны |

**Spam** (там же):

| настройка | значение |
|-----------|----------|
| `rejectDuplicates` | **off** |
| `rateLimitPerMinute` | 20 |
| `requireCaptcha` | off |

`rejectDuplicates` по умолчанию **включён** и режет одинаковый payload с одного IP в течение 10 минут — для счётчика это молча теряет повторные скачивания. Защиту от накрутки даёт `rateLimitPerMinute`.

## 3. CORS

`/admin/settings/system` → **API access**: добавить `2js.ru` в allowed origins (или оставить unrestricted).

Без этого preflight `OPTIONS /api/downloads` вернёт 403 и счётчик просто не появится — страница не сломается.

## 4. Проверка

```bash
# запись клика
curl -X POST https://api.2js.ru/api/downloads \
  -H 'Content-Type: application/json' \
  -H 'Origin: https://2js.ru' \
  -d '{"asset":"install.php","version":"0.50.0","source":"button","referrer":""}'

# то, что читает лендинг
curl -s 'https://api.2js.ru/api/downloads?limit=1' | jq '.meta.total'
```

## Поведение на фронте

- Счётчик скрыт, пока `meta.total` не получен: CMS недоступна → лендинг работает без него.
- Клик инкрементит число оптимистично, не дожидаясь ответа.
- `fetch(..., { keepalive: true })` — клик уводит на GitHub, запрос должен пережить навигацию.
- Копирование `curl`-команды считается как `source=curl`: это тоже установка.

Публичный GET отдаёт и сами строки (`data`), поэтому в ресурсе не должно быть PII. IP не пишется в поля — он остаётся в audit log CMS.
