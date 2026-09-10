const REPO = 'lnked/hcms';

// Counting is server-side: every .js-download link points at /download, which
// writes the row and 302s to GitHub. This endpoint is read-only and returns a
// bare { total } — the rows themselves never leave the API host.
const DOWNLOADS_ENDPOINT = '/api/downloads';

const state = { downloads: null, preview: 'light' };

const I18N = {
  en: {
    'skip': 'Skip to content',
    'nav.features': 'Features',
    'nav.install': 'Install',
    'nav.preview': 'Admin preview',
    'nav.why': 'Why',
    'nav.compare': 'Compare',
    'nav.faq': 'FAQ',
    'nav.cta': 'Get install.php',
    'hero.title': 'A ready-made admin for your SPA',
    'hero.sub':
      'Describe the schema. Get REST, OpenAPI, CRUD, feature flags, and translates. Your SPA stays the frontend.',
    'hero.download': 'Download install.php',
    'hero.github': 'View on GitHub',
    'hero.copy': 'Copy',
    'hero.copied': 'Copied',
    'hero.note':
      'The second line boots the wizard on port 8080 and prints a one-time link: PHP check, latest release, DB and admin — all in the browser.',
    'pill.admin': 'Admin panel',
    'pill.oauth': 'OAuth',
    'pill.roles': 'Roles',
    'pill.flags': 'Feature flags',
    'pill.i18n': 'i18n API',
    'pill.dark': 'Dark mode',
    'pill.host': 'Self-host',
    'trust.deps': 'Zero runtime deps',
    'step1.title': 'Run the two lines',
    'step1.body':
      'Over SSH, in the folder you want the CMS in. No shell? Upload <code>install.php</code> to the web root and open it in the browser instead.',
    'step2.title': 'Open the printed link',
    'step2.body':
      '<code>php install.php</code> serves the wizard on port 8080 behind a one-time key. It checks PHP 8.3+, downloads the latest release and verifies sha256.',
    'step3.title': 'Fill DB and admin',
    'step3.body':
      'The wizard writes the config, runs migrations and creates the owner. Then /admin and /api are live for your SPA.',
    'preview.eyebrow': 'See the admin',
    'preview.title': 'Light or dark — same product',
    'preview.lead':
      'What editors open at /admin: sidebar, dashboard, schema, content. Toggle the theme preview.',
    'preview.light': 'Light',
    'preview.dark': 'Dark',
    'preview.altLight': 'HCMS admin dashboard in light theme',
    'preview.altDark': 'HCMS admin dashboard in dark theme',
    's1.eyebrow': 'For your SPA',
    's1.title': 'Plug in an admin. Keep the frontend.',
    's1.lead':
      'HCMS is the backend your SPA is missing: visual schema, REST, feature flags, translates, and a real /admin — no custom CRUD.',
    's1.c1.title': 'Drop-in /admin',
    's1.c1.body': 'Ship a full admin UI with your PHP host. Editors never touch the SPA repo.',
    's1.c2.title': 'REST for React / Vue / Next',
    's1.c2.body': 'Public /api/{slug} with pagination, search, sort, and filter. Bearer tokens, CORS included.',
    's1.c3.title': 'OpenAPI / Swagger',
    's1.c3.body': 'Spec at /api/openapi.json, UI at /api/docs — generated from the schema you publish.',
    'rt.eyebrow': 'SPA runtime',
    'rt.title': 'Flags, A/B, and translates — without another SaaS',
    'rt.lead': 'Ship kill-switches and copy from the same PHP host as your content API.',
    'rt.c1.title': 'Feature flags',
    'rt.c1.body':
      'Boolean, integer, string, object. Admin CRUD plus public GET /api/features with ETag for the SPA.',
    'rt.c2.title': 'A/B percentage rollout',
    'rt.c2.body':
      'Sticky buckets on boolean flags: abTest + rolloutPercent, subject via query or X-Flag-Subject.',
    'rt.c3.title': 'Translates API',
    'rt.c3.body':
      'Locales and keys in admin. Public GET /api/translates for SPA dictionaries — import/export JSON.',
    'team.eyebrow': 'Built for teams',
    'team.title': 'Sign-in, roles, and forms that ship',
    'team.lead':
      'OAuth, clear roles for editors and viewers, spam-safe public forms, and email out of the box.',
    'team.c1.title': 'Sign-in that fits teams',
    'team.c1.body':
      'Google and Telegram login plus TOTP 2FA. Owners stay on password; editors can connect social accounts.',
    'team.c2.title': 'Roles people understand',
    'team.c2.body':
      'Invite a content manager to edit entries — or give view-only access to the admin. Owner keeps schema, users, and system. Per-resource ACL on top.',
    'team.c3.title': 'Forms & email ready',
    'team.c3.body':
      'Anti-spam on public create: honeypot, timing, captcha, blocklist. Send via Resend, Postmark, or Mailgun.',
    's2.eyebrow': 'Build APIs fast',
    's2.title': 'From content type to live /api/{slug}',
    's2.lead': 'Schema is the source of truth for SQL, validation, REST, Admin UI, and OpenAPI.',
    's2.c1.title': 'Field types that cover real apps',
    's2.c1.body': 'String, richtext, relation, media, slug, enum, json, dates — publish and get a migration.',
    's2.c2.title': 'Query the way you expect',
    's2.c2.body':
      'Per-resource pagination, search, sort, and filter. Custom resource APIs for read and write projections.',
    's2.c3.title': 'Swagger the moment you publish',
    's2.c3.body': 'No hand-written spec. Open /api/docs after you hit publish on a content type.',
    's3.eyebrow': 'Content tools',
    's3.title': 'Editors get a real admin, not a JSON dump',
    's3.lead':
      'Media, rich text, relations, revisions, dashboard analytics — the usual CMS jobs, wired to your API.',
    's3.c1.title': 'Media + variants',
    's3.c1.body':
      'Library, crop, optimize (WebP/JPEG/PNG), variants. Pretty /media/{id}/{file} with static warm-cache after the first hit.',
    's3.c2.title': 'Rich text & tables',
    's3.c2.body': 'Markdown for editors. Typed column filters, bulk delete, and relation labels in the list view.',
    's3.c3.title': 'Relations & revisions',
    's3.c3.body': 'Link entries, keep history, export/import resource packages.',
    's4.eyebrow': 'Deploy anywhere',
    's4.title': 'One file. Any PHP host.',
    's4.lead': 'No Cloud upsell. Self-host, update in-app, roll back if a swap dies mid-flight.',
    's4.c1.title': 'install.php → GitHub zip',
    's4.c1.body': 'Wizard downloads the latest release, verifies sha256, unzip. If src/ is already there, skip.',
    's4.c2.title': 'Shared hosting or Docker',
    's4.c2.body':
      'public_html layout done right — or <code>docker compose up</code> with MySQL and Adminer for local.',
    's4.c3.title': 'Atomic updates',
    's4.c3.body': 'Settings → System. Interrupted swap rolls back. restore.php if the box still needs a shove.',
    's5.eyebrow': 'Secure by default',
    's5.title': 'Tokens, roles, hooks — without a plugin zoo',
    's5.lead': 'Auth is Bearer-only. The rest ships in core.',
    's5.c1.title': 'Editor, viewer, owner',
    's5.c1.body':
      'Content managers edit entries; viewers only browse the admin. Owner → admin → editor → viewer, plus per-resource ACL.',
    's5.c2.title': 'OAuth + TOTP',
    's5.c2.body':
      'Google / Telegram and authenticator apps. Captcha, IP blocks, token origin/IP limits — no security plugin hunt.',
    's5.c3.title': 'HMAC webhooks',
    's5.c3.body': 'Ping your SPA or workers on content changes. Retries included.',
    'why.eyebrow': 'Why HCMS',
    'why.title': 'You shipped the SPA. Then you rebuilt the CMS.',
    'why.lead': 'CRUD, migrations, admin UI, OpenAPI — again. HCMS closes that gap on any PHP host.',
    'why.pain.title': 'The usual path',
    'why.pain.1': 'Hand-rolled admin nobody wants to maintain',
    'why.pain.2': 'SQL and validation drift from the API',
    'why.pain.3': 'Swagger written by hand — or skipped',
    'why.pain.4': 'Editors poked into the SPA repo',
    'why.win.title': 'With HCMS',
    'why.win.1': 'Schema → SQL, REST, admin, OpenAPI',
    'why.win.2': 'Publish runs the migration',
    'why.win.3': 'Editors work at /admin — SPA stays yours',
    'why.win.4': 'install.php on shared hosting or Docker',
    'cmp.eyebrow': 'Compare',
    'cmp.title': 'Built for PHP hosts, not another Node stack',
    'cmp.lead': 'When shared hosting and a two-line install matter more than a cloud dashboard.',
    'cmp.col.feat': 'Capability',
    'cmp.col.custom': 'Custom CRUD',
    'cmp.col.node': 'Node headless',
    'cmp.r1': 'Shared hosting / public_html',
    'cmp.r2': 'Zero Node runtime',
    'cmp.r3': 'install.php → latest zip',
    'cmp.r4': 'Schema → OpenAPI + admin',
    'cmp.r5': 'MIT, self-host, no cloud upsell',
    'faq.eyebrow': 'FAQ',
    'faq.title': 'Questions before you curl',
    'faq.q1': 'Does it run on shared hosting?',
    'faq.a1':
      'Yes. public_html layout is first-class: web root in the docroot, src/ one level up. PHP 8.3+ and MySQL / MariaDB.',
    'faq.q2': 'Do I need Node on the server?',
    'faq.a2': 'No. Runtime is PHP only. Node is only for building the admin UI when you develop from source.',
    'faq.q3': 'How is this different from Strapi / Directus?',
    'faq.a3':
      'Those want a Node host. HCMS targets classic PHP hosting: one install.php, zip from GitHub, no Cloud tier.',
    'faq.q4': 'Who can edit content vs only view the admin?',
    'faq.a4':
      'Roles: owner → admin → editor → viewer. Give a content manager editor access, or viewer for read-only admin. ACL can narrow resources further.',
    'faq.q5': 'Are schema migrations safe?',
    'faq.a5':
      'Publish migrates for you. Destructive ops (drop field, change type) require confirmDestructive — or the whole migration fails cleanly.',
    'faq.q6': 'Feature flags and translates — another product?',
    'faq.a6':
      'No. Flags (with optional A/B) and translation keys live in the same admin and public API as your content — /api/features and /api/translates.',
    'cta.title': 'Two commands. Admin on your PHP host.',
    'cta.body': 'Latest zip from GitHub. Admin at /admin, API at /api. MIT.',
    'footer.blurb':
      'API-first headless CMS. Schema, flags, translates, media — REST and admin out. MIT.',
    'footer.product': 'Product',
    'footer.source': 'Source',
    'announce':
      'HCMS {version} is out — MIT, PHP 8.3, zero runtime deps. <a href="{url}" target="_blank" rel="noopener">Release notes</a>',
    'counter.plural': 'download|downloads|downloads',
    'title': 'HCMS — a ready-made admin for your SPA',
  },
  ru: {
    'skip': 'К содержимому',
    'nav.features': 'Возможности',
    'nav.install': 'Установка',
    'nav.preview': 'Превью админки',
    'nav.why': 'Зачем',
    'nav.compare': 'Сравнение',
    'nav.faq': 'FAQ',
    'nav.cta': 'Скачать install.php',
    'hero.title': 'Готовая админка для вашего SPA',
    'hero.sub':
      'Опиши схему — получи REST, OpenAPI, CRUD, feature flags и translates. Фронт остаётся фронтом.',
    'hero.download': 'Скачать install.php',
    'hero.github': 'Код на GitHub',
    'hero.copy': 'Копировать',
    'hero.copied': 'Скопировано',
    'hero.note':
      'Вторая строка поднимает мастер на порту 8080 и печатает одноразовую ссылку: проверка PHP, свежий релиз, БД и админ — в браузере.',
    'pill.admin': 'Админка',
    'pill.oauth': 'OAuth',
    'pill.roles': 'Роли',
    'pill.flags': 'Feature flags',
    'pill.i18n': 'i18n API',
    'pill.dark': 'Тёмная тема',
    'pill.host': 'Self-host',
    'trust.deps': 'Без runtime-зависимостей',
    'step1.title': 'Выполни две строки',
    'step1.body':
      'По SSH, в папке будущей CMS. Нет шелла? Залей <code>install.php</code> в корень сайта и открой в браузере.',
    'step2.title': 'Открой ссылку из терминала',
    'step2.body':
      '<code>php install.php</code> поднимает мастер на порту 8080 под одноразовым ключом. Проверяет PHP 8.3+, качает свежий релиз, сверяет sha256.',
    'step3.title': 'Заполни БД и админа',
    'step3.body':
      'Мастер пишет конфиг, гоняет миграции, создаёт владельца. После этого /admin и /api готовы для SPA.',
    'preview.eyebrow': 'Смотри админку',
    'preview.title': 'Светлая или тёмная — один продукт',
    'preview.lead':
      'То, что открывают редакторы на /admin: сайдбар, дашборд, схема, контент. Переключи превью темы.',
    'preview.light': 'Светлая',
    'preview.dark': 'Тёмная',
    'preview.altLight': 'Админка HCMS — светлая тема',
    'preview.altDark': 'Админка HCMS — тёмная тема',
    's1.eyebrow': 'Для вашего SPA',
    's1.title': 'Админка подключается. Фронт остаётся твоим.',
    's1.lead':
      'HCMS — бэкенд, которого не хватает SPA: визуальная схема, REST, feature flags, translates и нормальный /admin. Без самописного CRUD.',
    's1.c1.title': 'Drop-in /admin',
    's1.c1.body': 'Полная админка на PHP-хостинге. Редакторы не лезут в репозиторий фронта.',
    's1.c2.title': 'REST для React / Vue / Next',
    's1.c2.body': 'Публичный /api/{slug}: пагинация, поиск, сорт, фильтры. Bearer и CORS из коробки.',
    's1.c3.title': 'OpenAPI / Swagger',
    's1.c3.body': 'Спека /api/openapi.json, UI /api/docs — из опубликованной схемы.',
    'rt.eyebrow': 'Runtime для SPA',
    'rt.title': 'Флаги, A/B и translates — без ещё одного SaaS',
    'rt.lead': 'Kill-switch и копирайт с того же PHP-хоста, что и content API.',
    'rt.c1.title': 'Feature flags',
    'rt.c1.body':
      'Boolean, integer, string, object. CRUD в админке и публичный GET /api/features с ETag для SPA.',
    'rt.c2.title': 'A/B по проценту',
    'rt.c2.body':
      'Sticky-бакеты на boolean: abTest + rolloutPercent, subject через query или X-Flag-Subject.',
    'rt.c3.title': 'Translates API',
    'rt.c3.body':
      'Локали и ключи в админке. Публичный GET /api/translates для словарей SPA — import/export JSON.',
    'team.eyebrow': 'Для команды',
    'team.title': 'Вход, роли и формы без боли',
    'team.lead':
      'OAuth, понятные роли editor/viewer, антиспам на публичных формах и email из коробки.',
    'team.c1.title': 'Вход под команду',
    'team.c1.body':
      'Google и Telegram плюс TOTP 2FA. Владелец на пароле; редакторы могут подключить соцвход.',
    'team.c2.title': 'Роли без жаргона',
    'team.c2.body':
      'Выдай контент-менеджеру доступ к записям — или только просмотр админки. Схема, пользователи и система остаются у владельца. Сверху — ACL на ресурсы.',
    'team.c3.title': 'Формы и email',
    'team.c3.body':
      'Антиспам на public.create: honeypot, тайминг, капча, блоклист. Отправка через Resend, Postmark или Mailgun.',
    's2.eyebrow': 'API за минуты',
    's2.title': 'От типа контента до живого /api/{slug}',
    's2.lead': 'Схема — source of truth для SQL, валидации, REST, админки и OpenAPI.',
    's2.c1.title': 'Типы полей под реальные приложения',
    's2.c1.body': 'String, richtext, relation, media, slug, enum, json, даты — опубликовал и получил миграцию.',
    's2.c2.title': 'Запросы как ожидаешь',
    's2.c2.body':
      'Пагинация, поиск, сорт и фильтры на ресурс. Кастомные resource API — на чтение и на запись.',
    's2.c3.title': 'Swagger сразу после publish',
    's2.c3.body': 'Без ручной спеки. Открыл /api/docs после публикации типа.',
    's3.eyebrow': 'Контент',
    's3.title': 'Редакторам — админка, не JSON',
    's3.lead':
      'Медиа, rich text, связи, ревизии, аналитика на дашборде — обычные CMS-задачи, уже на API.',
    's3.c1.title': 'Медиа и варианты',
    's3.c1.body':
      'Библиотека, кроп, optimize (WebP/JPEG/PNG), варианты. Pretty /media/{id}/{file} со static warm-cache после первого hit.',
    's3.c2.title': 'Rich text и таблицы',
    's3.c2.body':
      'Markdown для редакторов. Типизированные фильтры колонок, bulk delete и подписи связей в списке.',
    's3.c3.title': 'Связи и ревизии',
    's3.c3.body': 'Связи записей, история, экспорт/импорт пакетов ресурсов.',
    's4.eyebrow': 'Куда угодно',
    's4.title': 'Один файл. Любой PHP-хостинг.',
    's4.lead': 'Без Cloud. Self-host, обновление из админки, откат если swap оборвался.',
    's4.c1.title': 'install.php → zip с GitHub',
    's4.c1.body': 'Мастер качает latest, проверяет sha256, распаковывает. Если src/ уже есть — skip.',
    's4.c2.title': 'Shared-хостинг или Docker',
    's4.c2.body':
      'Правильная раскладка public_html — или <code>docker compose up</code> с MySQL и Adminer локально.',
    's4.c3.title': 'Атомарные обновления',
    's4.c3.body': 'Settings → System. Оборванный swap откатывается. restore.php — если всё же надо пихнуть.',
    's5.eyebrow': 'Безопасность в ядре',
    's5.title': 'Токены, роли, хуки — без зоопарка плагинов',
    's5.lead': 'Auth только Bearer. Остальное уже в core.',
    's5.c1.title': 'Editor, viewer, owner',
    's5.c1.body':
      'Контент-менеджер правит записи; viewer только смотрит админку. Owner → admin → editor → viewer, плюс ACL на ресурс.',
    's5.c2.title': 'OAuth + TOTP',
    's5.c2.body':
      'Google / Telegram и authenticator. Капча, IP-блоки, лимиты токена по origin/IP — без охоты за security-плагином.',
    's5.c3.title': 'HMAC webhooks',
    's5.c3.body': 'Пингуй SPA или воркеры при изменении контента. Ретраи в комплекте.',
    'why.eyebrow': 'Зачем HCMS',
    'why.title': 'SPA уже есть. CMS ты снова пишешь сам.',
    'why.lead': 'CRUD, миграции, админка, OpenAPI — по кругу. HCMS закрывает дыру на любом PHP-хосте.',
    'why.pain.title': 'Обычный путь',
    'why.pain.1': 'Самописная админка, которую никто не хочет трогать',
    'why.pain.2': 'SQL и валидация разъезжаются с API',
    'why.pain.3': 'Swagger вручную — или его нет',
    'why.pain.4': 'Редакторы лезут в репозиторий SPA',
    'why.win.title': 'С HCMS',
    'why.win.1': 'Схема → SQL, REST, админка, OpenAPI',
    'why.win.2': 'Publish сам гоняет миграцию',
    'why.win.3': 'Редакторы на /admin — SPA остаётся твоим',
    'why.win.4': 'install.php на shared-хостинге или Docker',
    'cmp.eyebrow': 'Сравнение',
    'cmp.title': 'Под PHP-хостинг, не под ещё один Node-стек',
    'cmp.lead': 'Когда shared-хостинг и две строки установки важнее облачного дашборда.',
    'cmp.col.feat': 'Возможность',
    'cmp.col.custom': 'Свой CRUD',
    'cmp.col.node': 'Node headless',
    'cmp.r1': 'Shared-хостинг / public_html',
    'cmp.r2': 'Без Node runtime',
    'cmp.r3': 'install.php → latest zip',
    'cmp.r4': 'Схема → OpenAPI + админка',
    'cmp.r5': 'MIT, self-host, без Cloud upsell',
    'faq.eyebrow': 'FAQ',
    'faq.title': 'Вопросы до curl',
    'faq.q1': 'Заведётся на shared-хостинге?',
    'faq.a1':
      'Да. Раскладка public_html — first-class: web-корень в docroot, src/ уровнем выше. PHP 8.3+ и MySQL / MariaDB.',
    'faq.q2': 'Нужен Node на сервере?',
    'faq.a2': 'Нет. Runtime — только PHP. Node нужен лишь чтобы собрать админку из исходников.',
    'faq.q3': 'Чем не Strapi / Directus?',
    'faq.a3':
      'Им нужен Node-хост. HCMS — классический PHP: один install.php, zip с GitHub, без Cloud-тарифа.',
    'faq.q4': 'Кто правит контент, а кто только смотрит админку?',
    'faq.a4':
      'Роли: owner → admin → editor → viewer. Контент-менеджеру — editor, для read-only — viewer. ACL сужает ресурсы.',
    'faq.q5': 'Миграции схемы безопасны?',
    'faq.a5':
      'Publish мигрирует сам. Деструктивные операции (drop field, change type) требуют confirmDestructive — иначе миграция падает целиком.',
    'faq.q6': 'Feature flags и translates — отдельный продукт?',
    'faq.a6':
      'Нет. Флаги (с опциональным A/B) и ключи переводов живут в той же админке и публичном API, что и контент — /api/features и /api/translates.',
    'cta.title': 'Две команды. Админка на твоём PHP-хосте.',
    'cta.body': 'Latest zip с GitHub. Админка /admin, API /api. MIT.',
    'footer.blurb':
      'API-first headless CMS. Схема, флаги, translates, медиа — REST и админка на выходе. MIT.',
    'footer.product': 'Продукт',
    'footer.source': 'Исходники',
    'announce':
      'HCMS {version} — MIT, PHP 8.3, без runtime-зависимостей. <a href="{url}" target="_blank" rel="noopener">Релиз</a>',
    'counter.plural': 'скачивание|скачивания|скачиваний',
    'title': 'HCMS — готовая админка для вашего SPA',
  },
};

