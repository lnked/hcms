#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seed demo resources + random entries via Admin API.
 *
 * Covers all field types: string, text, integer, float, boolean, date, datetime,
 * email, url, uuid, json, enum, image, file, relation (manyToOne + oneToMany).
 *
 * Usage:
 *   php scripts/seed-demo.php --email=admin@example.com --password=secret
 *   php scripts/seed-demo.php --url=http://127.0.0.1:8080 --email=... --password=... --min=100 --max=300
 *   php scripts/seed-demo.php ... --force   # delete existing demo_* first
 *
 * Env fallbacks: CMS_BASE_URL / APP_URL, CMS_EMAIL, CMS_PASSWORD
 */

$opts = getopt('', [
    'url::',
    'email::',
    'password::',
    'min::',
    'max::',
    'force',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, <<<TXT
Seed demo resources for HCMS.

  --url=URL          Base URL (default: http://127.0.0.1:8080)
  --email=EMAIL      Admin email
  --password=PASS    Admin password
  --min=N            Min entries per resource (default: 100)
  --max=N            Max entries per resource (default: 300)
  --force            Delete existing demo_* resources before seeding

TXT);
    exit(0);
}

$baseUrl = rtrim((string) ($opts['url'] ?? getenv('CMS_BASE_URL') ?: getenv('APP_URL') ?: 'http://127.0.0.1:8080'), '/');
$email = (string) ($opts['email'] ?? getenv('CMS_EMAIL') ?: '');
$password = (string) ($opts['password'] ?? getenv('CMS_PASSWORD') ?: '');
$minCount = max(1, (int) ($opts['min'] ?? 100));
$maxCount = max($minCount, (int) ($opts['max'] ?? 300));
$force = isset($opts['force']);

if ($email === '' || $password === '') {
    fwrite(STDERR, "Error: --email and --password are required (or CMS_EMAIL / CMS_PASSWORD).\n");
    exit(1);
}

$client = new DemoApiClient($baseUrl);
$faker = new DemoFaker();

out("Login → {$baseUrl}");
$auth = $client->request('POST', '/admin/api/auth/login', [
    'email' => $email,
    'password' => $password,
]);
$token = (string) ($auth['data']['token'] ?? '');
if ($token === '') {
    fwrite(STDERR, "Error: login failed — no token in response.\n");
    exit(1);
}
$client->setToken($token);
out('OK, admin token acquired');

if ($force) {
    $listed = $client->request('GET', '/admin/api/resources');
    $resources = $listed['data'] ?? [];
    if (!is_array($resources)) {
        $resources = [];
    }
    foreach ($resources as $row) {
        if (!is_array($row)) {
            continue;
        }
        $slug = (string) ($row['slug'] ?? '');
        if (!str_starts_with($slug, 'demo_')) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        out("Force: delete resource #{$id} ({$slug})");
        $client->request('DELETE', '/admin/api/resources/' . $id);
    }
}

out('Upload demo media…');
$imageIds = [];
$fileIds = [];
for ($i = 1; $i <= 8; $i++) {
    $png = $faker->tinyPngPath("demo-image-{$i}.png");
    $txt = $faker->tinyTextPath("demo-file-{$i}.txt");
    try {
        $imageIds[] = (int) $client->upload($png, 'image/png')['data']['id'];
        $fileIds[] = (int) $client->upload($txt, 'text/plain')['data']['id'];
    } finally {
        @unlink($png);
        @unlink($txt);
    }
}
out(sprintf('  media: %d images, %d files', count($imageIds), count($fileIds)));

// ─── 1. Authors ─────────────────────────────────────────────────────────────
$authorsCount = $faker->countBetween($minCount, $maxCount);
$authors = seedResource($client, $faker, [
    'label' => 'Demo Authors',
    'name' => 'demo_authors',
    'slug' => 'demo_authors',
    'description' => 'Demo authors (string, text, email, url, boolean, uuid + oneToMany)',
    'fields' => [
        field('name', 'string', 'Name', required: true, searchable: true, sortable: true, filterable: true, config: ['maxLength' => 120]),
        field('bio', 'text', 'Bio', searchable: true, nullable: true),
        field('email', 'email', 'Email', required: true, unique: true, searchable: true, filterable: true),
        field('website', 'url', 'Website', nullable: true),
        field('is_active', 'boolean', 'Active', filterable: true, sortable: true, default: true),
        field('public_id', 'uuid', 'Public ID', unique: true, filterable: true),
        field('articles', 'relation', 'Articles', readable: true, writable: false, config: [
            'cardinality' => 'oneToMany',
            'relatedSlug' => 'demo_articles',
            'labelField' => 'title',
            'foreignKey' => 'author_id',
        ]),
    ],
    'count' => $authorsCount,
    'entry' => static function (DemoFaker $f, int $i) : array {
        return [
            'name' => $f->personName(),
            'bio' => $f->paragraph(2, 5),
            'email' => $f->email("author{$i}"),
            'website' => $f->maybe(0.7) ? $f->url() : null,
            'is_active' => $f->bool(0.85),
            'public_id' => $f->uuid(),
        ];
    },
]);
$authorIds = $authors['entryIds'];
out("Authors ready: {$authorsCount} entries");

// ─── 2. Categories ──────────────────────────────────────────────────────────
$categoriesCount = $faker->countBetween($minCount, min($maxCount, 180));
$categories = seedResource($client, $faker, [
    'label' => 'Demo Categories',
    'name' => 'demo_categories',
    'slug' => 'demo_categories',
    'description' => 'Demo categories (string, text, enum, integer, boolean)',
    'fields' => [
        field('title', 'string', 'Title', required: true, searchable: true, sortable: true, config: ['maxLength' => 160]),
        field('description', 'text', 'Description', nullable: true, searchable: true),
        field('kind', 'enum', 'Kind', required: true, filterable: true, sortable: true, config: [
            'options' => ['news', 'guide', 'review', 'tutorial', 'other'],
        ]),
        field('sort_order', 'integer', 'Sort', filterable: true, sortable: true, default: 0),
        field('is_featured', 'boolean', 'Featured', filterable: true, default: false),
    ],
    'count' => $categoriesCount,
    'entry' => static function (DemoFaker $f, int $i) : array {
        return [
            'title' => $f->title('Category'),
            'description' => $f->maybe(0.8) ? $f->paragraph(1, 3) : null,
            'kind' => $f->pick(['news', 'guide', 'review', 'tutorial', 'other']),
            'sort_order' => $f->int(0, 1000),
            'is_featured' => $f->bool(0.25),
        ];
    },
]);
$categoryIds = $categories['entryIds'];
out("Categories ready: {$categoriesCount} entries");

// ─── 3. Articles (kitchen sink) ─────────────────────────────────────────────
$articlesCount = $faker->countBetween($minCount, $maxCount);
$articles = seedResource($client, $faker, [
    'label' => 'Demo Articles',
    'name' => 'demo_articles',
    'slug' => 'demo_articles',
    'description' => 'Kitchen-sink resource covering every field type',
    'fields' => [
        field('title', 'string', 'Title', required: true, searchable: true, sortable: true, config: ['maxLength' => 200]),
        field('slug', 'string', 'Slug', required: true, unique: true, filterable: true, config: ['maxLength' => 200]),
        field('excerpt', 'text', 'Excerpt', nullable: true, searchable: true),
        field('body', 'text', 'Body', required: true, searchable: true),
        field('views', 'integer', 'Views', filterable: true, sortable: true, default: 0),
        field('rating', 'float', 'Rating', filterable: true, sortable: true, nullable: true),
        field('is_published', 'boolean', 'Published', filterable: true, sortable: true, default: false),
        field('published_on', 'date', 'Published on', nullable: true, filterable: true, sortable: true),
        field('published_at', 'datetime', 'Published at', nullable: true, filterable: true, sortable: true),
        field('contact_email', 'email', 'Contact email', nullable: true),
        field('canonical_url', 'url', 'Canonical URL', nullable: true),
        field('external_id', 'uuid', 'External ID', unique: true, filterable: true),
        field('meta', 'json', 'Meta', nullable: true),
        field('status', 'enum', 'Status', required: true, filterable: true, sortable: true, config: [
            'options' => ['draft', 'review', 'published', 'archived'],
        ]),
        field('cover', 'image', 'Cover', nullable: true),
        field('attachment', 'file', 'Attachment', nullable: true),
        field('author_id', 'relation', 'Author', filterable: true, config: [
            'cardinality' => 'manyToOne',
            'relatedSlug' => 'demo_authors',
            'labelField' => 'name',
        ]),
        field('category_id', 'relation', 'Category', nullable: true, filterable: true, config: [
            'cardinality' => 'manyToOne',
            'relatedSlug' => 'demo_categories',
            'labelField' => 'title',
        ]),
    ],
    'count' => $articlesCount,
    'entry' => static function (DemoFaker $f, int $i) use ($authorIds, $categoryIds, $imageIds, $fileIds): array {
        $status = $f->pick(['draft', 'review', 'published', 'archived']);
        $published = $status === 'published' || $f->bool(0.4);
        $day = $f->date();
        $dt = $f->datetime();

        return [
            'title' => $f->title('Article'),
            'slug' => 'article-' . $i . '-' . $f->slugTail(),
            'excerpt' => $f->maybe(0.75) ? $f->sentence(8, 18) : null,
            'body' => $f->paragraph(3, 8),
            'views' => $f->int(0, 50000),
            'rating' => $f->maybe(0.8) ? $f->float(0, 5, 1) : null,
            'is_published' => $published,
            'published_on' => $published ? $day : ($f->maybe(0.3) ? $day : null),
            'published_at' => $published ? $dt : ($f->maybe(0.2) ? $dt : null),
            'contact_email' => $f->maybe(0.5) ? $f->email("article{$i}") : null,
            'canonical_url' => $f->maybe(0.4) ? $f->url('/posts/' . $i) : null,
            'external_id' => $f->uuid(),
            'meta' => $f->maybe(0.7) ? [
                'tags' => $f->tags(1, 5),
                'seo' => ['title' => $f->sentence(3, 6), 'noindex' => $f->bool(0.1)],
                'score' => $f->int(1, 100),
            ] : null,
            'status' => $status,
            'cover' => $f->maybe(0.6) ? $f->pick($imageIds) : null,
            'attachment' => $f->maybe(0.35) ? $f->pick($fileIds) : null,
            'author_id' => $f->pick($authorIds),
            'category_id' => $f->maybe(0.85) ? $f->pick($categoryIds) : null,
        ];
    },
]);
out("Articles ready: {$articlesCount} entries");

// ─── 4. Events ──────────────────────────────────────────────────────────────
$eventsCount = $faker->countBetween($minCount, $maxCount);
seedResource($client, $faker, [
    'label' => 'Demo Events',
    'name' => 'demo_events',
    'slug' => 'demo_events',
    'description' => 'Demo events (date/datetime/json/enum/float focus)',
    'fields' => [
        field('name', 'string', 'Name', required: true, searchable: true, sortable: true, config: ['maxLength' => 180]),
        field('summary', 'text', 'Summary', nullable: true, searchable: true),
        field('starts_on', 'date', 'Starts on', required: true, filterable: true, sortable: true),
        field('starts_at', 'datetime', 'Starts at', required: true, filterable: true, sortable: true),
        field('ends_at', 'datetime', 'Ends at', nullable: true, sortable: true),
        field('capacity', 'integer', 'Capacity', nullable: true, filterable: true, sortable: true),
        field('ticket_price', 'float', 'Ticket price', nullable: true, filterable: true, sortable: true),
        field('is_online', 'boolean', 'Online', filterable: true, default: false),
        field('registration_url', 'url', 'Registration URL', nullable: true),
        field('payload', 'json', 'Payload', nullable: true),
        field('level', 'enum', 'Level', required: true, filterable: true, config: [
            'options' => ['beginner', 'intermediate', 'advanced'],
        ]),
        field('cover', 'image', 'Cover', nullable: true),
        field('organizer_id', 'relation', 'Organizer', nullable: true, filterable: true, config: [
            'cardinality' => 'manyToOne',
            'relatedSlug' => 'demo_authors',
            'labelField' => 'name',
        ]),
    ],
    'count' => $eventsCount,
    'entry' => static function (DemoFaker $f, int $i) use ($authorIds, $imageIds): array {
        $start = $f->datetimeRelative(-30, 120);
        $end = $f->maybe(0.7) ? date('Y-m-d H:i:s', strtotime($start . ' +' . $f->int(1, 8) . ' hours')) : null;

        return [
            'name' => $f->title('Event'),
            'summary' => $f->maybe(0.8) ? $f->paragraph(1, 3) : null,
            'starts_on' => substr($start, 0, 10),
            'starts_at' => $start,
            'ends_at' => $end,
            'capacity' => $f->maybe(0.7) ? $f->int(10, 2000) : null,
            'ticket_price' => $f->maybe(0.65) ? $f->float(0, 499, 2) : null,
            'is_online' => $f->bool(0.4),
            'registration_url' => $f->maybe(0.6) ? $f->url('/events/' . $i) : null,
            'payload' => [
                'venue' => $f->pick(['Hall A', 'Hall B', 'Online', 'Rooftop', 'Studio']),
                'speakers' => $f->int(1, 6),
                'tracks' => $f->tags(1, 4),
            ],
            'level' => $f->pick(['beginner', 'intermediate', 'advanced']),
            'cover' => $f->maybe(0.5) ? $f->pick($imageIds) : null,
            'organizer_id' => $f->maybe(0.9) ? $f->pick($authorIds) : null,
        ];
    },
]);
out("Events ready: {$eventsCount} entries");

out('');
out('Done. Demo resources:');
out('  - demo_authors     (' . $authorsCount . ')');
out('  - demo_categories  (' . $categoriesCount . ')');
out('  - demo_articles    (' . $articlesCount . ')  ← all field types');
out('  - demo_events      (' . $eventsCount . ')');
out('Public read enabled on all of them (/api/{slug}).');

// ═══════════════════════════════════════════════════════════════════════════

/**
 * @param array{
 *   label: string,
 *   name: string,
 *   slug: string,
 *   description: string,
 *   fields: list<array<string, mixed>>,
 *   count: int,
 *   entry: callable(DemoFaker, int): array<string, mixed>
 * } $spec
 * @return array{id: int, entryIds: list<int>}
 */
function seedResource(DemoApiClient $client, DemoFaker $faker, array $spec): array
{
    out('');
    out("Create resource {$spec['slug']}…");

    $created = $client->request('POST', '/admin/api/resources', [
        'label' => $spec['label'],
        'name' => $spec['name'],
        'slug' => $spec['slug'],
        'description' => $spec['description'],
        'settings' => [
            'apiEnabled' => true,
            'public' => [
                'read' => true,
                'create' => false,
                'update' => false,
                'delete' => false,
            ],
        ],
    ]);

    $id = (int) ($created['data']['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Failed to create resource ' . $spec['slug']);
    }

    out("  fields → #{$id}");
    $client->request('PUT', '/admin/api/resources/' . $id . '/fields', [
        'fields' => $spec['fields'],
    ]);

    out('  publish + migrate');
    $client->request('POST', '/admin/api/resources/' . $id . '/publish', [
        'confirmDestructive' => false,
    ]);

    $count = $spec['count'];
    out("  seeding {$count} entries…");
    $entryIds = [];
    $progressEvery = max(25, (int) floor($count / 10));

    for ($i = 1; $i <= $count; $i++) {
        /** @var array<string, mixed> $payload */
        $payload = ($spec['entry'])($faker, $i);
        $entry = $client->request('POST', '/admin/api/resources/' . $id . '/entries', $payload);
        $entryId = (int) ($entry['data']['id'] ?? 0);
        if ($entryId > 0) {
            $entryIds[] = $entryId;
        }
        if ($i % $progressEvery === 0 || $i === $count) {
            out("    {$i}/{$count}");
        }
    }

    return ['id' => $id, 'entryIds' => $entryIds];
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function field(
    string $name,
    string $type,
    string $label,
    bool $required = false,
    bool $nullable = false,
    bool $unique = false,
    bool $searchable = false,
    bool $sortable = false,
    bool $filterable = false,
    bool $readable = true,
    bool $writable = true,
    mixed $default = null,
    array $config = [],
): array {
    if (!$required && !$nullable && $default === null && $type !== 'relation') {
        $nullable = true;
    }

    $out = [
        'name' => $name,
        'type' => $type,
        'label' => $label,
        'required' => $required,
        'nullable' => $nullable,
        'unique' => $unique,
        'indexed' => $unique || $filterable,
        'searchable' => $searchable,
        'sortable' => $sortable,
        'filterable' => $filterable,
        'readable' => $readable,
        'writable' => $writable,
        'config' => $config,
    ];
    if ($default !== null) {
        $out['default'] = $default;
    }

    return $out;
}

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

final class DemoApiClient
{
    private string $token = '';

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $json = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $headers = ['Accept: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 120,
        ];

        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new RuntimeException('json_encode failed');
            }
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP error: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseBody = substr($raw, $headerSize);
        if ($status === 204) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON from {$method} {$path} (HTTP {$status}): " . substr($responseBody, 0, 300));
        }

        if ($status >= 400) {
            $msg = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $responseBody);
            throw new RuntimeException("{$method} {$path} → HTTP {$status}: {$msg}");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(string $absolutePath, string $mime): array
    {
        $ch = curl_init($this->baseUrl . '/admin/api/media');
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $cfile = new CURLFile($absolutePath, $mime, basename($absolutePath));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_POSTFIELDS => ['file' => $cfile],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Upload failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $status >= 400) {
            $msg = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $raw)
                : (string) $raw;
            throw new RuntimeException("Upload → HTTP {$status}: {$msg}");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

final class DemoFaker
{
    /** @var list<string> */
    private array $first = [
        'Alex', 'Jordan', 'Sam', 'Taylor', 'Casey', 'Morgan', 'Riley', 'Quinn',
        'Ava', 'Noah', 'Mia', 'Leo', 'Zoe', 'Ethan', 'Luna', 'Owen', 'Ivy', 'Kai',
        'Sofia', 'Marcus', 'Elena', 'Nikita', 'Olga', 'Dmitry', 'Anna', 'Ivan',
    ];

    /** @var list<string> */
    private array $last = [
        'Smith', 'Johnson', 'Lee', 'Brown', 'Garcia', 'Martinez', 'Davis', 'Wilson',
        'Anderson', 'Thomas', 'Ivanov', 'Petrov', 'Sidorov', 'Kuznetsov', 'Volkov',
        'Novak', 'Horvat', 'Nguyen', 'Kim', 'Patel',
    ];

    /** @var list<string> */
    private array $words = [
        'alpha', 'breeze', 'cascade', 'delta', 'ember', 'flux', 'grove', 'harbor',
        'ivory', 'jade', 'kernel', 'lattice', 'meadow', 'nebula', 'orbit', 'prism',
        'quartz', 'ridge', 'sable', 'timber', 'umbra', 'vector', 'willow', 'xenon',
        'yellow', 'zephyr', 'anchor', 'beacon', 'cipher', 'drizzle', 'echo', 'forge',
    ];

    public function countBetween(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public function personName(): string
    {
        return $this->pick($this->first) . ' ' . $this->pick($this->last);
    }

    public function title(string $prefix = 'Item'): string
    {
        return $prefix . ': ' . ucfirst($this->pick($this->words)) . ' ' . $this->pick($this->words) . ' #' . random_int(1, 9999);
    }

    public function sentence(int $minWords = 5, int $maxWords = 12): string
    {
        $n = random_int($minWords, $maxWords);
        $parts = [];
        for ($i = 0; $i < $n; $i++) {
            $parts[] = $this->pick($this->words);
        }
        $s = implode(' ', $parts);

        return ucfirst($s) . '.';
    }

    public function paragraph(int $minSentences = 1, int $maxSentences = 4): string
    {
        $n = random_int($minSentences, $maxSentences);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->sentence(6, 16);
        }

        return implode(' ', $out);
    }

    public function email(string $localPrefix): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '.', $localPrefix) ?? 'user')
            . '.' . bin2hex(random_bytes(3))
            . '@example.test';
    }

    public function url(string $path = ''): string
    {
        $host = $this->pick(['example.test', 'demo.local', 'cms.dev', 'content.test']);

        return 'https://' . $host . ($path !== '' ? $path : '/' . $this->pick($this->words));
    }

    public function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
            . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    public function date(): string
    {
        return date('Y-m-d', time() + random_int(-86400 * 400, 86400 * 200));
    }

    public function datetime(): string
    {
        return date('Y-m-d H:i:s', time() + random_int(-86400 * 400, 86400 * 200));
    }

    public function datetimeRelative(int $daysFrom, int $daysTo): string
    {
        return date('Y-m-d H:i:s', time() + random_int($daysFrom * 86400, $daysTo * 86400));
    }

    public function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    public function float(float $min, float $max, int $decimals = 2): float
    {
        $v = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);

        return round($v, $decimals);
    }

    public function bool(float $trueProbability = 0.5): bool
    {
        return (mt_rand() / mt_getrandmax()) < $trueProbability;
    }

    public function maybe(float $probability): bool
    {
        return $this->bool($probability);
    }

    /**
     * @template T
     * @param non-empty-list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    /**
     * @return list<string>
     */
    public function tags(int $min, int $max): array
    {
        $n = random_int($min, $max);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->pick($this->words);
        }

        return array_values(array_unique($out));
    }

    public function slugTail(): string
    {
        return $this->pick($this->words) . '-' . bin2hex(random_bytes(2));
    }

    public function tinyPngPath(string $basename): string
    {
        // 1x1 PNG
        $bin = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5WzqYAAAAASUVORK5CYII=',
            true,
        );
        if ($bin === false) {
            throw new RuntimeException('Failed to decode PNG');
        }
        $path = sys_get_temp_dir() . '/' . $basename;
        file_put_contents($path, $bin);

        return $path;
    }

    public function tinyTextPath(string $basename): string
    {
        $path = sys_get_temp_dir() . '/' . $basename;
        file_put_contents($path, "Demo attachment {$basename}\nGenerated for HCMS seed.\n");

        return $path;
    }
}
