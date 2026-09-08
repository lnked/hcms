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

Без этого локейшена лендинг получает `POST https://2js.ru/api/downloads` → **404**: статический вхост про `/api/` ничего не знает.

### Вариант A: CMS живёт отдельным вхостом (`api.2js.ru`)

Проксируем на неё по петле — лендинг остаётся same-origin, CORS и сертификат для api-хоста не нужны:

```nginx
location ^~ /api/ {
    proxy_pass http://127.0.0.1:80;
    proxy_set_header Host api.2js.ru;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
}
```

`Host` обязателен: по нему nginx выбирает вхост CMS. `X-Forwarded-Proto https` — чтобы CMS генерировала https-ссылки, а не http.

### Вариант B: CMS лежит каталогом на том же сервере

Маршрут напрямую на её фронт-контроллер (`public/index.php`):

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

`^~` в обоих вариантах не даёт regex-локейшенам лендинга перехватить `/api/`, а жёсткий `SCRIPT_FILENAME` — потому что фронт-контроллер всегда один. Пути к docroot и php-fpm сокету подставить свои.

### Вариант C: shared-хостинг без доступа к nginx

Так сейчас развёрнут 2js.ru: Timeweb, два вхоста одного аккаунта, конфиг nginx правит только панель. Роль локейшена берут на себя [`landing/.htaccess`](../landing/.htaccess) и [`landing/api-proxy.php`](../landing/api-proxy.php) — они лежат в docroot лендинга и форвардят единственный путь `/api/downloads` на API-хост:

```apache
RewriteRule ^api/downloads$ api-proxy.php [QSA,L]
```

Загрузить ядро CMS прямо в процессе лендинга (`require .../src/bootstrap.php`) нельзя, если у вхостов разные версии PHP: у 2js.ru — 7.2, у api.2js.ru — 8.3, и `vendor/composer/platform_check.php` валит запрос в 500. Форвард по HTTP от версии не зависит.

Цена решения — CMS видит IP сервера, а не посетителя: `mod_remoteip` вырезает `X-Forwarded-For` от недоверенного источника, поэтому `security.rate_limit_ip_per_minute` (120) и `security.rate_limit_anon_write_per_minute` (20) считаются на всех разом. Для лендингового трафика этого хватает, а счётчик декоративный — на 429 страница не ломается. Если версии PHP выровнять через панель, шим сводится к двум строкам с `bootstrap.php` и IP снова становится настоящим.

### Проверка после применения

```bash
curl -s 'https://2js.ru/api/downloads?limit=1' | jq '.meta.total'   # число, не 404
```

Если вместо JSON пришёл HTML лендинга — маршрут перехвачен другим правилом, проверь, что в nginx стоит именно `^~`.

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