const dict = (lang) => I18N[lang] ?? I18N.en;

const pathLang = () => (/\/ru(?:\/|$)/.test(location.pathname || '/') ? 'ru' : 'en');

const currentLang = () => pathLang();

function applyLang(lang) {
  const t = dict(lang);
  document.documentElement.lang = lang;
  document.title = t.title;
  document.querySelectorAll('[data-i18n]').forEach((el) => {
    const key = el.getAttribute('data-i18n');
    if (key && t[key]) {
      el.textContent = t[key];
    }
  });
  document.querySelectorAll('[data-i18n-html]').forEach((el) => {
    const key = el.getAttribute('data-i18n-html');
    if (key && t[key]) {
      el.innerHTML = t[key];
    }
  });
  document.querySelectorAll('[data-i18n-aria]').forEach((el) => {
    const key = el.getAttribute('data-i18n-aria');
    if (key && t[key]) {
      el.setAttribute('aria-label', t[key]);
      el.setAttribute('title', t[key]);
    }
  });
  document.querySelectorAll('[data-lang]').forEach((el) => {
    el.setAttribute('aria-pressed', String(el.getAttribute('data-lang') === lang));
  });
  applyPreview(state.preview);
  localStorage.setItem('hcms-lang', lang);
}

async function copyText(button) {
  const lines = [...(button.closest('.code')?.querySelectorAll('code') ?? [])];
  const text = lines.map((line) => line.textContent.trim()).join('\n');
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    return;
  }
  const t = dict(currentLang());
  button.classList.add('is-copied');
  button.setAttribute('aria-label', t['hero.copied']);
  button.setAttribute('title', t['hero.copied']);
  window.setTimeout(() => {
    button.classList.remove('is-copied');
    button.setAttribute('aria-label', t['hero.copy']);
    button.setAttribute('title', t['hero.copy']);
  }, 1400);
}

