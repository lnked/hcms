# Счётчик скачиваний на лендинге

Лендинг [2js.ru](https://2js.ru) считает скачивания `install.php` через **обычный публичный Content API** нашей же CMS. Отдельного кода в бэкенде нет: клик пишет запись в ресурс, счётчик читает `meta.total`.

API отдаётся с **того же origin**, что и лендинг — по пути `/api/`:

```text
2js.ru  ──POST /api/downloads──►          (публичный create)
2js.ru  ──GET  /api/downloads?limit=1──►  (читаем только meta.total)
```

Константа в [`landing/app.js`](../landing/app.js):

```js
const DOWNLOADS_ENDPOINT = '/api/downloads';
```

Так сделано намеренно: отдельный хост вида `api.2js.ru` требует своего SAN в сертификате и проходит CORS-preflight. Пока сертификат покрывал только `2js.ru` и `www.2js.ru`, браузер рубил все запросы лендинга на `ERR_CERT_COMMON_NAME_INVALID`, и счётчик молча не появлялся, а клики не записывались.

## 0. nginx: /api/ в вхосте лендинга

В `server`-блок лендинга добавить маршрут на фронт-контроллер CMS (`public/index.php` её инстанса):

```nginx
location ^~ /api/ {
    root /var/www/hcms/public;
    try_files $uri /index.php$is_args$args;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /var/www/hcms/public/index.php;
    }
}
```

`^~` не даёт regex-локейшенам лендинга перехватить `/api/`, а жёсткий `SCRIPT_FILENAME` — потому что фронт-контроллер всегда один. Пути к docroot и php-fpm сокету подставить свои.

## Быстрый путь: патч

[`scripts/setup-downloads.php`](../scripts/setup-downloads.php) делает всё из разделов 1–3 сам, через Admin API, идемпотентно (повторный запуск чинит существующий ресурс, а не дублирует его).

Из браузера — залить файл рядом с `index.php` в корне сайта и открыть `https://2js.ru/setup-downloads.php`: форма спросит URL CMS, email и пароль администратора. После успеха там же кнопка **Delete this file**.

Из CLI:

```bash
php scripts/setup-downloads.php \
  --url=https://2js.ru --email=admin@example.com --password=SECRET
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

Если сертификат не покрывает хост CMS (`SSL: no alternative certificate subject name`), поставь галку **Skip TLS verification** в форме или `--insecure` в CLI. Это лечит только сам патч: лендинг ходит в API из браузера, и там нужен валидный сертификат — ещё одна причина держать API на том же хосте, что и лендинг.

Дальше — то же самое руками.

## 1. Ресурс в админке

`/admin/resources/new` → content type `downloads`, затем **Schema** и **Publish**.

| поле | тип | что лежит |
|------|-----|-----------|
| `asset` | string | всегда `install.php` |
| `version` | string | версия из GitHub latest release (`0.50.0`), пустая если API GitHub недоступен |
| `source` | string | откуда пришёл клик, см. таблицу ниже |
| `referrer` | string | хост реферера, пустой при прямом заходе |

Значения `source`:

| значение | откуда |
|----------|--------|
| `nav` | кнопка в шапке |
| `hero` | кнопка в первом экране |
| `cta` | кнопка в нижнем блоке |
| `curl` | копирование `curl`-команды |
| `button` | фолбэк, если у ссылки нет `data-source` (так же выглядят записи до разделения источников) |

Все поля — `string`, не `enum`: неизвестное значение не должно ронять запись в 422 и терять клик. Поэтому новый источник достаточно добавить атрибутом `data-source` в разметке — схему править не нужно.

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

При раздаче API с того же origin CORS не нужен — запрос лендинга уже не cross-origin, preflight не отправляется.

Настройка `/admin/settings/system` → **API access** нужна только если API остаётся на отдельном хосте: тогда добавить `2js.ru` в allowed origins, иначе preflight `OPTIONS /api/downloads` вернёт 403 и счётчик просто не появится — страница не сломается.

## 4. Проверка

```bash
# запись клика
curl -X POST https://2js.ru/api/downloads \
  -H 'Content-Type: application/json' \
  -d '{"asset":"install.php","version":"0.50.0","source":"button","referrer":""}'

# то, что читает лендинг
curl -s 'https://2js.ru/api/downloads?limit=1' | jq '.meta.total'
```

## Поведение на фронте

- Счётчик скрыт, пока `meta.total` не получен: CMS недоступна → лендинг работает без него.
- Клик инкрементит число оптимистично, не дожидаясь ответа.
- `fetch(..., { keepalive: true })` — клик уводит на GitHub, запрос должен пережить навигацию.
- Копирование `curl`-команды считается как `source=curl`: это тоже установка.

Публичный GET отдаёт и сами строки (`data`), поэтому в ресурсе не должно быть PII. IP не пишется в поля — он остаётся в audit log CMS.
