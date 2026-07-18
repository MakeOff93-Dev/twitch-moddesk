<?php

declare(strict_types=1);

function base_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function db(): PDO
{
    return Database::connection();
}

function auth(): Auth
{
    global $auth;
    return $auth;
}

function twitch(): TwitchClient
{
    global $twitch;
    return $twitch;
}

function settings(): AppSettings
{
    global $settings;
    return $settings;
}

function discord(): DiscordClient
{
    global $discord;
    return $discord;
}

function smtp_mailer(): SmtpMailer
{
    global $smtpMailer;
    return $smtpMailer;
}

function branding(): BrandingManager
{
    global $branding;
    return $branding;
}

function modules(): ModuleManager
{
    global $modules;
    return $modules;
}

function github_releases(): GitHubReleaseClient
{
    global $githubReleases;
    return $githubReleases;
}

function app_version(): string
{
    $version = trim((string) @file_get_contents(base_path('VERSION')));
    return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : '0.0.0';
}

function changelog_for_version(?string $version = null): string
{
    $version ??= app_version();
    $raw = @file_get_contents(base_path('CHANGELOG.md'));
    if (!is_string($raw) || $raw === '') {
        return 'Für diese Version ist noch kein lokaler Changelog hinterlegt.';
    }
    $quoted = preg_quote($version, '/');
    if (preg_match('/^##\s+(?:\[)?v?' . $quoted . '(?:\])?[^\r\n]*\R([\s\S]*?)(?=^##\s+|\z)/im', $raw, $match)) {
        return trim(mb_substr($match[1], 0, 12000));
    }
    return trim(mb_substr($raw, 0, 12000));
}

function github_update_status(): ?array
{
    try {
        $status = github_releases()->cachedStatus();
        if ($status !== null) {
            if (strtolower((string) ($status['repository'] ?? '')) !== github_releases()->repository()) {
                return null;
            }
            $status['update_available'] = github_releases()->updateAvailable($status);
        }
        return $status;
    } catch (Throwable) {
        return null;
    }
}

function module_setting(string $moduleKey, string $key, mixed $default = null): mixed
{
    if (!preg_match('/^[a-z][a-z0-9-]{2,49}$/', $moduleKey) || !preg_match('/^[a-z][a-z0-9_]{1,49}$/', $key)) {
        return $default;
    }
    return settings()->get('module.' . $moduleKey . '.' . $key, $default);
}

function module_asset_url(string $moduleKey, string $relativePath): string
{
    return url('module-asset', ['module' => $moduleKey, 'file' => ltrim(str_replace('\\', '/', $relativePath), '/')]);
}

function asset_url(string $path): string
{
    $prefix = defined('MODDESK_ASSET_PREFIX') ? (string) constant('MODDESK_ASSET_PREFIX') : '';
    return $prefix . ltrim($path, '/');
}