async function loadRelease() {
  try {
    const res = await fetch(`https://api.github.com/repos/${REPO}/releases/latest`);
    if (!res.ok) {
      return;
    }
    const data = await res.json();
    const tag = typeof data.tag_name === 'string' ? data.tag_name : '';
    const version = tag.replace(/^v/, '');
    if (!version) {
      return;
    }
    const bar = document.getElementById('announce');
    const lang = currentLang();
    const html = dict(lang)
      .announce.replace('{version}', version)
      .replace('{url}', data.html_url || `https://github.com/${REPO}/releases`);
    if (bar) {
      bar.innerHTML = html;
      bar.hidden = false;
      bar.dataset.version = version;
      bar.dataset.releaseUrl = data.html_url || '';
    }
  } catch {
    // Announce bar is optional: GitHub being down must not break the page.
  }
}

function pluralForm(count, lang) {
  if (lang !== 'ru') {
    return count === 1 ? 0 : 1;
  }
  const tail = count % 10;
  const teen = count % 100;
  if (tail === 1 && teen !== 11) {
    return 0;
  }

  return tail >= 2 && tail <= 4 && (teen < 12 || teen > 14) ? 1 : 2;
}

function renderCounter() {
  const el = document.getElementById('counter');
  if (!el || state.downloads === null) {
    return;
  }
  const lang = currentLang();
  const forms = dict(lang)['counter.plural'].split('|');
  const formatted = new Intl.NumberFormat(lang === 'ru' ? 'ru-RU' : 'en-US').format(state.downloads);
  el.textContent = `${formatted} ${forms[pluralForm(state.downloads, lang)]}`;
  el.hidden = false;
}

