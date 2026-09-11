# Deployment

Релиз: GitHub Release `vX.Y.Z` с assets:

- `latest.json`
- `cms-X.Y.Z.zip`
- `cms-X.Y.Z.zip.sha256`

`install.php` и админка читают:

`https://github.com/lnked/hcms/releases/latest/download/latest.json`

Не клади `.env` в git и в zip, если там уже есть секреты сайта.

## Uptime cron

Если сайт часто без трафика, поставь минутный cron — иначе soft cron (после `/admin/api/health`) может не тикать. Подробности: [`development.md#uptime-checks-cron`](development.md#uptime-checks-cron).

```bash
# CLI
* * * * * cd /path/to/hcms && php cms uptime:check >/dev/null 2>&1

# или HTTP (admin Bearer из login, не API-токен из Tokens)
* * * * * curl -fsS -X POST -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  https://example.com/admin/api/uptime/run >/dev/null
```
