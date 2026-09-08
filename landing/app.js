const REPO = 'lnked/hcms';
const DOWNLOAD_LATEST = `https://github.com/${REPO}/releases/latest/download/install.php`;
const DOWNLOAD_RAW = `https://github.com/${REPO}/raw/main/install.php`;

// Download counter lives in our own HCMS instance: public create writes a row,
// public read exposes only meta.total via ?limit=1.
const API_BASE = 'https://api.2js.ru';
const DOWNLOADS_SLUG = 'downloads';

const state = { version: '', downloads: null };

const I18N = {
  en: {
    'skip': 'Skip to content',
    'nav.features': 'Features',
    'nav.install': 'Install',
    'nav.cta': 'Get install.php',
    'hero.title': 'A ready-made admin for your SPA',
    'hero.sub': 'Describe the schema. Get REST, OpenAPI, and CRUD. Your SPA stays the frontend.',
    'hero.download': 'Download install.php',
    'hero.github': 'View on GitHub',
    'hero.copy': 'Copy',
    'hero.copied': 'Copied',
    'pill.admin': 'Admin panel',
    'pill.schema': 'Schema builder',
    'pill.host': 'Self-host',
    'trust.deps': 'Zero runtime deps',
    'step1.title': 'Drop the file',
    'step1.body': 'Put install.php in the site root — shared hosting is fine.',
    'step2.title': 'Open the wizard',
    'step2.body': 'Hit <code>/install.php</code>. It pulls the latest zip from GitHub Releases.',
    'step3.title': 'Point your SPA',
    'step3.body': 'Fill DB + admin, then call /api from React, Vue, or Next.',
    's1.eyebrow': 'For your SPA',
    's1.title': 'Plug in an admin. Keep the frontend.',
    's1.lead':
      'HCMS is the backend your SPA is missing: visual schema, REST, and a real /admin — no custom CRUD.',
    's1.c1.title': 'Drop-in /admin',
    's1.c1.body': 'Ship a full admin UI with your PHP host. Editors never touch the SPA repo.',
    's1.c2.title': 'REST for React / Vue / Next',
    's1.c2.body': 'Public /api/{slug} with pagination, search, sort, and filter. Bearer tokens, CORS included.',
    's1.c3.title': 'OpenAPI / Swagger',
    's1.c3.body': 'Spec at /api/openapi.json, UI at /api/docs — generated from the schema you publish.',
    's2.eyebrow': 'Build APIs fast',
    's2.title': 'From content type to live /api/{slug}',
    's2.lead': 'Schema is the source of truth for SQL, validation, REST, Admin UI, and OpenAPI.',
    's2.c1.title': 'Field types that cover real apps',
    's2.c1.body': 'String, richtext, relation, media, slug, enum, json, dates — publish and get a migration.',
    's2.c2.title': 'Query the way you expect',
    's2.c2.body': 'Per-resource pagination, search, sort, and filter. Custom GET projections when you need them.',
    's2.c3.title': 'Swagger the moment you publish',
    's2.c3.body': 'No hand-written spec. Open /api/docs after you hit publish on a content type.',
    's3.eyebrow': 'Content tools',
    's3.title': 'Editors get a real admin, not a JSON dump',
    's3.lead': 'Media, rich text, relations, revisions — the usual CMS jobs, wired to your API.',
    's3.c1.title': 'Media + variants',
    's3.c1.body': 'Library, crop editor, image variants. Public /media/{id} for the SPA.',
    's3.c2.title': 'Rich text',
    's3.c2.body': 'Markdown for editors. Structured fields for everything else.',
    's3.c3.title': 'Relations & revisions',
    's3.c3.body': 'Link entries, keep history, export/import resource packages.',
    's4.eyebrow': 'Deploy anywhere',
    's4.title': 'One file. Any PHP host.',
    's4.lead': 'No Cloud upsell. Self-host, update in-app, roll back if a swap dies mid-flight.',
    's4.c1.title': 'install.php → GitHub zip',
    's4.c1.body': 'Wizard downloads the latest release, verifies sha256, unzip. If src/ is already there, skip.',
    's4.c2.title': 'Shared hosting layout',
    's4.c2.body': 'Installs into public_html correctly: public files in the web root, src/ one level up.',
    's4.c3.title': 'Atomic updates',
    's4.c3.body': 'Settings → System. Interrupted swap rolls back. restore.php if the box still needs a shove.',
    's5.eyebrow': 'Secure by default',
    's5.title': 'Tokens, roles, hooks — without a plugin zoo',
    's5.lead': 'Auth is Bearer-only. The rest ships in core.',
    's5.c1.title': 'RBAC',
    's5.c1.body': 'Owner, admin, editor, viewer. Per-resource public CRUD flags.',
    's5.c2.title': 'TOTP 2FA',
    's5.c2.body': 'Plus captcha and IP blocks. No “install a security plugin” step.',
    's5.c3.title': 'HMAC webhooks',
    's5.c3.body': 'Ping your SPA or workers on content changes. Retries included.',
    'cta.title': 'Put the admin next to your SPA',
    'cta.body': 'One PHP file. Latest zip from GitHub. Admin at /admin, API at /api.',
    'footer.blurb': 'API-first headless CMS. Schema in, REST and admin out. MIT.',
    'footer.product': 'Product',
    'footer.source': 'Source',
    'announce': 'HCMS {version} is out — MIT, PHP 8.3, zero runtime deps. <a href="{url}">Release notes</a>',
    'counter.plural': 'download|downloads|downloads',
    'title': 'HCMS — a ready-made admin for your SPA',
  },
  ru: {
    'skip': 'К содержимому',
    'nav.features': 'Возможности',
    'nav.install': 'Установка',
    'nav.cta': 'Скачать install.php',
    'hero.title': 'Готовая админка для вашего SPA',
    'hero.sub': 'Опиши схему — получи REST, OpenAPI и CRUD. Фронт остаётся фронтом.',
    'hero.download': 'Скачать install.php',
    'hero.github': 'Код на GitHub',
    'hero.copy': 'Копировать',
    'hero.copied': 'Скопировано',
    'pill.admin': 'Админка',
    'pill.schema': 'Конструктор схемы',
    'pill.host': 'Self-host',
    'trust.deps': 'Без runtime-зависимостей',
    'step1.title': 'Положи файл',
    'step1.body': 'install.php в корень сайта — shared-хостинг тоже ок.',
    'step2.title': 'Открой мастер',
    'step2.body': 'Зайди на <code>/install.php</code>. Он скачает latest zip из GitHub Releases.',
    'step3.title': 'Подключи SPA',
    'step3.body': 'БД и админ — и дергай /api из React, Vue или Next.',
    's1.eyebrow': 'Для вашего SPA',
    's1.title': 'Админка подключается. Фронт остаётся твоим.',
    's1.lead':
      'HCMS — бэкенд, которого не хватает SPA: визуальная схема, REST и нормальный /admin. Без самописного CRUD.',
    's1.c1.title': 'Drop-in /admin',
    's1.c1.body': 'Полная админка на PHP-хостинге. Редакторы не лезут в репозиторий фронта.',
    's1.c2.title': 'REST для React / Vue / Next',
    's1.c2.body': 'Публичный /api/{slug}: пагинация, поиск, сорт, фильтры. Bearer и CORS из коробки.',
    's1.c3.title': 'OpenAPI / Swagger',
    's1.c3.body': 'Спека /api/openapi.json, UI /api/docs — из опубликованной схемы.',
    's2.eyebrow': 'API за минуты',
    's2.title': 'От типа контента до живого /api/{slug}',
    's2.lead': 'Схема — source of truth для SQL, валидации, REST, админки и OpenAPI.',
    's2.c1.title': 'Типы полей под реальные приложения',
    's2.c1.body': 'String, richtext, relation, media, slug, enum, json, даты — опубликовал и получил миграцию.',
    's2.c2.title': 'Запросы как ожидаешь',
    's2.c2.body': 'Пагинация, поиск, сорт и фильтры на ресурс. Кастомные GET-проекции — когда надо.',
    's2.c3.title': 'Swagger сразу после publish',
    's2.c3.body': 'Без ручной спеки. Открыл /api/docs после публикации типа.',
    's3.eyebrow': 'Контент',
    's3.title': 'Редакторам — админка, не JSON',
    's3.lead': 'Медиа, rich text, связи, ревизии — обычные CMS-задачи, уже на API.',
    's3.c1.title': 'Медиа и варианты',
    's3.c1.body': 'Библиотека, кроп, варианты картинок. Публичный /media/{id} для SPA.',
    's3.c2.title': 'Rich text',
    's3.c2.body': 'Markdown для редакторов. Структурные поля — для всего остального.',
    's3.c3.title': 'Связи и ревизии',
    's3.c3.body': 'Связи записей, история, экспорт/импорт пакетов ресурсов.',
    's4.eyebrow': 'Куда угодно',
    's4.title': 'Один файл. Любой PHP-хостинг.',
    's4.lead': 'Без Cloud. Self-host, обновление из админки, откат если swap оборвался.',
    's4.c1.title': 'install.php → zip с GitHub',
    's4.c1.body': 'Мастер качает latest, проверяет sha256, распаковывает. Если src/ уже есть — skip.',
    's4.c2.title': 'Раскладка shared-хостинга',
    's4.c2.body': 'Корректная установка в public_html: публичные файлы в корне, src/ уровнем выше.',
    's4.c3.title': 'Атомарные обновления',
    's4.c3.body': 'Settings → System. Оборванный swap откатывается. restore.php — если всё же надо пихнуть.',
    's5.eyebrow': 'Безопасность в ядре',
    's5.title': 'Токены, роли, хуки — без зоопарка плагинов',
    's5.lead': 'Auth только Bearer. Остальное уже в core.',
    's5.c1.title': 'RBAC',
    's5.c1.body': 'Owner, admin, editor, viewer. Публичные CRUD-флаги на ресурс.',
    's5.c2.title': 'TOTP 2FA',
    's5.c2.body': 'Плюс капча и IP-блоки. Без шага «поставь security-плагин».',
    's5.c3.title': 'HMAC webhooks',
    's5.c3.body': 'Пингуй SPA или воркеры при изменении контента. Ретраи в комплекте.',
    'cta.title': 'Поставь админку рядом с SPA',
    'cta.body': 'Один PHP-файл. Latest zip с GitHub. Админка /admin, API /api.',
    'footer.blurb': 'API-first headless CMS. Схема на входе, REST и админка на выходе. MIT.',
    'footer.product': 'Продукт',
    'footer.source': 'Исходники',
    'announce': 'HCMS {version} — MIT, PHP 8.3, без runtime-зависимостей. <a href="{url}">Релиз</a>',
    'counter.plural': 'скачивание|скачивания|скачиваний',
    'title': 'HCMS — готовая админка для вашего SPA',
  },
};