function setupStickyCta() {
  const sticky = document.getElementById('sticky-cta');
  const heroCta = document.getElementById('hero-cta');
  if (!sticky || !heroCta || !('IntersectionObserver' in window)) {
    return;
  }
  const mq = window.matchMedia('(max-width: 860px)');
  const sync = (visible) => {
    sticky.hidden = !mq.matches || visible;
  };
  const io = new IntersectionObserver((entries) => {
    const entry = entries[0];
    sync(Boolean(entry && entry.isIntersecting));
  });
  io.observe(heroCta);
  if (mq.addEventListener) {
    mq.addEventListener('change', () => {
      if (!mq.matches) {
        sticky.hidden = true;
      }
    });
  }
}

async function loadDownloads() {
  try {
    const res = await fetch(DOWNLOADS_ENDPOINT, { headers: { Accept: 'application/json' } });
    if (!res.ok) {
      return;
    }
    const body = await res.json();
    const total = Number(body ? body.total : NaN);
    if (!Number.isFinite(total)) {
      return;
    }
    state.downloads = total;
    renderCounter();
  } catch {
    // Counter is decoration: an unreachable CMS must not break the page.
  }
}

function bumpCounter() {
  if (state.downloads !== null) {
    state.downloads += 1;
    renderCounter();
  }
}

