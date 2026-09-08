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

## Быстрый путь: патч

[`scripts/setup-downloads.php`](../scripts/setup-downloads.php) делает всё из разделов 1–3 сам, через Admin API, идемпотентно (повторный запуск чинит существующий ресурс, а не дублирует его).

Из браузера — залить файл рядом с `index.php` в корне сайта и открыть `https://api.2js.ru/setup-downloads.php`: форма спросит URL CMS, email и пароль администратора. После успеха там же кнопка **Delete this file**.

Из CLI:

```bash
php scripts/setup-downloads.php \
  --url=https://api.2js.ru --email=admin@example.com --password=SECRET
```

```text
Logged in as admin@example.com
Created resource downloads (#7)
Settings: public read + create, spam rejectDuplicates off, 20 req/min
Schema: asset, version, source, referrer
Published + migrated → /api/downloads
CORS: added 2js.ru
Public GET works, meta.total = 0
```

CORS патч трогает, только если доступ ограничен: при `unrestricted` не делает ничего, иначе дописывает `2js.ru` к существующему списку.

Файл должен лежать **в docroot** — рядом с `index.php` и папкой `admin/` (обычно `public_html/`). Если положить его в корень проекта, рядом с `src/`, веб-сервер до него не достучится и вместо формы отдаст SPA админки.

Если сертификат не покрывает хост CMS (`SSL: no alternative certificate subject name`), поставь галку **Skip TLS verification** в форме или `--insecure` в CLI. Это лечит только сам патч: лендинг ходит в API из браузера, и там нужен валидный сертификат на домен API.

Дальше — то же самое руками.

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
