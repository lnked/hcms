# Счётчик скачиваний на лендинге

Лендинг [2js.ru](https://2js.ru) считает скачивания `install.php` **на сервере**, а не в браузере. Ресурс `downloads` в нашей же CMS закрыт полностью: ни `public.read`, ни `public.create`. Пишет и читает его только лендинг — по токену, который лежит на его хосте.

```text
клик → GET 2js.ru/download?source=hero  → 302 на GitHub
                                        └─ POST api.2js.ru/api/downloads (Bearer, server-to-server)

счётчик → GET 2js.ru/api/downloads      → {"total": 1234}   (кэш 60 с, без строк)
```

## Почему не публичный POST из браузера

Так было раньше: `public.create` на ресурсе, `fetch(..., {keepalive:true})` из [`landing/app.js`](../landing/app.js). Проблемы, которые это давало:

- **писать мог кто угодно.** `ApiAccess::allows()` пропускает запрос без заголовка `Origin` — иначе сломались бы server-to-server клиенты. `curl` его не шлёт, значит ни CORS-список, ни `Referer` ничего не ограничивали. Ключ в JS не помог бы: он виден в исходнике страницы;
- **произвольный текст на нашем домене.** Поля `version`/`source`/`referrer` приходили из тела запроса, а `public.read` отдавал `data` наружу. То есть чужая строка со ссылками раздавалась с `2js.ru`;
- **лимит на всех разом.** IP посетителя до CMS не доезжает (см. ниже), поэтому `spam.rateLimitPerMinute` тратился одним ведром — скрипт выжирал его, и настоящие клики молча терялись в 429.

Серверный подсчёт убирает всё это сразу: накрутка стоит ровно одного настоящего скачивания, а тело записи собирается из `REMOTE_ADDR`, `Referer` и серверного времени.

## Как устроено

| файл | роль |
|------|------|
| [`landing/counter.php`](../landing/counter.php) | общий слой: конфиг, кэш, троттлинг, HTTP к CMS. Не роут — прямой доступ отдаёт 404 |
| [`landing/download.php`](../landing/download.php) | `GET /download?source=…` → 302 на GitHub, запись строки после флаша ответа |
| [`landing/api-proxy.php`](../landing/api-proxy.php) | `GET /api/downloads` → `{"total": N}` и ничего больше |
| [`landing/.htaccess`](../landing/.htaccess) | два rewrite-правила + запрет на доступ к `counter*.php` и дотфайлам |

Что делает `download.php` по шагам:

1. отдаёт `302 Location: https://github.com/lnked/hcms/releases/latest/download/install.php` и закрывает соединение (`fastcgi_finish_request`, иначе `Content-Length: 0` + `flush()`);
2. отсекает `HEAD`, prefetch-заголовки (`Sec-Purpose`, `X-Moz`, `Purpose`) и ботов по User-Agent — `curl`/`wget` при этом считаются, это настоящие установки;
3. проверяет свой файловый лимит: `clicksPerIpPerMinute` (10) на `REMOTE_ADDR`. Превышение не ломает скачивание — просто не двигает число;
4. `POST /api/downloads` с Bearer-токеном. Полей ровно пять, все собраны на сервере.

`source` — единственное, на что влияет посетитель, и он проходит через whitelist (`nav`, `hero`, `cta`, `curl`, `button`); что угодно другое схлопывается в `button`.

## Настройка

### 1. Ресурс и токен

[`scripts/setup-downloads.php`](../scripts/setup-downloads.php) делает всё через Admin API и идемпотентен: повторный запуск чинит существующий ресурс, а не дублирует его.

```bash
php scripts/setup-downloads.php \
  --url=https://api.2js.ru --email=admin@example.com --password=SECRET \
  --landing-ip=203.0.113.10
```

```text
Logged in as admin@example.com
Created resource downloads (#7)
Settings: no public access, token only
Schema: asset, version, source, referrer, date
Published + migrated → /api/downloads
Token created, locked to 203.0.113.10 — put it in landing/counter-config.php as `token`:
hcms_… 
Authenticated GET works, meta.total = 0
Anonymous GET rejected, as expected
```

Токен показывается один раз. Потерял — `--rotate` отзовёт старый и выпустит новый. Права у него ровно два: `read` + `create` на `downloads`.

`--landing-ip` кладёт IP лендинга в `allowedIps` политики токена — единственное неподделываемое ограничение в системе: утёкший токен снаружи бесполезен. В отличие от него `allowedOrigins` держится на заголовке `Origin`, который скрипт не шлёт вовсе.

Из браузера — залить файл рядом с `index.php` в корне сайта и открыть `https://api.2js.ru/setup-downloads.php`: та же форма, после успеха кнопка **Delete this file**. Файл должен лежать **в docroot**, рядом с `index.php` и папкой `admin/` (обычно `public_html/`); в корне проекта, рядом с `src/`, веб-сервер до него не достучится.

Если сертификат не покрывает хост CMS (`SSL: no alternative certificate subject name`) — галка **Skip TLS verification** или `--insecure` в CLI.

### 2. Конфиг лендинга

```bash
cp landing/counter-config.sample.php landing/counter-config.php
# вписать token и api
```

Либо переменные окружения `HCMS_DOWNLOADS_TOKEN` и `HCMS_API_BASE` — они перекрывают файл. `counter-config.php` в `.gitignore` и закрыт в `.htaccess`.

Схема ресурса (все поля nullable, чтобы кривой payload не стоил клика):

| поле | тип | что лежит |
|------|-----|-----------|
| `asset` | string(64) | всегда `install.php` |
| `version` | string(32) | версия из GitHub latest release, пустая если GitHub недоступен |
| `source` | string(32) | `nav` / `hero` / `cta` / `curl` / `button` |
| `referrer` | string(190) | хост из заголовка `Referer`, пустой при прямом заходе |
| `date` | datetime | серверное время клика |

Новый источник добавляется в whitelist `counter_source()` и в `?source=` в разметке — схему трогать не нужно.

### 3. CORS

Не нужен вообще. Браузер ходит только на свой origin (`/download`, `/api/downloads`), в CMS стучится PHP. `/admin/settings/system` → **API access** можно оставить как есть.

## Развёртывание

Так сейчас развёрнут 2js.ru: Timeweb, два вхоста одного аккаунта, конфиг nginx правит только панель. Всё живёт в `.htaccess` лендинга:

```apache
RewriteRule ^download/?$ download.php [QSA,L]
RewriteRule ^api/downloads$ api-proxy.php [QSA,L]
```

Загрузить ядро CMS прямо в процессе лендинга (`require .../src/bootstrap.php`) нельзя: у вхостов разные версии PHP (у 2js.ru — 7.2, у api.2js.ru — 8.3), и `vendor/composer/platform_check.php` валит запрос в 500. Поэтому код в `landing/` держится PHP 7.2 и ходит в CMS по HTTP.

Если CMS раздаётся тем же nginx, что и лендинг, шим всё равно работает — просто поставь `api` в `http://127.0.0.1` и заголовок `Host` сделает своё дело на стороне сервера.

### IP посетителя и `trusted_proxies` — выбрать одно

`download.php` и `api-proxy.php` шлют `X-Forwarded-For: REMOTE_ADDR` (никогда из клиентского заголовка). Дальше развилка:

| `security.trusted_proxies` | что получаешь | что теряешь |
|---------------------------|---------------|-------------|
| пусто (**рекомендуется**) | `allowedIps` на токене работает: запрос приходит с IP лендинга | в audit log CMS — IP лендинга, не посетителя |
| IP лендинга в списке | настоящий IP посетителя в audit log | `ClientIp::resolve` подменяет `$request->ip`, и `allowedIps` отвергнет **все** запросы |

Совмещать нельзя: проверка политики в `Kernel` идёт уже по разрешённому IP. Раньше `trusted_proxies` был нужен ради лимитера — теперь лимит считается на лендинге, где `REMOTE_ADDR` настоящий, так что смысла жертвовать `allowedIps` нет. См. [anti-spam.md](anti-spam.md).

## Проверка

```bash
# счётчик
curl -s https://2js.ru/api/downloads          # {"total":1234}

# клик: 302 на GitHub, строка пишется асинхронно
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' 'https://2js.ru/download?source=curl'

# ресурс закрыт снаружи
curl -s -o /dev/null -w '%{http_code}\n' https://api.2js.ru/api/downloads   # 401
```

Если `/api/downloads` вернул HTML лендинга — правило перехвачено другим, проверь порядок в `.htaccess`. Если `503 UPSTREAM_UNAVAILABLE` — не задан токен или CMS недоступна.

## Поведение на фронте

- Счётчик скрыт, пока `total` не получен: CMS недоступна → лендинг работает без него.
- Клик инкрементит число оптимистично; настоящая запись идёт на сервере.
- Копирование `curl`-команды **не** считается: в команде стоит `https://2js.ru/download?source=curl`, и клик засчитается, когда её реально выполнят.
- `?limit=1` наружу больше не уходит: `api-proxy.php` отдаёт только число и кэширует его на 60 секунд, так что шквал открытий страницы не бьёт по API.
