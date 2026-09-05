# Deployment

Релиз: GitHub Release `vX.Y.Z` с assets:

- `latest.json`
- `cms-X.Y.Z.zip`
- `cms-X.Y.Z.zip.sha256`

`install.php` и админка читают:

`https://github.com/lnked/hcms/releases/latest/download/latest.json`

Не клади `.env` в git и в zip, если там уже есть секреты сайта.
