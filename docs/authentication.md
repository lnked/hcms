# Authentication

Единственный механизм:

```http
Authorization: Bearer <token>
```

Login:

```http
POST /admin/api/auth/login
{ "email": "...", "password": "..." }
```

Ответ: `{ "data": { "token": "...", "user": { ... } } }`.

Токен в БД не хранится открытым: `token_prefix` + `sha256`. Logout ревокает текущий токен.