function refreshAnnounce() {
  const bar = document.getElementById('announce');
  if (!bar || bar.hidden || !bar.dataset.version) {
    return;
  }
  bar.innerHTML = dict(currentLang())
    .announce.replace('{version}', bar.dataset.version)
    .replace('{url}', bar.dataset.releaseUrl || `https://github.com/${REPO}/releases`);
}

function applyPreview(theme, opts) {
  const user = Boolean(opts && opts.user);
  state.preview = theme === 'dark' ? 'dark' : 'light';
  const t = dict(currentLang());
  const frame = document.querySelector('.hero-frame');
  if (frame) {
    frame.setAttribute('data-theme', state.preview);
  }
  document.querySelectorAll('.hero-shot').forEach((img) => {
    const on = img.getAttribute('data-shot') === state.preview;
    img.classList.toggle('is-active', on);
    if (img.getAttribute('data-shot') === 'light') {
      img.alt = t['preview.altLight'];
    } else if (img.getAttribute('data-shot') === 'dark') {
      img.alt = t['preview.altDark'];
    }
  });
  document.querySelectorAll('[data-preview]').forEach((btn) => {
    btn.classList.toggle('is-active', btn.getAttribute('data-preview') === state.preview);
  });
  if (user) {
    pausePreview(12000);
  }
}