const dict = (lang) => I18N[lang] ?? I18N.en;

const currentLang = () => (localStorage.getItem('hcms-lang') === 'ru' ? 'ru' : 'en');

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
  document.querySelectorAll('[data-lang]').forEach((btn) => {
    btn.setAttribute('aria-pressed', String(btn.getAttribute('data-lang') === lang));
  });
  localStorage.setItem('hcms-lang', lang);
}

function curlLine(url) {
  return `curl -fsSL -o install.php ${url}`;
}

function setDownloadUrl(url) {
  document.querySelectorAll('.js-download').forEach((a) => {
    a.setAttribute('href', url);
  });
  const line = curlLine(url);
  const main = document.getElementById('curl');
  if (main) {
    main.textContent = line;
  }
  document.querySelectorAll('.js-curl-clone').forEach((el) => {
    el.textContent = line;
  });
}

async function copyText(button) {
  const code = button.closest('.code')?.querySelector('code');
  const text = code ? code.textContent : '';
  try {
    await navigator.clipboard.writeText(text);
  } catch {
    return;
  }
  trackDownload('curl');
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
    const assets = Array.isArray(data.assets) ? data.assets : [];
    const hasInstall = assets.some((asset) => asset && asset.name === 'install.php');
    setDownloadUrl(hasInstall ? DOWNLOAD_LATEST : DOWNLOAD_RAW);

    if (!version) {
      return;
    }
    state.version = version;
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
    setDownloadUrl(DOWNLOAD_RAW);
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

async function loadDownloads() {
  try {
    const res = await fetch(`${API_BASE}/api/${DOWNLOADS_SLUG}?limit=1`, {
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) {
      return;
    }
    const body = await res.json();
    const total = Number(body && body.meta ? body.meta.total : NaN);
    if (!Number.isFinite(total)) {
      return;
    }
    state.downloads = total;
    renderCounter();
  } catch {
    // Counter is decoration: an unreachable CMS must not break the page.
  }
}

function referrerHost() {
  try {
    return document.referrer ? new URL(document.referrer).hostname : '';
  } catch {
    return '';
  }
}

function trackDownload(source) {
  if (state.downloads !== null) {
    state.downloads += 1;
    renderCounter();
  }
  // keepalive: the click navigates to GitHub, the request must survive it.
  fetch(`${API_BASE}/api/${DOWNLOADS_SLUG}`, {
    method: 'POST',
    keepalive: true,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      asset: 'install.php',
      version: state.version,
      source,
      referrer: referrerHost(),
    }),
  }).catch(() => {});
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

document.querySelectorAll('[data-lang]').forEach((btn) => {
  btn.addEventListener('click', () => {
    applyLang(btn.getAttribute('data-lang') || 'en');
    refreshAnnounce();
    renderCounter();
  });
});

document.querySelectorAll('.js-download').forEach((link) => {
  link.addEventListener('click', () => trackDownload('button'));
});

document.querySelectorAll('.js-copy').forEach((btn) => {
  btn.addEventListener('click', () => copyText(btn));
});

applyLang(currentLang());
loadRelease();
loadDownloads();