function setting_json(string $key): array
{
    $raw = settings()->get($key, '');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function database_table_exists(string $table): bool
{
    if (!preg_match('/^[a-z0-9_]{2,64}$/', $table)) {
        return false;
    }
    try {
        $statement = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);
        return (int) $statement->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function discord_managed_channels(bool $enabledOnly = true): array
{
    try {
        if (database_table_exists('discord_channels') && database_table_exists('discord_servers')) {
            $sql = 'SELECT dc.id, dc.channel_id, dc.name AS channel_name, dc.enabled AS channel_enabled,
                           ds.id AS server_id, ds.guild_id, ds.name AS server_name, ds.enabled AS server_enabled
                    FROM discord_channels dc JOIN discord_servers ds ON ds.id = dc.server_id';
            if ($enabledOnly) {
                $sql .= ' WHERE dc.enabled = 1 AND ds.enabled = 1';
            }
            $sql .= ' ORDER BY ds.name, dc.name';
            return db()->query($sql)->fetchAll();
        }
        $rows = db()->query(
            "SELECT id, channel_id, channel_id AS channel_name, enabled AS channel_enabled,
                    NULL AS server_id, guild_id, guild_id AS server_name, enabled AS server_enabled
             FROM discord_notification_routes
             WHERE channel_id IS NOT NULL AND channel_id <> ''"
            . ($enabledOnly ? ' AND enabled = 1' : '') . ' ORDER BY guild_id, channel_id'
        )->fetchAll();
        $unique = [];
        foreach ($rows as $row) $unique[(string) $row['channel_id']] = $row;
        return array_values($unique);
    } catch (Throwable) {
        return [];
    }
}

function discord_channel_is_managed(string $channelId): bool
{
    foreach (discord_managed_channels(true) as $channel) {
        if (hash_equals((string) $channel['channel_id'], $channelId)) return true;
    }
    return false;
}

function navigation_definitions(): array
{
    return array_merge([
        'dashboard' => ['icon' => '▦', 'label' => 'Dashboard', 'permission' => null, 'order' => 10],
        'news' => ['icon' => '◫', 'label' => 'News', 'permission' => null, 'order' => 15, 'module' => 'news'],
        'ideas' => ['icon' => '✦', 'label' => 'Ideen', 'permission' => null, 'order' => 20, 'module' => 'ideas'],
        'notes' => ['icon' => '▤', 'label' => 'Notizen', 'permission' => null, 'order' => 30, 'module' => 'notes'],
        'links' => ['icon' => '↗', 'label' => 'Links', 'permission' => null, 'order' => 40, 'module' => 'links'],
        'twitch' => ['icon' => '◉', 'label' => 'Twitch-Zentrale', 'permission' => null, 'order' => 50, 'module' => 'twitch'],
        'ban-sync' => ['icon' => '⛔', 'label' => 'BanSync', 'permission' => null, 'order' => 60, 'module' => 'ban-sync'],
        'twitch-users' => ['icon' => '♙', 'label' => 'Twitch-Nutzer', 'permission' => null, 'order' => 70, 'module' => 'twitch'],
        'cases' => ['icon' => '⚑', 'label' => 'Mod-Fälle', 'permission' => null, 'order' => 80, 'module' => 'cases'],
        'discord-studio' => ['icon' => '◆', 'label' => 'Discord Studio', 'permission' => 'discord.studio', 'order' => 90, 'module' => 'discord'],
        'team' => ['icon' => '♚', 'label' => 'Team & Rechte', 'permission' => 'team.manage', 'order' => 100, 'module' => 'team'],
        'design' => ['icon' => '◈', 'label' => 'Design-Editor', 'permission' => 'design.manage', 'order' => 110, 'module' => 'design'],
        'settings' => ['icon' => '⚙', 'label' => 'Einstellungen', 'permission' => 'settings.manage', 'order' => 120],
        'modules' => ['icon' => '⬢', 'label' => 'Module', 'permission' => 'modules.manage', 'order' => 125],
        'audit' => ['icon' => '⌁', 'label' => 'Audit-Protokoll', 'permission' => 'audit.view', 'order' => 130, 'module' => 'audit'],
    ], modules()->customNavigation());
}

function navigation_items(): array
{
    $config = setting_json('menu_config_json');
    $items = [];
    foreach (navigation_definitions() as $page => $definition) {
        $custom = isset($config[$page]) && is_array($config[$page]) ? $config[$page] : [];
        $permission = $definition['permission'];
        if ($permission !== null && !auth()->can((string) $permission)) {
            continue;
        }
        $module = $definition['module'] ?? null;
        if (is_string($module) && !modules()->isEnabled($module)) {
            continue;
        }
        $label = mb_substr(trim((string) ($custom['label'] ?? $definition['label'])), 0, 45);
        $icon = mb_substr(trim((string) ($custom['icon'] ?? $definition['icon'])), 0, 4);
        $items[$page] = [
            'icon' => $icon !== '' ? $icon : $definition['icon'],
            'label' => $label !== '' ? $label : $definition['label'],
            'order' => max(0, min(999, (int) ($custom['order'] ?? $definition['order']))),
            'enabled' => !array_key_exists('enabled', $custom) || filter_var($custom['enabled'], FILTER_VALIDATE_BOOLEAN),
        ];
    }
    uasort($items, static fn (array $left, array $right): int => [$left['order'], $left['label']] <=> [$right['order'], $right['label']]);
    return array_filter($items, static fn (array $item): bool => $item['enabled']);
}

function editable_page_definitions(): array
{
    return [
        'dashboard' => 'Dashboard',
        'news' => 'News & Ankündigungen',
        'ideas' => 'Ideen',
        'notes' => 'Notizen',
        'links' => 'Geteilte Links',
        'twitch' => 'Twitch-Zentrale',
        'ban-sync' => 'BanSync',
        'twitch-users' => 'Twitch-Nutzer',
        'cases' => 'Moderationsfälle',
        'discord-studio' => 'Discord Studio',
        'team' => 'Team & Rechte',
        'design' => 'Design-Editor',
        'settings' => 'Einstellungen',
        'modules' => 'Module',
        'audit' => 'Audit-Protokoll',
    ];
}

function page_presentation(string $page, string $fallbackTitle = ''): array
{
    $config = setting_json('page_content_json');
    $custom = isset($config[$page]) && is_array($config[$page]) ? $config[$page] : [];
    $defaultTitle = editable_page_definitions()[$page] ?? $fallbackTitle;
    $title = mb_substr(trim((string) ($custom['title'] ?? '')), 0, 100);
    $topText = mb_substr(trim((string) ($custom['top_text'] ?? '')), 0, 240);
    return [
        'title' => $title !== '' ? $title : ($fallbackTitle !== '' ? $fallbackTitle : $defaultTitle),
        'top_text' => $topText,
    ];
}

function valid_theme_color(mixed $value, string $fallback): string
{
    $color = strtoupper(trim((string) $value));
    return preg_match('/^#[0-9A-F]{6}$/', $color) ? $color : $fallback;
}

function theme_values(): array
{
    $defaults = [
        'background' => '#09080E',
        'surface' => '#111019',
        'surface_alt' => '#171520',
        'text' => '#F6F4FB',
        'muted' => '#9691A5',
        'primary' => '#9147FF',
        'secondary' => '#B77BFF',
    ];
    foreach ($defaults as $key => $fallback) {
        $defaults[$key] = valid_theme_color(settings()->get('theme_' . $key, $fallback), $fallback);
    }
    return $defaults;
}

function display_version_text(): string
{
    $text = mb_substr(trim((string) settings()->get('header_version_text', 'Version {version}')), 0, 80);
    return str_replace('{version}', app_version(), $text !== '' ? $text : 'Version {version}');
}

function validate_embed_url(mixed $value, string $label): string
{
    $url = trim((string) $value);
    if ($url === '') {
        return '';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (strlen($url) > 1000 || !filter_var($url, FILTER_VALIDATE_URL) || $scheme !== 'https') {
        throw new InvalidArgumentException($label . ' muss eine gültige HTTPS-Adresse sein.');
    }
    return $url;
}

function normalize_discord_studio_input(array $source, bool $requireName = false): array
{
    $name = mb_substr(trim((string) ($source['template_name'] ?? '')), 0, 120);
    if ($requireName && $name === '') {
        throw new InvalidArgumentException('Für eine Vorlage wird ein Name benötigt.');
    }

    $content = mb_substr(trim((string) ($source['message_content'] ?? '')), 0, 2000);
    $title = mb_substr(trim((string) ($source['embed_title'] ?? '')), 0, 256);
    $description = mb_substr(trim((string) ($source['embed_description'] ?? '')), 0, 4096);
    $authorName = mb_substr(trim((string) ($source['author_name'] ?? '')), 0, 256);
    $footerText = mb_substr(trim((string) ($source['footer_text'] ?? '')), 0, 2048);
    $embedUrl = validate_embed_url($source['embed_url'] ?? '', 'Die Titel-URL');
    $authorUrl = validate_embed_url($source['author_url'] ?? '', 'Die Autor-URL');
    $authorIconUrl = validate_embed_url($source['author_icon_url'] ?? '', 'Die Autor-Icon-URL');
    $thumbnailUrl = validate_embed_url($source['thumbnail_url'] ?? '', 'Die Thumbnail-URL');
    $imageUrl = validate_embed_url($source['image_url'] ?? '', 'Die Bild-URL');
    $footerIconUrl = validate_embed_url($source['footer_icon_url'] ?? '', 'Die Footer-Icon-URL');
    if (($authorUrl !== '' || $authorIconUrl !== '') && $authorName === '') {
        throw new InvalidArgumentException('Für Autor-Link oder Autor-Icon wird auch ein Autorname benötigt.');
    }
    if ($footerIconUrl !== '' && $footerText === '') {
        throw new InvalidArgumentException('Für ein Footer-Icon wird auch ein Footer-Text benötigt.');
    }

    $colorHex = strtoupper(trim((string) ($source['embed_color'] ?? '#9147FF')));
    if (!preg_match('/^#[0-9A-F]{6}$/', $colorHex)) {
        throw new InvalidArgumentException('Die Embed-Farbe ist ungültig.');
    }
    $color = hexdec(substr($colorHex, 1));

    $postedFields = $source['embed_fields'] ?? [];
    $postedFields = is_array($postedFields) ? $postedFields : [];
    $fields = [];
    foreach (array_slice($postedFields, 0, 25) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $fieldName = mb_substr(trim((string) ($field['name'] ?? '')), 0, 256);
        $fieldValue = mb_substr(trim((string) ($field['value'] ?? '')), 0, 1024);
        if ($fieldName === '' && $fieldValue === '') {
            continue;
        }
        if ($fieldName === '' || $fieldValue === '') {
            throw new InvalidArgumentException('Jedes Embed-Feld benötigt Name und Inhalt.');
        }
        $fields[] = ['name' => $fieldName, 'value' => $fieldValue, 'inline' => isset($field['inline'])];
    }

    $embedCharacters = mb_strlen($title) + mb_strlen($description) + mb_strlen($authorName) + mb_strlen($footerText);
    foreach ($fields as $field) {
        $embedCharacters += mb_strlen($field['name']) + mb_strlen($field['value']);
    }
    if ($embedCharacters > 6000) {
        throw new InvalidArgumentException('Der Embed überschreitet insgesamt 6000 Zeichen.');
    }

    $hasEmbed = $title !== '' || $description !== '' || $authorName !== '' || $footerText !== ''
        || $thumbnailUrl !== '' || $imageUrl !== '' || $fields !== [];
    if ($content === '' && !$hasEmbed) {
        throw new InvalidArgumentException('Die Discord-Nachricht benötigt Text oder mindestens einen Embed-Inhalt.');
    }

    return [
        'name' => $name,
        'message_content' => $content,
        'embed_title' => $title,
        'embed_description' => $description,
        'embed_url' => $embedUrl,
        'embed_color' => $color,
        'embed_color_hex' => $colorHex,
        'author_name' => $authorName,
        'author_url' => $authorUrl,
        'author_icon_url' => $authorIconUrl,
        'thumbnail_url' => $thumbnailUrl,
        'image_url' => $imageUrl,
        'footer_text' => $footerText,
        'footer_icon_url' => $footerIconUrl,
        'include_timestamp' => isset($source['include_timestamp']) ? 1 : 0,
        'fields' => $fields,
        'has_embed' => $hasEmbed,
    ];
}

function discord_message_from_template(array $template): array
{
    $fields = $template['fields'] ?? null;
    if (!is_array($fields)) {
        try {
            $decoded = json_decode((string) ($template['fields_json'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);
            $fields = is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            $fields = [];
        }
    }
    $embed = [
        'title' => (string) ($template['embed_title'] ?? ''),
        'description' => (string) ($template['embed_description'] ?? ''),
        'url' => (string) ($template['embed_url'] ?? ''),
        'color' => (int) ($template['embed_color'] ?? 9525247),
        'author_name' => (string) ($template['author_name'] ?? ''),
        'author_url' => (string) ($template['author_url'] ?? ''),
        'author_icon_url' => (string) ($template['author_icon_url'] ?? ''),
        'thumbnail_url' => (string) ($template['thumbnail_url'] ?? ''),
        'image_url' => (string) ($template['image_url'] ?? ''),
        'footer_text' => (string) ($template['footer_text'] ?? ''),
        'footer_icon_url' => (string) ($template['footer_icon_url'] ?? ''),
        'timestamp' => (int) ($template['include_timestamp'] ?? 0) === 1,
        'fields' => $fields,
    ];
    $hasEmbed = trim((string) ($template['embed_title'] ?? '')) !== ''
        || trim((string) ($template['embed_description'] ?? '')) !== ''
        || trim((string) ($template['author_name'] ?? '')) !== ''
        || trim((string) ($template['thumbnail_url'] ?? '')) !== ''
        || trim((string) ($template['image_url'] ?? '')) !== ''
        || trim((string) ($template['footer_text'] ?? '')) !== ''
        || $fields !== [];
    return [
        'content' => (string) ($template['message_content'] ?? ''),
        'embed' => !empty($template['has_embed']) || $hasEmbed ? $embed : null,
    ];
}

function notify_discord(
    string $eventKey,
    string $title,
    string $description,
    array $fields = [],
    int $color = 9525247,
): void {
    try {
        discord()->sendEvent($eventKey, compact('title', 'description', 'fields', 'color'));
    } catch (Throwable) {
        // Benachrichtigungsfehler werden separat protokolliert und blockieren keine Kernaktion.
    }
}

function discord_event_definitions(): array
{
    return [
        'ban_sync' => [
            'label' => 'BanSync-Ergebnis',
            'description' => 'Sammel-Bans und -Unbans mit Erfolgen und Fehlern.',
        ],
        'moderation_action' => [
            'label' => 'Twitch-Modaktion',
            'description' => 'Verwarnung, Timeout, Ban oder Unban aus der Twitch-Zentrale.',
        ],
        'idea_created' => [
            'label' => 'Neue Idee',
            'description' => 'Neu angelegte Einträge im Ideen-Board.',
        ],
        'case_created' => [
            'label' => 'Neuer Mod-Fall',
            'description' => 'Neu eröffnete interne Moderationsfälle.',
        ],
        'news_published' => [
            'label' => 'News veröffentlicht',
            'description' => 'Neu veröffentlichte Ankündigungen aus dem News-Modul.',
        ],
        'changelog' => [
            'label' => 'Versions-Changelog',
            'description' => 'Release-Notizen und verfügbare ModDesk-Versionen.',
        ],
    ];
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function request_page(string $fallback = 'dashboard'): string
{
    $queryPage = trim((string) ($_GET['page'] ?? ''));
    if ($queryPage !== '') {
        return $queryPage;
    }
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $basePath = (string) parse_url((string) settings()->get('app_url', env('APP_URL', '')), PHP_URL_PATH);
    $basePath = '/' . trim($basePath, '/');
    if ($basePath === '/') {
        $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $basePath = $scriptDirectory === '/' ? '' : rtrim($scriptDirectory, '/');
    }
    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        $path = substr($path, strlen($basePath));
    }
    $page = trim($path, '/');
    if ($page === '' || in_array($page, ['index.php', 'public/index.php'], true) || str_contains($page, '/')) {
        return $fallback;
    }
    return preg_match('/^[a-z0-9-]{2,80}$/', $page) ? $page : $fallback;
}

function app_base_url(): string
{
    $configured = rtrim(trim((string) settings()->get('app_url', env('APP_URL', ''))), '/');
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_URL)) {
        return $configured;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $directory = $directory === '/' ? '' : rtrim($directory, '/');
    return ($secure ? 'https' : 'http') . '://' . $host . $directory;
}

function url(string $page = 'dashboard', array $params = []): string
{
    if (settings()->bool('url_rewrite_enabled', false)) {
        $target = app_base_url() . '/' . rawurlencode($page);
        return $params !== [] ? $target . '?' . http_build_query($params) : $target;
    }
    return app_base_url() . '/?' . http_build_query(array_merge(['page' => $page], $params));
}

function redirect(string $page = 'dashboard', array $params = []): never
{
    header('Location: ' . url($page, $params), true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $provided = (string) ($_POST['_csrf'] ?? '');
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('Die Sitzung ist abgelaufen. Bitte lade die Seite neu.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flashes'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);
    return is_array($flashes) ? $flashes : [];
}

function require_login(): void
{
    if (!auth()->check()) {
        flash('warning', 'Bitte melde dich zuerst an.');
        redirect('login');
    }
}

function require_permission(string $permission): void
{
    require_login();
    if (!auth()->can($permission)) {
        http_response_code(403);
        render('error', ['title' => 'Kein Zugriff', 'message' => 'Für diese Aktion fehlen dir die Rechte.']);
        exit;
    }
}

function require_module_enabled(string $moduleKey): void
{
    if (!modules()->isEnabled($moduleKey)) {
        throw new RuntimeException('Dieses Modul ist derzeit deaktiviert.');
    }
}

function audit(string $action, ?string $entityType = null, string|int|null $entityId = null, array $details = []): void
{
    $statement = db()->prepare(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip)'
    );
    $statement->execute([
        'user_id' => auth()->user()['id'] ?? null,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId !== null ? (string) $entityId : null,
        'details' => $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);
}

function render(string $view, array $data = []): void
{
    $viewFile = base_path('views/pages/' . $view . '.php');
    if (!is_file($viewFile)) {
        throw new RuntimeException('View nicht gefunden: ' . $view);
    }

    $page = in_array($view, ['login', 'error'], true)
        ? $view
        : request_page($view);
    $presentation = page_presentation($page, (string) ($data['title'] ?? ''));
    $data['title'] = $presentation['title'];
    $data['pageTopText'] = $presentation['top_text'];
    extract($data, EXTR_SKIP);
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();
    require base_path('views/layout.php');
}

function render_custom_module(array $module, string $viewFile): void
{
    if (!is_file($viewFile) || !str_starts_with(str_replace('\\', '/', $viewFile), str_replace('\\', '/', base_path('modules/')))) {
        throw new RuntimeException('Der Einstieg des Moduls wurde nicht gefunden.');
    }
    $title = (string) ($module['name'] ?? 'Modul');
    $pageTopText = (string) ($module['description'] ?? '');
    $moduleKey = (string) ($module['module_key'] ?? '');
    $moduleSettings = modules()->configurationValues($module);
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();
    require base_path('views/layout.php');
}

function validate_choice(string $value, array $allowed, string $fallback): string
{
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function utc_to_local(?string $date): string
{
    if (!$date) {
        return '–';
    }

    try {
        $time = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        return $time->setTimezone(new DateTimeZone((string) env('APP_TIMEZONE', 'Europe/Berlin')))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $date;
    }
}