let previewTimer = null;
let previewResume = null;

function stopPreviewTimer() {
  if (previewTimer) {
    window.clearInterval(previewTimer);
    previewTimer = null;
  }
}

function startPreviewTimer() {
  stopPreviewTimer();
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }
  previewTimer = window.setInterval(() => {
    applyPreview(state.preview === 'light' ? 'dark' : 'light');
  }, 4800);
}

function pausePreview(ms) {
  stopPreviewTimer();
  if (previewResume) {
    window.clearTimeout(previewResume);
  }
  previewResume = window.setTimeout(() => {
    previewResume = null;
    startPreviewTimer();
  }, ms);
}

document.querySelectorAll('[data-preview]').forEach((btn) => {
  btn.addEventListener('click', () => applyPreview(btn.getAttribute('data-preview') || 'light', { user: true }));
});

const stage = document.querySelector('.hero-stage');
if (stage) {
  stage.addEventListener('mouseenter', () => pausePreview(999999));
  stage.addEventListener('mouseleave', () => {
    if (previewResume) {
      window.clearTimeout(previewResume);
      previewResume = null;
    }
    startPreviewTimer();
  });
  stage.addEventListener('focusin', () => pausePreview(999999));
  stage.addEventListener('focusout', (e) => {
    if (!stage.contains(e.relatedTarget)) {
      startPreviewTimer();
    }
  });
}

document.querySelectorAll('.js-download').forEach((link) => {
  link.addEventListener('click', bumpCounter);
});

document.querySelectorAll('.js-copy').forEach((btn) => {
  btn.addEventListener('click', () => copyText(btn));
});

applyLang(currentLang());
startPreviewTimer();
setupStickyCta();
loadRelease();
loadDownloads();
