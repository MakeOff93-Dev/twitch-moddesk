<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
if (!is_file($projectRoot . '/.env') && getenv('APP_KEY') === false) {
    header('Location: install.php', true, 302);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$page = request_page('dashboard');

function safe_return_page(string $page): string
{
    $allowed = [
        'dashboard',
        'news',
        'ideas',
        'notes',
        'links',
        'twitch',
        'ban-sync',
        'twitch-users',
        'cases',
        'discord-studio',
        'team',
        'design',
        'modules',
        'settings',
        'audit',
    ];
    if (in_array($page, $allowed, true)) {
        return $page;
    }
    if (str_starts_with($page, 'module-') && modules()->moduleForPage($page) !== null) {
        return $page;
    }
    return 'dashboard';
}

function resolve_twitch_target(string $login): array
{
    $user = twitch()->findUser($login);
    if ($user === null) {
        throw new InvalidArgumentException('Dieser Twitch-User wurde nicht gefunden.');
    }
    return $user;
}

function record_mod_action(
    ?string $targetId,
    string $action,
    ?int $duration,
    ?string $reason,
    bool $success,
    array $response = [],
    ?int $caseId = null,
): void {
    $statement = db()->prepare(
        'INSERT INTO moderation_actions
            (case_id, twitch_user_id, action, duration_seconds, reason, success, api_response, performed_by)
         VALUES (:case_id, :target, :action, :duration, :reason, :success, :response, :performed_by)'
    );
    $statement->execute([
        'case_id' => $caseId ?: null,
        'target' => $targetId,
        'action' => $action,
        'duration' => $duration,
        'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
        'success' => $success ? 1 : 0,
        'response' => $response !== [] ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'performed_by' => auth()->user()['id'],
    ]);
}

if ($page === 'theme-css') {
    header('Content-Type: text/css; charset=UTF-8');
    $theme = theme_values();
    echo ':root{'
        . '--bg:' . $theme['background'] . ';'
        . '--surface:' . $theme['surface'] . ';'
        . '--surface-2:' . $theme['surface_alt'] . ';'
        . '--text:' . $theme['text'] . ';'
        . '--muted:' . $theme['muted'] . ';'
        . '--purple:' . $theme['primary'] . ';'
        . '--purple-2:' . $theme['secondary'] . ';'
        . '}';
    exit;
}

if ($page === 'brand-logo') {
    $logo = branding()->logo();
    if ($logo === null) {
        http_response_code(404);
        exit;
    }
    $logoData = (string) $logo['file_data'];
    header('Content-Type: ' . (string) $logo['mime_type']);
    header('Content-Length: ' . (string) strlen($logoData));
    header('ETag: "' . (string) $logo['checksum_sha256'] . '"');
    header('Cache-Control: public, max-age=86400, immutable');
    echo $logoData;
    exit;
}

if ($page === 'module-asset') {
    require_login();
    $moduleKey = trim((string) ($_GET['module'] ?? ''));
    $relativePath = trim((string) ($_GET['file'] ?? ''));
    $asset = modules()->customAsset($moduleKey, $relativePath);
    if ($asset === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $asset['mime_type']);
    header('Content-Length: ' . (string) $asset['size']);
    header('ETag: "' . $asset['etag'] . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($asset['path']);
    exit;
}

if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $returnPage = safe_return_page((string) ($_POST['return_page'] ?? $page));

    try {
        if ($action === 'login') {
            if (auth()->attempt((string) input('username', ''), (string) input('password', ''))) {
                audit('auth.login', 'user', auth()->user()['id']);
                flash('success', 'Willkommen zurück, ' . auth()->user()['display_name'] . '!');
                redirect('dashboard');
            }
            flash('danger', 'Anmeldung fehlgeschlagen. Prüfe deine Daten oder warte 15 Minuten.');
            redirect('login');
        }

        require_login();

        if ($action === 'logout') {
            audit('auth.logout', 'user', auth()->user()['id']);
            auth()->logout();
            header('Location: ' . url('login'), true, 303);
            exit;
        }

        $moduleActionPrefixes = [
            'idea-' => 'ideas',
            'note-' => 'notes',
            'link-' => 'links',
            'case-' => 'cases',
            'news-' => 'news',
            'ban-sync-' => 'ban-sync',
            'twitch-' => 'twitch',
            'discord-template-' => 'discord',
            'discord-message-' => 'discord',
            'design-' => 'design',
            'team-' => 'team',
        ];
        foreach ($moduleActionPrefixes as $prefix => $moduleKey) {
            if (str_starts_with($action, $prefix)) {
                require_module_enabled($moduleKey);
                break;
            }
        }

        if ($action === 'module-action') {
            $moduleKey = strtolower(trim((string) input('module_key', '')));
            $moduleAction = strtolower(trim((string) input('module_action', '')));
            if (!preg_match('/^[a-z][a-z0-9-]{1,79}$/', $moduleAction)) {
                throw new InvalidArgumentException('Die Modulaktion ist ungültig.');
            }
            require_module_enabled($moduleKey);
            $customModule = modules()->get($moduleKey);
            $customPageFile = modules()->customPageFile($moduleKey);
            if ($customModule === null || ($customModule['source'] ?? '') !== 'custom' || $customPageFile === null) {
                throw new RuntimeException('Das Zusatzmodul wurde nicht gefunden.');
            }
            $moduleSettings = modules()->configurationValues($customModule);
            $isModulePost = true;
            ob_start();
            try {
                require $customPageFile;
            } finally {
                ob_end_clean();
            }
            audit('module.action', 'module', $moduleKey, ['action' => $moduleAction]);
            redirect('module-' . $moduleKey);
        }

        if ($action === 'channel-switch') {
            require_permission('twitch.configure');
            require_module_enabled('twitch');
            $channel = twitch()->selectAvailableChannel(trim((string) input('twitch_channel_id', '')), (int) auth()->user()['id']);
            audit('twitch.channel_switched', 'twitch_channel', $channel['id'], ['login' => $channel['login']]);
            flash('success', 'Aktiver Twitch-Kanal: ' . $channel['display_name']);
            redirect($returnPage);
        }

        if ($action === 'news-save') {
            require_permission('content.write');
            require_module_enabled('news');
            if (!database_table_exists('news_posts')) {
                throw new RuntimeException('Bitte führe zuerst die Systemmigrationen im Panel aus.');
            }
            $id = max(0, (int) input('news_id', 0));
            $title = mb_substr(trim((string) input('title', '')), 0, 180);
            $body = mb_substr(trim((string) input('body', '')), 0, 50000);
            $status = validate_choice((string) input('status', 'draft'), ['draft', 'published', 'archived'], 'draft');
            $pinned = input('pinned') === '1' ? 1 : 0;
            $publishAtInput = trim((string) input('publish_at', ''));
            $publishAt = null;
            if ($publishAtInput !== '') {
                try {
                    $publishAt = (new DateTimeImmutable($publishAtInput, new DateTimeZone((string) env('APP_TIMEZONE', 'Europe/Berlin'))))
                        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                } catch (Throwable) {
                    throw new InvalidArgumentException('Der Veröffentlichungszeitpunkt ist ungültig.');
                }
            }
            if ($title === '' || $body === '') {
                throw new InvalidArgumentException('News benötigen Titel und Inhalt.');
            }

            $previousStatus = null;
            if ($id > 0) {
                $current = db()->prepare('SELECT status FROM news_posts WHERE id = :id');
                $current->execute(['id' => $id]);
                $previousStatus = $current->fetchColumn();
                if ($previousStatus === false) throw new RuntimeException('Die News wurde nicht gefunden.');
                $statement = db()->prepare(
                    'UPDATE news_posts SET title = :title, body = :body, status = :status, pinned = :pinned,
                     publish_at = :publish_at, updated_by = :updated_by WHERE id = :id'
                );
                $statement->execute(compact('id', 'title', 'body', 'status', 'pinned') + ['publish_at' => $publishAt, 'updated_by' => auth()->user()['id']]);
                audit('news.updated', 'news', $id, ['status' => $status]);
            } else {
                $statement = db()->prepare(
                    'INSERT INTO news_posts (title, body, status, pinned, publish_at, created_by, updated_by)
                     VALUES (:title, :body, :status, :pinned, :publish_at, :created_by, :updated_by)'
                );
                $statement->execute(compact('title', 'body', 'status', 'pinned') + [
                    'publish_at' => $publishAt,
                    'created_by' => auth()->user()['id'],
                    'updated_by' => auth()->user()['id'],
                ]);
                $id = (int) db()->lastInsertId();
                audit('news.created', 'news', $id, ['status' => $status]);
            }
            if ($status === 'published' && $previousStatus !== 'published') {
                notify_discord('news_published', $title, mb_substr($body, 0, 4000), [
                    ['name' => 'Veröffentlicht von', 'value' => (string) auth()->user()['display_name'], 'inline' => true],
                ], 5814783);
            }
            flash('success', 'News wurde gespeichert.');
            redirect('news');
        }

        if ($action === 'news-archive') {
            require_permission('content.archive');
            require_module_enabled('news');
            $id = max(1, (int) input('news_id', 0));
            db()->prepare("UPDATE news_posts SET status = 'archived', updated_by = :updated_by WHERE id = :id")
                ->execute(['updated_by' => auth()->user()['id'], 'id' => $id]);
            audit('news.archived', 'news', $id);
            flash('success', 'News wurde archiviert.');
            redirect('news');
        }

        if ($action === 'idea-save') {
            require_permission('content.write');
            $id = (int) input('id', 0);
            $title = trim((string) input('title', ''));
            $description = trim((string) input('description', ''));
            if ($title === '' || $description === '') {
                throw new InvalidArgumentException('Titel und Beschreibung sind Pflichtfelder.');
            }
            $status = validate_choice((string) input('status', 'new'), ['new', 'planned', 'in_progress', 'done', 'rejected'], 'new');
            $priority = validate_choice((string) input('priority', 'normal'), ['low', 'normal', 'high', 'urgent'], 'normal');
            $dueDate = trim((string) input('due_date', '')) ?: null;
            $assignedTo = (int) input('assigned_to', 0) ?: null;

            if ($id > 0) {
                $statement = db()->prepare(
                    'UPDATE ideas SET title = :title, description = :description, status = :status,
                     priority = :priority, due_date = :due_date, assigned_to = :assigned_to
                     WHERE id = :id AND archived_at IS NULL'
                );
                $statement->execute(compact('title', 'description', 'status', 'priority') + [
                    'due_date' => $dueDate,
                    'assigned_to' => $assignedTo,
                    'id' => $id,
                ]);
                audit('idea.updated', 'idea', $id);
                flash('success', 'Idee wurde aktualisiert.');
            } else {
                $statement = db()->prepare(
                    'INSERT INTO ideas (title, description, status, priority, due_date, created_by, assigned_to)
                     VALUES (:title, :description, :status, :priority, :due_date, :created_by, :assigned_to)'
                );
                $statement->execute(compact('title', 'description', 'status', 'priority') + [
                    'due_date' => $dueDate,
                    'created_by' => auth()->user()['id'],
                    'assigned_to' => $assignedTo,
                ]);
                $id = (int) db()->lastInsertId();
                audit('idea.created', 'idea', $id);
                notify_discord(
                    'idea_created',
                    'Neue Idee im ModDesk',
                    $title,
                    [
                        ['name' => 'Priorität', 'value' => ucfirst($priority), 'inline' => true],
                        ['name' => 'Erstellt von', 'value' => (string) auth()->user()['display_name'], 'inline' => true],
                        ['name' => 'Beschreibung', 'value' => mb_substr($description, 0, 1000), 'inline' => false],
                    ],
                    9525247,
                );
                flash('success', 'Neue Idee wurde gespeichert.');
            }
            redirect('ideas');
        }

        if ($action === 'idea-archive') {
            require_permission('content.archive');
            $id = (int) input('id', 0);
            db()->prepare('UPDATE ideas SET archived_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $id]);
            audit('idea.archived', 'idea', $id);
            flash('success', 'Idee wurde archiviert.');
            redirect('ideas');
        }

        if ($action === 'note-save') {
            require_permission('content.write');
            $id = (int) input('id', 0);
            $title = trim((string) input('title', ''));
            $body = trim((string) input('body', ''));
            if ($title === '' || $body === '') {
                throw new InvalidArgumentException('Titel und Notiztext sind Pflichtfelder.');
            }
            $visibility = validate_choice((string) input('visibility', 'team'), ['team', 'admin'], 'team');
            if ($visibility === 'admin' && !in_array(auth()->user()['role'], ['owner', 'admin'], true)) {
                $visibility = 'team';
            }
            $tags = mb_substr(trim((string) input('tags', '')), 0, 500) ?: null;
            $pinned = input('pinned') === '1' ? 1 : 0;
            $twitchUserId = trim((string) input('twitch_user_id', '')) ?: null;
            $ideaId = (int) input('idea_id', 0) ?: null;

            if ($id > 0) {
                $existing = db()->prepare('SELECT visibility FROM notes WHERE id = :id AND archived_at IS NULL');
                $existing->execute(['id' => $id]);
                $existingVisibility = $existing->fetchColumn();
                if ($existingVisibility === false) {
                    throw new RuntimeException('Die Notiz wurde nicht gefunden.');
                }
                if ($existingVisibility === 'admin' && !in_array(auth()->user()['role'], ['owner', 'admin'], true)) {
                    throw new RuntimeException('Diese Admin-Notiz darfst du nicht bearbeiten.');
                }
                $statement = db()->prepare(
                    'UPDATE notes SET title = :title, body = :body, tags = :tags, visibility = :visibility,
                     pinned = :pinned, twitch_user_id = :twitch_user_id, idea_id = :idea_id, updated_by = :updated_by
                     WHERE id = :id AND archived_at IS NULL'
                );
                $statement->execute(compact('title', 'body', 'tags', 'visibility', 'pinned') + [
                    'twitch_user_id' => $twitchUserId,
                    'idea_id' => $ideaId,
                    'updated_by' => auth()->user()['id'],
                    'id' => $id,
                ]);
                audit('note.updated', 'note', $id);
                flash('success', 'Notiz wurde aktualisiert.');
            } else {
                $statement = db()->prepare(
                    'INSERT INTO notes (title, body, tags, visibility, pinned, twitch_user_id, idea_id, created_by)
                     VALUES (:title, :body, :tags, :visibility, :pinned, :twitch_user_id, :idea_id, :created_by)'
                );
                $statement->execute(compact('title', 'body', 'tags', 'visibility', 'pinned') + [
                    'twitch_user_id' => $twitchUserId,
                    'idea_id' => $ideaId,
                    'created_by' => auth()->user()['id'],
                ]);
                $id = (int) db()->lastInsertId();
                audit('note.created', 'note', $id);
                flash('success', 'Notiz wurde gespeichert.');
            }
            redirect('notes');
        }

        if ($action === 'note-archive') {
            require_permission('content.archive');
            $id = (int) input('id', 0);
            db()->prepare('UPDATE notes SET archived_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $id]);
            audit('note.archived', 'note', $id);
            flash('success', 'Notiz wurde archiviert.');
            redirect('notes');
        }

        if ($action === 'link-save') {
            require_permission('content.write');
            $id = (int) input('id', 0);
            $title = trim((string) input('title', ''));
            $linkUrl = trim((string) input('url', ''));
            $description = trim((string) input('description', '')) ?: null;
            $category = mb_substr(trim((string) input('category', '')), 0, 80) ?: null;
            if ($title === '' || !filter_var($linkUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($linkUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new InvalidArgumentException('Bitte gib einen Titel und eine gültige HTTP- oder HTTPS-Adresse ein.');
            }
            if ($id > 0) {
                $statement = db()->prepare(
                    'UPDATE shared_links SET title = :title, url = :url, description = :description, category = :category
                     WHERE id = :id AND archived_at IS NULL'
                );
                $statement->execute(['title' => $title, 'url' => $linkUrl, 'description' => $description, 'category' => $category, 'id' => $id]);
                audit('link.updated', 'link', $id);
                flash('success', 'Link wurde aktualisiert.');
            } else {
                $statement = db()->prepare(
                    'INSERT INTO shared_links (title, url, description, category, created_by)
                     VALUES (:title, :url, :description, :category, :created_by)'
                );
                $statement->execute(['title' => $title, 'url' => $linkUrl, 'description' => $description, 'category' => $category, 'created_by' => auth()->user()['id']]);
                $id = (int) db()->lastInsertId();
                audit('link.created', 'link', $id);
                flash('success', 'Link wurde geteilt.');
            }
            redirect('links');
        }

        if ($action === 'link-archive') {
            require_permission('content.archive');
            $id = (int) input('id', 0);
            db()->prepare('UPDATE shared_links SET archived_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $id]);
            audit('link.archived', 'link', $id);
            flash('success', 'Link wurde archiviert.');
            redirect('links');
        }

        if ($action === 'case-save') {
            require_permission('twitch.use');
            $id = (int) input('id', 0);
            $target = resolve_twitch_target((string) input('twitch_login', ''));
            $title = trim((string) input('title', ''));
            if ($title === '') {
                throw new InvalidArgumentException('Der Fall braucht einen Titel.');
            }
            $summary = trim((string) input('summary', '')) ?: null;
            $status = validate_choice((string) input('status', 'open'), ['open', 'monitoring', 'closed'], 'open');
            $severity = validate_choice((string) input('severity', 'normal'), ['low', 'normal', 'high', 'critical'], 'normal');
            $assignedTo = (int) input('assigned_to', 0) ?: null;
            $closedAt = $status === 'closed' ? gmdate('Y-m-d H:i:s') : null;
            if ($id > 0) {
                $statement = db()->prepare(
                    'UPDATE moderation_cases SET twitch_user_id = :target, title = :title, summary = :summary,
                     status = :status, severity = :severity, assigned_to = :assigned_to, closed_at = :closed_at WHERE id = :id'
                );
                $statement->execute(['target' => $target['id'], 'title' => $title, 'summary' => $summary, 'status' => $status, 'severity' => $severity, 'assigned_to' => $assignedTo, 'closed_at' => $closedAt, 'id' => $id]);
                audit('case.updated', 'moderation_case', $id);
                flash('success', 'Moderationsfall wurde aktualisiert.');
            } else {
                $statement = db()->prepare(
                    'INSERT INTO moderation_cases (twitch_user_id, title, summary, status, severity, created_by, assigned_to, closed_at)
                     VALUES (:target, :title, :summary, :status, :severity, :created_by, :assigned_to, :closed_at)'
                );
                $statement->execute(['target' => $target['id'], 'title' => $title, 'summary' => $summary, 'status' => $status, 'severity' => $severity, 'created_by' => auth()->user()['id'], 'assigned_to' => $assignedTo, 'closed_at' => $closedAt]);
                $id = (int) db()->lastInsertId();
                audit('case.created', 'moderation_case', $id);
                notify_discord(
                    'case_created',
                    'Neuer Moderationsfall #' . $id,
                    $title,
                    [
                        ['name' => 'Twitch-User', 'value' => '@' . $target['login'], 'inline' => true],
                        ['name' => 'Schweregrad', 'value' => ucfirst($severity), 'inline' => true],
                        ['name' => 'Erstellt von', 'value' => (string) auth()->user()['display_name'], 'inline' => true],
                    ],
                    $severity === 'critical' ? 16733552 : 16752451,
                );
                flash('success', 'Moderationsfall wurde angelegt.');
            }
            redirect('cases');
        }

        if ($action === 'team-save') {
            require_permission('team.manage');
            $id = (int) input('id', 0);
            $username = strtolower(trim((string) input('username', '')));
            $displayName = trim((string) input('display_name', ''));
            $email = trim((string) input('email', '')) ?: null;
            $password = (string) input('password', '');
            $role = validate_choice((string) input('role', 'viewer'), ['owner', 'admin', 'moderator', 'viewer'], 'viewer');
            $active = input('active') === '1' ? 1 : 0;
            if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $username) || $displayName === '') {
                throw new InvalidArgumentException('Benutzername (mindestens 3 Zeichen) und Anzeigename sind Pflicht.');
            }
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Die E-Mail-Adresse ist ungültig.');
            }
            if ($role === 'owner' && auth()->user()['role'] !== 'owner') {
                throw new RuntimeException('Nur der Owner darf weitere Owner anlegen.');
            }
            if ($id === (int) auth()->user()['id'] && $active === 0) {
                throw new RuntimeException('Du kannst deinen eigenen Zugang nicht deaktivieren.');
            }

            if ($id > 0) {
                $targetStatement = db()->prepare('SELECT id, role FROM users WHERE id = :id');
                $targetStatement->execute(['id' => $id]);
                $targetMember = $targetStatement->fetch();
                if (!$targetMember) {
                    throw new RuntimeException('Der Team-Zugang wurde nicht gefunden.');
                }
                if ($targetMember['role'] === 'owner' && auth()->user()['role'] !== 'owner') {
                    throw new RuntimeException('Nur ein Owner darf einen Owner-Zugang verändern.');
                }
                if ($id === (int) auth()->user()['id'] && $targetMember['role'] === 'owner' && $role !== 'owner') {
                    throw new RuntimeException('Du kannst dir die eigene Owner-Rolle nicht entziehen.');
                }
                $fields = 'username = :username, display_name = :display_name, email = :email, role = :role, active = :active';
                $params = compact('username', 'role', 'active') + ['display_name' => $displayName, 'email' => $email, 'id' => $id];
                if ($password !== '') {
                    if (strlen($password) < 12) {
                        throw new InvalidArgumentException('Das Passwort muss mindestens 12 Zeichen haben.');
                    }
                    $fields .= ', password_hash = :password_hash';
                    $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                db()->prepare('UPDATE users SET ' . $fields . ' WHERE id = :id')->execute($params);
                audit('team.updated', 'user', $id, ['role' => $role, 'active' => $active]);
                flash('success', 'Team-Zugang wurde aktualisiert.');
            } else {
                if (strlen($password) < 12) {
                    throw new InvalidArgumentException('Ein neues Passwort muss mindestens 12 Zeichen haben.');
                }
                $statement = db()->prepare(
                    'INSERT INTO users (username, display_name, email, password_hash, role, active)
                     VALUES (:username, :display_name, :email, :password_hash, :role, :active)'
                );
                $statement->execute(compact('username', 'role', 'active') + [
                    'display_name' => $displayName,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $id = (int) db()->lastInsertId();
                audit('team.created', 'user', $id, ['role' => $role]);
                flash('success', 'Neuer Team-Zugang wurde erstellt.');
            }
            redirect('team');
        }

        if ($action === 'settings-general-save') {
            require_permission('settings.manage');
            $appName = mb_substr(trim((string) input('app_name', '')), 0, 100);
            $appUrl = rtrim(trim((string) input('app_url', '')), '/');
            $urlRewriteEnabled = input('url_rewrite_enabled') === '1';
            if (
                $appName === ''
                || !filter_var($appUrl, FILTER_VALIDATE_URL)
                || !in_array(parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true)
                || parse_url($appUrl, PHP_URL_QUERY) !== null
                || parse_url($appUrl, PHP_URL_FRAGMENT) !== null
            ) {
                throw new InvalidArgumentException('App-Name oder App-URL ist ungültig.');
            }
            settings()->set('app_name', $appName, false, (int) auth()->user()['id']);
            settings()->set('app_url', $appUrl, false, (int) auth()->user()['id']);
            settings()->set('url_rewrite_enabled', $urlRewriteEnabled ? 'true' : 'false', false, (int) auth()->user()['id']);
            audit('settings.general_updated', null, null, ['url_rewrite_enabled' => $urlRewriteEnabled]);
            flash('success', 'Allgemeine Einstellungen wurden gespeichert.');
            redirect('settings', ['section' => 'general']);
        }

        if ($action === 'settings-twitch-save') {
            require_permission('settings.manage');
            $clientId = trim((string) input('twitch_client_id', ''));
            $clientSecret = trim((string) input('twitch_client_secret', ''));
            $redirectUri = trim((string) input('twitch_redirect_uri', ''));
            if ($clientId !== '' && !preg_match('/^[a-zA-Z0-9_-]{5,255}$/', $clientId)) {
                throw new InvalidArgumentException('Die Twitch Client-ID ist ungültig.');
            }
            if ($redirectUri !== '' && (!filter_var($redirectUri, FILTER_VALIDATE_URL) || !in_array(parse_url($redirectUri, PHP_URL_SCHEME), ['http', 'https'], true))) {
                throw new InvalidArgumentException('Die Twitch Redirect-URI ist ungültig.');
            }
            $hasSecret = $clientSecret !== '' || settings()->hasValue('twitch_client_secret') || trim((string) env('TWITCH_CLIENT_SECRET', '')) !== '';
            if ($clientId !== '' && (!$hasSecret || $redirectUri === '')) {
                throw new InvalidArgumentException('Für Twitch werden Client-ID, Client-Secret und Redirect-URI benötigt.');
            }
            settings()->set('twitch_client_id', $clientId, false, (int) auth()->user()['id']);
            settings()->setSecretWhenProvided('twitch_client_secret', $clientSecret, (int) auth()->user()['id']);
            settings()->set('twitch_redirect_uri', $redirectUri, false, (int) auth()->user()['id']);
            audit('settings.twitch_updated', null, null, ['client_id_set' => $clientId !== '', 'redirect_uri' => $redirectUri]);
            flash('success', 'Twitch-API-Einstellungen wurden gespeichert.');
            redirect('settings', ['section' => 'twitch']);
        }

        if ($action === 'settings-discord-save' || $action === 'settings-discord-test') {
            require_permission('settings.manage');
            $enabled = input('discord_enabled') === '1';
            $applicationId = trim((string) input('discord_application_id', ''));
            $botToken = trim((string) input('discord_bot_token', ''));
            if ($applicationId !== '' && !preg_match('/^[0-9]{15,22}$/', $applicationId)) {
                throw new InvalidArgumentException('Die Discord Application-ID ist ungültig.');
            }
            if ($enabled && $botToken === '' && !settings()->hasValue('discord_bot_token')) {
                throw new InvalidArgumentException('Zum Aktivieren von Discord wird ein Bot-Token benötigt.');
            }

            $postedRoutes = $_POST['discord_routes'] ?? [];
            $postedRoutes = is_array($postedRoutes) ? $postedRoutes : [];
            $validatedRoutes = [];
            $managedDiscord = database_table_exists('discord_servers') && database_table_exists('discord_channels');
            if (!$managedDiscord) {
                foreach (discord_event_definitions() as $eventKey => $definition) {
                    $route = isset($postedRoutes[$eventKey]) && is_array($postedRoutes[$eventKey]) ? $postedRoutes[$eventKey] : [];
                    $guildId = trim((string) ($route['guild_id'] ?? ''));
                    $channelId = trim((string) ($route['channel_id'] ?? ''));
                    $routeEnabled = isset($route['enabled']);
                    foreach ([$guildId, $channelId] as $snowflake) {
                        if ($snowflake !== '' && !preg_match('/^[0-9]{15,22}$/', $snowflake)) {
                            throw new InvalidArgumentException('Server- oder Channel-ID für „' . $definition['label'] . '“ ist ungültig.');
                        }
                    }
                    if ($routeEnabled && ($guildId === '' || $channelId === '')) {
                        throw new InvalidArgumentException('Für die aktive Discord-Route „' . $definition['label'] . '“ fehlen Server- oder Channel-ID.');
                    }
                    $validatedRoutes[] = [
                        'event_key' => $eventKey,
                        'guild_id' => $guildId !== '' ? $guildId : null,
                        'channel_id' => $channelId !== '' ? $channelId : null,
                        'enabled' => $routeEnabled ? 1 : 0,
                        'updated_by' => auth()->user()['id'],
                    ];
                }
            }

            db()->beginTransaction();
            try {
                settings()->set('discord_enabled', $enabled ? 'true' : 'false', false, (int) auth()->user()['id']);
                settings()->set('discord_application_id', $applicationId, false, (int) auth()->user()['id']);
                settings()->setSecretWhenProvided('discord_bot_token', $botToken, (int) auth()->user()['id']);

                if (!$managedDiscord) {
                    $saveRoute = db()->prepare(
                        'INSERT INTO discord_notification_routes (event_key, guild_id, channel_id, enabled, updated_by)
                         VALUES (:event_key, :guild_id, :channel_id, :enabled, :updated_by)
                         ON DUPLICATE KEY UPDATE guild_id = VALUES(guild_id), channel_id = VALUES(channel_id),
                            enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()'
                    );
                    foreach ($validatedRoutes as $validatedRoute) {
                        $saveRoute->execute($validatedRoute);
                    }
                }
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            audit('settings.discord_updated', null, null, ['enabled' => $enabled]);

            if ($action === 'settings-discord-test') {
                $testChannelId = trim((string) input('discord_test_channel_id', ''));
                if ($testChannelId === '') {
                    $managedChannels = discord_managed_channels(true);
                    $testChannelId = (string) ($managedChannels[0]['channel_id'] ?? '');
                }
                discord()->sendTest($testChannelId);
                audit('settings.discord_tested', 'discord_channel', $testChannelId);
                flash('success', 'Discord-Testnachricht wurde gesendet.');
            } else {
                flash('success', 'Discord-Bot und Channel-Routing wurden gespeichert.');
            }
            redirect('settings', ['section' => 'discord']);
        }

        if ($action === 'discord-server-save') {
            require_permission('settings.manage');
            if (!database_table_exists('discord_servers')) throw new RuntimeException('Bitte führe zuerst die Systemmigrationen aus.');
            $id = max(0, (int) input('server_id', 0));
            $guildId = trim((string) input('guild_id', ''));
            $name = mb_substr(trim((string) input('server_name', '')), 0, 120);
            $enabled = input('server_enabled') === '1' ? 1 : 0;
            if (!preg_match('/^[0-9]{15,22}$/', $guildId) || $name === '') {
                throw new InvalidArgumentException('Discord Server-ID oder Anzeigename ist ungültig.');
            }
            if ($id > 0) {
                $statement = db()->prepare(
                    'UPDATE discord_servers SET guild_id = :guild_id, name = :name, enabled = :enabled,
                     updated_by = :updated_by WHERE id = :id'
                );
                $statement->execute([
                    'guild_id' => $guildId,
                    'name' => $name,
                    'enabled' => $enabled,
                    'updated_by' => auth()->user()['id'],
                    'id' => $id,
                ]);
                if ($statement->rowCount() === 0) {
                    $exists = db()->prepare('SELECT id FROM discord_servers WHERE id = :id');
                    $exists->execute(['id' => $id]);
                    if (!$exists->fetchColumn()) throw new RuntimeException('Der Discord-Server wurde nicht gefunden.');
                }
                audit('discord.server_updated', 'discord_server', $id, ['guild_id' => $guildId]);
            } else {
                $statement = db()->prepare(
                    'INSERT INTO discord_servers (guild_id, name, enabled, created_by, updated_by)
                     VALUES (:guild_id, :name, :enabled, :created_by, :updated_by)'
                );
                $statement->execute(['guild_id' => $guildId, 'name' => $name, 'enabled' => $enabled, 'created_by' => auth()->user()['id'], 'updated_by' => auth()->user()['id']]);
                $id = (int) db()->lastInsertId();
                audit('discord.server_created', 'discord_server', $id, ['guild_id' => $guildId]);
            }
            flash('success', 'Discord-Server wurde gespeichert.');
            redirect('settings', ['section' => 'discord']);
        }

        if ($action === 'discord-server-delete') {
            require_permission('settings.manage');
            $id = max(1, (int) input('server_id', 0));
            $statement = db()->prepare('DELETE FROM discord_servers WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() === 0) throw new RuntimeException('Der Discord-Server wurde nicht gefunden.');
            audit('discord.server_deleted', 'discord_server', $id);
            flash('success', 'Discord-Server und seine Channel-Zuordnungen wurden entfernt.');
            redirect('settings', ['section' => 'discord']);
        }

        if ($action === 'discord-server-sync') {
            require_permission('settings.manage');
            $id = max(1, (int) input('server_id', 0));
            $statement = db()->prepare('SELECT id, guild_id, name FROM discord_servers WHERE id = :id');
            $statement->execute(['id' => $id]);
            $server = $statement->fetch();
            if (!$server) throw new RuntimeException('Der Discord-Server wurde nicht gefunden.');
            $remote = discord()->guildChannels((string) $server['guild_id']);
            $saveChannel = db()->prepare(
                'INSERT INTO discord_channels (server_id, channel_id, name, enabled, created_by, updated_by)
                 VALUES (:server_id, :channel_id, :name, 1, :created_by, :updated_by)
                 ON DUPLICATE KEY UPDATE server_id = VALUES(server_id), name = VALUES(name),
                    updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()'
            );
            foreach ($remote['channels'] as $channel) {
                $saveChannel->execute([
                    'server_id' => $id,
                    'channel_id' => $channel['channel_id'],
                    'name' => $channel['name'],
                    'created_by' => auth()->user()['id'],
                    'updated_by' => auth()->user()['id'],
                ]);
            }
            audit('discord.server_channels_synced', 'discord_server', $id, ['channels' => count($remote['channels'])]);
            flash('success', count($remote['channels']) . ' Text-, Ankündigungs- und Thread-Channels wurden von Discord übernommen.');
            redirect('settings', ['section' => 'discord']);
        }

        if ($action === 'discord-channel-save') {
            require_permission('settings.manage');
            if (!database_table_exists('discord_channels')) throw new RuntimeException('Bitte führe zuerst die Systemmigrationen aus.');
            $id = max(0, (int) input('channel_record_id', 0));
            $serverId = max(1, (int) input('server_id', 0));
            $channelId = trim((string) input('channel_id', ''));
            $name = mb_substr(trim((string) input('channel_name', '')), 0, 120);
            $enabled = input('channel_enabled') === '1' ? 1 : 0;
            if (!preg_match('/^[0-9]{15,22}$/', $channelId) || $name === '') {
                throw new InvalidArgumentException('Discord Channel-ID oder Anzeigename ist ungültig.');
            }
            $serverCheck = db()->prepare('SELECT id FROM discord_servers WHERE id = :id');
            $serverCheck->execute(['id' => $serverId]);
            if (!$serverCheck->fetchColumn()) throw new RuntimeException('Der ausgewählte Discord-Server wurde nicht gefunden.');

            $postedEvents = $_POST['channel_events'] ?? [];
            $postedEvents = is_array($postedEvents) ? array_map('strval', $postedEvents) : [];
            $allowedEvents = array_keys(discord_event_definitions());
            $events = array_values(array_intersect($allowedEvents, $postedEvents));
            db()->beginTransaction();
            try {
                if ($id > 0) {
                    $statement = db()->prepare(
                        'UPDATE discord_channels SET server_id = :server_id, channel_id = :channel_id, name = :name,
                         enabled = :enabled, updated_by = :updated_by WHERE id = :id'
                    );
                    $statement->execute(['server_id' => $serverId, 'channel_id' => $channelId, 'name' => $name, 'enabled' => $enabled, 'updated_by' => auth()->user()['id'], 'id' => $id]);
                    $exists = db()->prepare('SELECT id FROM discord_channels WHERE id = :id');
                    $exists->execute(['id' => $id]);
                    if (!$exists->fetchColumn()) throw new RuntimeException('Der Discord-Channel wurde nicht gefunden.');
                } else {
                    $statement = db()->prepare(
                        'INSERT INTO discord_channels (server_id, channel_id, name, enabled, created_by, updated_by)
                         VALUES (:server_id, :channel_id, :name, :enabled, :created_by, :updated_by)'
                    );
                    $statement->execute(['server_id' => $serverId, 'channel_id' => $channelId, 'name' => $name, 'enabled' => $enabled, 'created_by' => auth()->user()['id'], 'updated_by' => auth()->user()['id']]);
                    $id = (int) db()->lastInsertId();
                }
                db()->prepare('DELETE FROM discord_channel_routes WHERE channel_id = :channel_id')->execute(['channel_id' => $id]);
                $route = db()->prepare('INSERT INTO discord_channel_routes (channel_id, event_key, enabled) VALUES (:channel_id, :event_key, 1)');
                foreach ($events as $eventKey) $route->execute(['channel_id' => $id, 'event_key' => $eventKey]);
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) db()->rollBack();
                throw $exception;
            }
            audit('discord.channel_saved', 'discord_channel', $id, ['channel_id' => $channelId, 'events' => $events]);
            flash('success', 'Discord-Channel und Ereignisse wurden gespeichert.');
            redirect('settings', ['section' => 'discord']);
        }

        if ($action === 'discord-channel-delete') {
            require_permission('settings.manage');
            $id = max(1, (int) input('channel_record_id', 0));
            $statement = db()->prepare('DELETE FROM discord_channels WHERE id = :id');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() === 0) throw new RuntimeException('Der Discord-Channel wurde nicht gefunden.');
            audit('discord.channel_deleted', 'discord_channel', $id);
            flash('success', 'Discord-Channel wurde entfernt.');
            redirect('settings', ['section' => 'discord']);
        }

        if ($action === 'settings-smtp-save' || $action === 'settings-smtp-test') {
            require_permission('settings.manage');
            $enabled = input('smtp_enabled') === '1';
            $host = trim((string) input('smtp_host', ''));
            $port = max(1, min(65535, (int) input('smtp_port', 587)));
            $encryption = validate_choice((string) input('smtp_encryption', 'tls'), ['tls', 'ssl', 'none'], 'tls');
            $authMode = validate_choice((string) input('smtp_auth', 'login'), ['login', 'plain', 'none'], 'login');
            $username = trim((string) input('smtp_username', ''));
            $password = (string) input('smtp_password', '');
            $fromEmail = trim((string) input('smtp_from_email', ''));
            $fromName = mb_substr(trim((string) input('smtp_from_name', 'Twitch ModDesk')), 0, 100);
            if ($enabled && $host === '') {
                throw new InvalidArgumentException('Zum Aktivieren des Mailversands wird ein SMTP-Host benötigt.');
            }
            if ($host !== '' && (str_contains($host, '://') || preg_match('/[\s\r\n]/', $host))) {
                throw new InvalidArgumentException('Der SMTP-Hostname ist ungültig.');
            }
            if (($enabled || $host !== '') && (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || $fromName === '')) {
                throw new InvalidArgumentException('Für SMTP werden eine gültige Absenderadresse und ein Absendername benötigt.');
            }
            $hasPassword = $password !== '' || settings()->hasValue('smtp_password');
            if (($enabled || $host !== '') && $authMode !== 'none' && ($username === '' || !$hasPassword)) {
                throw new InvalidArgumentException('Für die SMTP-Anmeldung fehlen Benutzername oder Passwort.');
            }

            settings()->set('smtp_enabled', $enabled ? 'true' : 'false', false, (int) auth()->user()['id']);
            settings()->set('smtp_host', $host, false, (int) auth()->user()['id']);
            settings()->set('smtp_port', (string) $port, false, (int) auth()->user()['id']);
            settings()->set('smtp_encryption', $encryption, false, (int) auth()->user()['id']);
            settings()->set('smtp_auth', $authMode, false, (int) auth()->user()['id']);
            settings()->set('smtp_username', $username, false, (int) auth()->user()['id']);
            settings()->setSecretWhenProvided('smtp_password', $password, (int) auth()->user()['id']);
            settings()->set('smtp_from_email', $fromEmail, false, (int) auth()->user()['id']);
            settings()->set('smtp_from_name', $fromName, false, (int) auth()->user()['id']);
            audit('settings.smtp_updated', null, null, ['enabled' => $enabled, 'host' => $host, 'port' => $port]);

            if ($action === 'settings-smtp-test') {
                $recipient = trim((string) input('smtp_test_recipient', ''));
                smtp_mailer()->sendTest($recipient);
                audit('settings.smtp_tested', 'email', $recipient);
                flash('success', 'SMTP-Testmail wurde an ' . $recipient . ' gesendet.');
            } else {
                flash('success', 'SMTP-Einstellungen wurden gespeichert.');
            }
            redirect('settings', ['section' => 'smtp']);
        }

        if ($action === 'design-save') {
            require_permission('design.manage');
            $userId = (int) auth()->user()['id'];
            $appName = mb_substr(trim((string) input('app_name', '')), 0, 100);
            $headerEyebrow = mb_substr(trim((string) input('header_eyebrow', 'CONTROL CENTER')), 0, 60);
            $headerVersionText = mb_substr(trim((string) input('header_version_text', 'Version {version}')), 0, 80);
            $footerText = mb_substr(trim((string) input('footer_text', '')), 0, 300);
            if ($appName === '' || $headerEyebrow === '') {
                throw new InvalidArgumentException('App-Name und Headerzeile dürfen nicht leer sein.');
            }

            $theme = [];
            foreach (theme_values() as $key => $fallback) {
                $postedColor = trim((string) input('theme_' . $key, $fallback));
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $postedColor)) {
                    throw new InvalidArgumentException('Die Designfarbe „' . $key . '“ ist ungültig.');
                }
                $theme[$key] = strtoupper($postedColor);
            }

            $postedMenu = $_POST['menu'] ?? [];
            $postedMenu = is_array($postedMenu) ? $postedMenu : [];
            $menuConfig = [];
            foreach (navigation_definitions() as $menuKey => $definition) {
                $item = isset($postedMenu[$menuKey]) && is_array($postedMenu[$menuKey]) ? $postedMenu[$menuKey] : [];
                $label = mb_substr(trim((string) ($item['label'] ?? $definition['label'])), 0, 45);
                $icon = mb_substr(trim((string) ($item['icon'] ?? $definition['icon'])), 0, 4);
                if ($label === '' || $icon === '') {
                    throw new InvalidArgumentException('Jeder Menüpunkt benötigt Beschriftung und Symbol.');
                }
                $menuConfig[$menuKey] = [
                    'label' => $label,
                    'icon' => $icon,
                    'order' => max(0, min(999, (int) ($item['order'] ?? $definition['order']))),
                    'enabled' => isset($item['enabled']),
                ];
            }

            $postedPages = $_POST['pages'] ?? [];
            $postedPages = is_array($postedPages) ? $postedPages : [];
            $pageConfig = [];
            foreach (editable_page_definitions() as $pageKey => $defaultTitle) {
                $item = isset($postedPages[$pageKey]) && is_array($postedPages[$pageKey]) ? $postedPages[$pageKey] : [];
                $titleOverride = mb_substr(trim((string) ($item['title'] ?? '')), 0, 100);
                $topText = mb_substr(trim((string) ($item['top_text'] ?? '')), 0, 240);
                if ($titleOverride !== '' || $topText !== '') {
                    $pageConfig[$pageKey] = ['title' => $titleOverride, 'top_text' => $topText];
                }
            }

            db()->beginTransaction();
            try {
                settings()->set('app_name', $appName, false, $userId);
                settings()->set('header_eyebrow', $headerEyebrow, false, $userId);
                settings()->set('header_version_text', $headerVersionText, false, $userId);
                settings()->set('footer_text', $footerText, false, $userId);
                foreach ($theme as $key => $color) {
                    settings()->set('theme_' . $key, $color, false, $userId);
                }
                settings()->set(
                    'menu_config_json',
                    json_encode($menuConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    false,
                    $userId,
                );
                settings()->set(
                    'page_content_json',
                    json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    false,
                    $userId,
                );
                $logoUpload = $_FILES['brand_logo'] ?? null;
                if (is_array($logoUpload) && (int) ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if (!database_table_exists('branding_assets')) {
                        throw new RuntimeException('Bitte führe zuerst unter Einstellungen → System die ausstehenden Datenbankmigrationen aus.');
                    }
                    branding()->storeLogo($logoUpload, $userId);
                } elseif (input('remove_logo') === '1') {
                    branding()->clearLogo($userId);
                }
                db()->commit();
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
            audit('design.updated', 'settings', 'appearance', ['menu_items' => count($menuConfig), 'page_overrides' => count($pageConfig)]);
            flash('success', 'Design, Navigation und Seiteninhalte wurden gespeichert.');
            redirect('design');
        }

        if ($action === 'discord-template-save') {
            require_permission('discord.studio');
            if (!database_table_exists('discord_message_templates')) {
                throw new RuntimeException('Bitte führe zuerst unter Einstellungen → System die ausstehenden Datenbankmigrationen aus.');
            }
            $template = normalize_discord_studio_input($_POST, true);
            $templateId = (int) input('template_id', 0);
            $parameters = [
                'name' => $template['name'],
                'message_content' => $template['message_content'] !== '' ? $template['message_content'] : null,
                'embed_title' => $template['embed_title'] !== '' ? $template['embed_title'] : null,
                'embed_description' => $template['embed_description'] !== '' ? $template['embed_description'] : null,
                'embed_url' => $template['embed_url'] !== '' ? $template['embed_url'] : null,
                'embed_color' => $template['embed_color'],
                'author_name' => $template['author_name'] !== '' ? $template['author_name'] : null,
                'author_url' => $template['author_url'] !== '' ? $template['author_url'] : null,
                'author_icon_url' => $template['author_icon_url'] !== '' ? $template['author_icon_url'] : null,
                'thumbnail_url' => $template['thumbnail_url'] !== '' ? $template['thumbnail_url'] : null,
                'image_url' => $template['image_url'] !== '' ? $template['image_url'] : null,
                'footer_text' => $template['footer_text'] !== '' ? $template['footer_text'] : null,
                'footer_icon_url' => $template['footer_icon_url'] !== '' ? $template['footer_icon_url'] : null,
                'include_timestamp' => $template['include_timestamp'],
                'fields_json' => $template['fields'] !== []
                    ? json_encode($template['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    : null,
                'updated_by' => auth()->user()['id'],
            ];
            if ($templateId > 0) {
                $parameters['id'] = $templateId;
                $statement = db()->prepare(
                    'UPDATE discord_message_templates SET name = :name, message_content = :message_content,
                     embed_title = :embed_title, embed_description = :embed_description, embed_url = :embed_url,
                     embed_color = :embed_color, author_name = :author_name, author_url = :author_url,
                     author_icon_url = :author_icon_url, thumbnail_url = :thumbnail_url, image_url = :image_url,
                     footer_text = :footer_text, footer_icon_url = :footer_icon_url,
                     include_timestamp = :include_timestamp, fields_json = :fields_json, updated_by = :updated_by
                     WHERE id = :id'
                );
                $statement->execute($parameters);
                if ($statement->rowCount() === 0) {
                    $exists = db()->prepare('SELECT id FROM discord_message_templates WHERE id = :id');
                    $exists->execute(['id' => $templateId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('Die Discord-Vorlage wurde nicht gefunden.');
                    }
                }
                audit('discord.template_updated', 'discord_template', $templateId);
            } else {
                $parameters['created_by'] = auth()->user()['id'];
                $statement = db()->prepare(
                    'INSERT INTO discord_message_templates
                        (name, message_content, embed_title, embed_description, embed_url, embed_color,
                         author_name, author_url, author_icon_url, thumbnail_url, image_url, footer_text,
                         footer_icon_url, include_timestamp, fields_json, created_by, updated_by)
                     VALUES
                        (:name, :message_content, :embed_title, :embed_description, :embed_url, :embed_color,
                         :author_name, :author_url, :author_icon_url, :thumbnail_url, :image_url, :footer_text,
                         :footer_icon_url, :include_timestamp, :fields_json, :created_by, :updated_by)'
                );
                $statement->execute($parameters);
                $templateId = (int) db()->lastInsertId();
                audit('discord.template_created', 'discord_template', $templateId);
            }
            flash('success', 'Discord-Nachrichtenvorlage wurde gespeichert.');
            redirect('discord-studio', ['template' => $templateId]);
        }

        if ($action === 'discord-template-delete') {
            require_permission('discord.studio');
            if (!database_table_exists('discord_message_templates')) {
                throw new RuntimeException('Bitte führe zuerst unter Einstellungen → System die ausstehenden Datenbankmigrationen aus.');
            }
            $templateId = (int) input('template_id', 0);
            if ($templateId < 1) {
                throw new InvalidArgumentException('Ungültige Discord-Vorlage.');
            }
            $statement = db()->prepare('DELETE FROM discord_message_templates WHERE id = :id');
            $statement->execute(['id' => $templateId]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Die Discord-Vorlage wurde nicht gefunden.');
            }
            audit('discord.template_deleted', 'discord_template', $templateId);
            flash('success', 'Discord-Vorlage wurde gelöscht.');
            redirect('discord-studio');
        }

        if ($action === 'discord-message-send') {
            require_permission('discord.studio');
            $template = normalize_discord_studio_input($_POST, false);
            $channelId = trim((string) input('discord_channel_id', ''));
            $guildId = trim((string) input('discord_guild_id', ''));
            foreach ([$channelId, $guildId] as $snowflake) {
                if ($snowflake !== '' && !preg_match('/^[0-9]{15,22}$/', $snowflake)) {
                    throw new InvalidArgumentException('Server- oder Channel-ID ist ungültig.');
                }
            }
            if ($channelId === '') {
                throw new InvalidArgumentException('Bitte wähle einen Discord-Channel aus.');
            }
            discord()->sendCustomMessage($channelId, discord_message_from_template($template), $guildId);
            audit('discord.manual_message_sent', 'discord_channel', $channelId, ['guild_id' => $guildId ?: null]);
            flash('success', 'Die gestaltete Discord-Nachricht wurde live gesendet.');
            redirect('discord-studio', (int) input('template_id', 0) > 0 ? ['template' => (int) input('template_id')] : []);
        }

        if ($action === 'system-migrations-run') {
            require_permission('updates.manage');
            $applied = SchemaMigrator::migrate(db(), base_path('database/migrations'));
            audit('system.migrations_run', 'system', 'database', ['applied' => $applied]);
            flash('success', $applied === [] ? 'Die Datenbank ist bereits aktuell.' : 'Migrationen ausgeführt: ' . implode(', ', $applied));
            redirect('settings', ['section' => 'system']);
        }

        if ($action === 'module-toggle') {
            require_permission('modules.manage');
            $moduleKey = trim((string) input('module_key', ''));
            $enabled = input('enabled') === '1';
            modules()->setEnabled($moduleKey, $enabled);
            audit($enabled ? 'module.enabled' : 'module.disabled', 'module', $moduleKey);
            flash('success', 'Modul wurde ' . ($enabled ? 'aktiviert.' : 'deaktiviert.'));
            redirect('modules');
        }

        if ($action === 'module-config-save') {
            require_permission('modules.manage');
            $moduleKey = trim((string) input('module_key', ''));
            $postedSettings = $_POST['module_settings'] ?? [];
            $postedSettings = is_array($postedSettings) ? $postedSettings : [];
            $saved = modules()->saveConfiguration($moduleKey, $postedSettings, (int) auth()->user()['id']);
            audit('module.configuration_updated', 'module', $moduleKey, ['fields' => $saved]);
            flash('success', 'Moduleinstellungen wurden gespeichert.');
            redirect('modules', ['edit' => $moduleKey]);
        }

        if ($action === 'module-upload') {
            require_permission('modules.manage');
            $upload = $_FILES['module_package'] ?? null;
            if (!is_array($upload)) throw new RuntimeException('Bitte wähle ein Modul-ZIP aus.');
            $result = modules()->installUploaded($upload, (int) auth()->user()['id']);
            audit('module.installed', 'module', $result['key'], ['version' => $result['version'], 'migrations' => $result['migrations']]);
            flash('success', $result['name'] . ' ' . $result['version'] . ' wurde installiert und aktiviert.');
            redirect('modules', ['edit' => $result['key']]);
        }

        if ($action === 'module-remove') {
            require_permission('modules.manage');
            $moduleKey = trim((string) input('module_key', ''));
            modules()->removeCustom($moduleKey);
            audit('module.removed', 'module', $moduleKey);
            flash('success', 'Das Zusatzmodul wurde entfernt und sein Ordner gesichert.');
            redirect('modules');
        }

        if ($action === 'github-settings-save') {
            require_permission('updates.manage');
            if (!database_table_exists('github_release_status')) throw new RuntimeException('Bitte führe zuerst die Systemmigrationen aus.');
            $enabled = input('github_updates_enabled') === '1';
            $repository = strtolower(trim((string) input('github_repository', '')));
            $assetName = basename(trim((string) input('github_asset_name', 'twitch-moddesk.zip')));
            $interval = max(1, min(168, (int) input('github_check_interval_hours', 6)));
            $token = trim((string) input('github_token', ''));
            if ($repository !== '' && !preg_match('#^[a-z0-9_.-]{1,100}/[a-z0-9_.-]{1,100}$#i', $repository)) {
                throw new InvalidArgumentException('Das Repository muss als owner/repository eingetragen werden.');
            }
            if ($enabled && $repository === '') throw new InvalidArgumentException('Zum Aktivieren wird ein GitHub-Repository benötigt.');
            if ($assetName === '' || !preg_match('/^[A-Za-z0-9_.-]{3,190}\.zip$/i', $assetName)) {
                throw new InvalidArgumentException('Der GitHub-Assetname muss ein einfacher ZIP-Dateiname sein.');
            }
            $oldRepository = (string) settings()->get('github_repository', '');
            settings()->set('github_updates_enabled', $enabled ? 'true' : 'false', false, (int) auth()->user()['id']);
            settings()->set('github_repository', $repository, false, (int) auth()->user()['id']);
            settings()->set('github_asset_name', $assetName, false, (int) auth()->user()['id']);
            settings()->set('github_check_interval_hours', (string) $interval, false, (int) auth()->user()['id']);
            settings()->setSecretWhenProvided('github_token', $token, (int) auth()->user()['id']);
            if ($oldRepository !== $repository) db()->exec('DELETE FROM github_release_status WHERE id = 1');
            audit('settings.github_updated', 'github_repository', $repository, ['enabled' => $enabled, 'asset' => $assetName]);
            flash('success', 'GitHub-Updateverbindung wurde gespeichert.');
            redirect('settings', ['section' => 'github']);
        }

        if ($action === 'github-release-check') {
            require_permission('updates.manage');
            $status = github_releases()->checkLatest(true);
            audit('github.release_checked', 'github_release', $status['release_id'] ?? null, ['version' => $status['version'] ?? null]);
            flash('success', github_releases()->updateAvailable($status)
                ? 'Neue ModDesk-Version ' . $status['version'] . ' ist verfügbar.'
                : 'Kein neueres Release gefunden. Installiert ist ' . app_version() . '.');
            redirect('settings', ['section' => 'github']);
        }

        if ($action === 'github-update-install') {
            require_permission('updates.manage');
            $status = github_releases()->checkLatest(true);
            if (!github_releases()->updateAvailable($status)) throw new RuntimeException('Es ist kein neueres GitHub-Release verfügbar.');
            $download = github_releases()->downloadCachedPackage();
            try {
                $result = (new UpdateManager(db(), base_path()))->applyLocalPackage($download['path'], $download['name'], (int) auth()->user()['id']);
            } finally {
                if (isset($download['path']) && is_file($download['path'])) @unlink($download['path']);
            }
            audit('system.github_update_applied', 'system_version', $result['to_version'], ['from' => $result['from_version'], 'repository' => github_releases()->repository()]);
            flash('success', 'GitHub-Update auf Version ' . $result['to_version'] . ' wurde installiert; Installation und Daten bleiben erhalten.');
            redirect('settings', ['section' => 'github']);
        }

        if ($action === 'discord-changelog-post') {
            require_permission('discord.studio');
            require_module_enabled('discord');
            $channelId = trim((string) input('discord_channel_id', ''));
            if (!discord_channel_is_managed($channelId)) throw new InvalidArgumentException('Bitte wähle einen aktiven, verwalteten Discord-Channel.');
            $source = (string) input('changelog_source', 'current');
            $version = app_version();
            $title = 'ModDesk ' . $version . ' · Changelog';
            $body = changelog_for_version($version);
            if ($source === 'latest') {
                $status = github_update_status();
                if ($status === null || empty($status['version']) || empty($status['release_body'])) throw new RuntimeException('Es ist kein GitHub-Changelog verfügbar.');
                $version = (string) $status['version'];
                $title = 'ModDesk ' . $version . ' · Release verfügbar';
                $body = (string) $status['release_body'];
            }
            $guildId = '';
            foreach (discord_managed_channels(true) as $channel) {
                if ((string) $channel['channel_id'] === $channelId) $guildId = (string) ($channel['guild_id'] ?? '');
            }
            discord()->sendCustomMessage($channelId, [
                'content' => '',
                'embed' => [
                    'title' => $title,
                    'description' => mb_substr(trim($body), 0, 4096),
                    'color' => 9525247,
                    'footer_text' => 'Twitch ModDesk · veröffentlicht aus dem Panel',
                    'timestamp' => true,
                    'fields' => [],
                ],
            ], $guildId);
            audit('discord.changelog_sent', 'discord_channel', $channelId, ['version' => $version]);
            flash('success', 'Changelog ' . $version . ' wurde an Discord gesendet.');
            redirect($returnPage);
        }

        if ($action === 'system-update-import') {
            require_permission('updates.manage');
            if (!database_table_exists('system_updates')) {
                throw new RuntimeException('Bitte führe zuerst die Datenbankmigrationen unter Einstellungen → System aus.');
            }
            $upload = $_FILES['update_package'] ?? null;
            if (!is_array($upload)) {
                throw new RuntimeException('Bitte wähle ein ModDesk-Update-ZIP aus.');
            }
            $result = (new UpdateManager(db(), base_path()))->applyUploadedPackage($upload, (int) auth()->user()['id']);
            audit('system.update_applied', 'system_version', $result['to_version'], [
                'from' => $result['from_version'],
                'files' => $result['files'],
                'migrations' => $result['migrations'],
            ]);
            flash('success', 'Update auf Version ' . $result['to_version'] . ' wurde installiert.');
            redirect('settings', ['section' => 'updates']);
        }

        if ($action === 'ban-sync-channel-add') {
            require_permission('twitch.configure');
            $channel = resolve_twitch_target((string) input('channel_login', ''));
            $canModerate = twitch()->canModerateChannel((string) $channel['id']);
            $validationStatus = $canModerate === true ? 'verified' : ($canModerate === false ? 'denied' : 'unknown');
            $validatedAt = $canModerate === null ? null : gmdate('Y-m-d H:i:s');

            $statement = db()->prepare(
                'INSERT INTO ban_sync_channels
                    (twitch_user_id, enabled, validation_status, validated_at, created_by)
                 VALUES (:twitch_user_id, 1, :validation_status, :validated_at, :created_by)
                 ON DUPLICATE KEY UPDATE enabled = 1, validation_status = VALUES(validation_status),
                    validated_at = VALUES(validated_at), updated_at = UTC_TIMESTAMP()'
            );
            $statement->execute([
                'twitch_user_id' => $channel['id'],
                'validation_status' => $validationStatus,
                'validated_at' => $validatedAt,
                'created_by' => auth()->user()['id'],
            ]);
            audit('ban_sync.channel_added', 'twitch_channel', $channel['id'], [
                'login' => $channel['login'],
                'validation_status' => $validationStatus,
            ]);

            if ($validationStatus === 'verified') {
                flash('success', $channel['display_name'] . ' wurde als bestätigter BanSync-Kanal aktiviert.');
            } elseif ($validationStatus === 'denied') {
                flash('warning', $channel['display_name'] . ' wurde hinzugefügt, aber das verbundene Konto hat dort aktuell keine Mod-Rechte.');
            } else {
                flash('warning', $channel['display_name'] . ' wurde hinzugefügt. Für die automatische Rechteprüfung bitte Twitch neu verbinden.');
            }
            redirect('ban-sync');
        }

        if ($action === 'ban-sync-channel-toggle') {
            require_permission('twitch.configure');
            $channelId = (int) input('channel_id', 0);
            if ($channelId < 1) {
                throw new InvalidArgumentException('Ungültiger BanSync-Kanal.');
            }
            $enabled = input('enabled') === '1' ? 1 : 0;
            $statement = db()->prepare('UPDATE ban_sync_channels SET enabled = :enabled WHERE id = :id');
            $statement->execute(['enabled' => $enabled, 'id' => $channelId]);
            if ($statement->rowCount() === 0) {
                $exists = db()->prepare('SELECT id FROM ban_sync_channels WHERE id = :id');
                $exists->execute(['id' => $channelId]);
                if (!$exists->fetchColumn()) {
                    throw new RuntimeException('Der BanSync-Kanal wurde nicht gefunden.');
                }
            }
            audit($enabled ? 'ban_sync.channel_enabled' : 'ban_sync.channel_disabled', 'ban_sync_channel', $channelId);
            flash('success', 'Der BanSync-Kanal wurde ' . ($enabled ? 'aktiviert.' : 'pausiert.'));
            redirect('ban-sync');
        }

        if ($action === 'ban-sync-verify') {
            require_permission('twitch.configure');
            $connection = twitch()->connection();
            if ($connection === null) {
                throw new RuntimeException('Bitte verbinde zuerst dein Twitch-Modkonto.');
            }

            $verifiedIds = [(string) $connection['twitch_user_id'] => true];
            foreach (twitch()->moderatedChannels() as $moderatedChannel) {
                $id = (string) ($moderatedChannel['broadcaster_id'] ?? '');
                if ($id !== '') {
                    $verifiedIds[$id] = true;
                }
            }

            $channels = db()->query('SELECT id, twitch_user_id FROM ban_sync_channels')->fetchAll();
            $update = db()->prepare(
                'UPDATE ban_sync_channels SET validation_status = :validation_status,
                 validated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $verifiedCount = 0;
            foreach ($channels as $channel) {
                $verified = isset($verifiedIds[(string) $channel['twitch_user_id']]);
                $update->execute([
                    'validation_status' => $verified ? 'verified' : 'denied',
                    'id' => $channel['id'],
                ]);
                $verifiedCount += $verified ? 1 : 0;
            }

            audit('ban_sync.channels_verified', 'twitch_connection', $connection['id'], [
                'verified' => $verifiedCount,
                'total' => count($channels),
            ]);
            $type = $verifiedCount === count($channels) ? 'success' : 'warning';
            flash($type, $verifiedCount . ' von ' . count($channels) . ' BanSync-Kanälen wurden als moderierbar bestätigt.');
            redirect('ban-sync');
        }

        if ($action === 'ban-sync-execute') {
            require_permission('twitch.use');
            if (twitch()->connection() === null) {
                throw new RuntimeException('Bitte verbinde zuerst dein Twitch-Modkonto.');
            }
            if (!twitch()->hasScope('moderator:manage:banned_users')) {
                throw new RuntimeException('Dem Twitch-Token fehlt moderator:manage:banned_users. Bitte verbinde das Konto erneut.');
            }

            $syncAction = validate_choice((string) input('sync_action', ''), ['ban', 'unban'], '');
            if ($syncAction === '') {
                throw new InvalidArgumentException('Bitte wähle Ban oder Unban.');
            }
            $reason = mb_substr(trim((string) input('reason', '')), 0, 500);
            if ($syncAction === 'ban' && $reason === '') {
                throw new InvalidArgumentException('Für einen Ban ist eine Begründung erforderlich.');
            }
            $target = resolve_twitch_target((string) input('twitch_login', ''));

            $channelIds = [];
            foreach ((array) ($_POST['channel_ids'] ?? []) as $channelId) {
                if ((is_int($channelId) || is_string($channelId)) && (int) $channelId > 0) {
                    $channelIds[] = (int) $channelId;
                }
            }
            $channelIds = array_slice(array_values(array_unique($channelIds)), 0, 50);
            if ($channelIds === []) {
                throw new InvalidArgumentException('Wähle mindestens einen aktiven Zielkanal.');
            }

            $placeholders = [];
            $parameters = [];
            foreach ($channelIds as $index => $channelId) {
                $placeholder = ':channel_' . $index;
                $placeholders[] = $placeholder;
                $parameters['channel_' . $index] = $channelId;
            }
            $statement = db()->prepare(
                'SELECT bsc.id, bsc.twitch_user_id, bsc.validation_status, tu.login, tu.display_name
                 FROM ban_sync_channels bsc
                 JOIN twitch_users tu ON tu.twitch_user_id = bsc.twitch_user_id
                 WHERE bsc.enabled = 1 AND bsc.id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY tu.display_name'
            );
            $statement->execute($parameters);
            $channels = $statement->fetchAll();
            if ($channels === []) {
                throw new RuntimeException('Keiner der ausgewählten BanSync-Kanäle ist aktiv.');
            }

            $insertJob = db()->prepare(
                'INSERT INTO ban_sync_jobs
                    (target_twitch_user_id, action, reason, status, channel_count, requested_by)
                 VALUES (:target, :action, :reason, \'running\', :channel_count, :requested_by)'
            );
            $insertJob->execute([
                'target' => $target['id'],
                'action' => $syncAction,
                'reason' => $reason !== '' ? $reason : null,
                'channel_count' => count($channels),
                'requested_by' => auth()->user()['id'],
            ]);
            $jobId = (int) db()->lastInsertId();
            $successCount = 0;
            $failureCount = 0;

            $insertResult = db()->prepare(
                'INSERT INTO ban_sync_results
                    (job_id, channel_twitch_user_id, success, http_status, error_message, api_response)
                 VALUES (:job_id, :channel_id, :success, :http_status, :error_message, :api_response)'
            );
            foreach ($channels as $channel) {
                $success = false;
                $httpStatus = null;
                $errorMessage = null;
                $response = [];
                try {
                    $response = $syncAction === 'ban'
                        ? twitch()->banOnChannel((string) $channel['twitch_user_id'], (string) $target['id'], $reason)
                        : twitch()->unbanOnChannel((string) $channel['twitch_user_id'], (string) $target['id']);
                    $success = true;
                    $successCount++;
                } catch (TwitchApiException $exception) {
                    $httpStatus = $exception->status > 0 ? $exception->status : null;
                    $errorMessage = mb_substr($exception->getMessage(), 0, 1000);
                    $response = $exception->response;
                    $failureCount++;
                } catch (Throwable $exception) {
                    $errorMessage = mb_substr($exception->getMessage(), 0, 1000);
                    $failureCount++;
                }

                $insertResult->execute([
                    'job_id' => $jobId,
                    'channel_id' => $channel['twitch_user_id'],
                    'success' => $success ? 1 : 0,
                    'http_status' => $httpStatus,
                    'error_message' => $errorMessage,
                    'api_response' => $response !== []
                        ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                        : null,
                ]);

                $journalReason = '@' . $channel['login'] . ($reason !== '' ? ' · ' . $reason : '');
                record_mod_action(
                    (string) $target['id'],
                    $syncAction === 'ban' ? 'sync_ban' : 'sync_unban',
                    null,
                    $journalReason,
                    $success,
                    $response,
                );
            }

            $jobStatus = $successCount === count($channels)
                ? 'completed'
                : ($successCount === 0 ? 'failed' : 'partial');
            db()->prepare(
                'UPDATE ban_sync_jobs SET status = :status, success_count = :success_count,
                 failure_count = :failure_count, completed_at = UTC_TIMESTAMP() WHERE id = :id'
            )->execute([
                'status' => $jobStatus,
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'id' => $jobId,
            ]);
            audit('ban_sync.executed', 'ban_sync_job', $jobId, [
                'action' => $syncAction,
                'target' => $target['login'],
                'success' => $successCount,
                'failed' => $failureCount,
            ]);
            notify_discord(
                'ban_sync',
                ($syncAction === 'ban' ? 'BanSync' : 'UnbanSync') . ' für @' . $target['login'],
                $reason !== '' ? $reason : 'Keine interne Begründung angegeben.',
                [
                    ['name' => 'Erfolgreich', 'value' => (string) $successCount, 'inline' => true],
                    ['name' => 'Fehlgeschlagen', 'value' => (string) $failureCount, 'inline' => true],
                    ['name' => 'Kanäle', 'value' => mb_substr(implode(', ', array_map(static fn (array $item): string => '@' . $item['login'], $channels)), 0, 1024), 'inline' => false],
                    ['name' => 'Ausgeführt von', 'value' => (string) auth()->user()['display_name'], 'inline' => true],
                ],
                $failureCount === 0 ? 3858069 : ($successCount > 0 ? 16752451 : 16733552),
            );

            if ($jobStatus === 'completed') {
                flash('success', 'BanSync abgeschlossen: alle ' . $successCount . ' Kanäle waren erfolgreich.');
            } elseif ($jobStatus === 'partial') {
                flash('warning', 'BanSync teilweise abgeschlossen: ' . $successCount . ' erfolgreich, ' . $failureCount . ' fehlgeschlagen.');
            } else {
                flash('danger', 'BanSync fehlgeschlagen. Die Einzelheiten stehen im Kanalprotokoll.');
            }
            redirect('ban-sync', ['job' => $jobId]);
        }

        if ($action === 'twitch-set-channel') {
            require_permission('twitch.configure');
            $channel = twitch()->setChannelByLogin((string) input('channel_login', ''), (int) auth()->user()['id']);
            audit('twitch.channel_changed', 'twitch_user', $channel['id'], ['login' => $channel['login']]);
            flash('success', 'Zielkanal wurde auf ' . $channel['display_name'] . ' gesetzt.');
            redirect('twitch');
        }

        if ($action === 'twitch-validate') {
            require_permission('twitch.configure');
            twitch()->validateConnection();
            audit('twitch.token_validated');
            flash('success', 'Die Twitch-Verbindung ist gültig.');
            redirect('twitch');
        }

        if ($action === 'twitch-sync-moderators') {
            require_permission('twitch.use');
            $count = twitch()->syncModerators();
            audit('twitch.moderators_synced', 'twitch_channel', twitch()->channel()['id'], ['count' => $count]);
            flash('success', $count . ' Moderatorinnen und Moderatoren wurden synchronisiert.');
            redirect('twitch');
        }

        if ($action === 'twitch-sync-bans') {
            require_permission('twitch.use');
            $count = twitch()->syncBannedUsers();
            audit('twitch.bans_synced', 'twitch_channel', twitch()->channel()['id'], ['count' => $count]);
            flash('success', $count . ' Bans und Timeouts wurden synchronisiert.');
            redirect('twitch');
        }

        if ($action === 'twitch-lookup') {
            require_permission('twitch.use');
            $target = resolve_twitch_target((string) input('twitch_login', ''));
            audit('twitch.user_lookup', 'twitch_user', $target['id']);
            redirect('twitch-users', ['id' => $target['id']]);
        }

        if ($action === 'twitch-mod-action') {
            require_permission('twitch.use');
            $target = resolve_twitch_target((string) input('twitch_login', ''));
            $modAction = validate_choice((string) input('mod_action', ''), ['warn', 'timeout', 'ban', 'unban'], '');
            if ($modAction === '') {
                throw new InvalidArgumentException('Bitte wähle eine Moderationsaktion.');
            }
            $reason = trim((string) input('reason', ''));
            $caseId = (int) input('case_id', 0) ?: null;
            $duration = null;
            if ($modAction === 'timeout') {
                $minutes = max(1, min(20160, (int) input('duration_minutes', 10)));
                $duration = $minutes * 60;
            }
            if (in_array($modAction, ['warn', 'timeout', 'ban'], true) && $reason === '') {
                throw new InvalidArgumentException('Für diese Aktion ist eine Begründung erforderlich.');
            }

            try {
                $response = match ($modAction) {
                    'warn' => twitch()->warn((string) $target['id'], $reason),
                    'timeout' => twitch()->ban((string) $target['id'], $reason, $duration),
                    'ban' => twitch()->ban((string) $target['id'], $reason),
                    'unban' => twitch()->unban((string) $target['id']),
                };
                record_mod_action((string) $target['id'], $modAction, $duration, $reason ?: null, true, $response, $caseId);
                audit('twitch.' . $modAction, 'twitch_user', $target['id'], ['case_id' => $caseId, 'duration' => $duration]);
                notify_discord(
                    'moderation_action',
                    'Twitch-Modaktion: ' . strtoupper($modAction),
                    '@' . $target['login'] . ' wurde über das ModDesk bearbeitet.',
                    [
                        ['name' => 'Aktion', 'value' => $modAction, 'inline' => true],
                        ['name' => 'Ausgeführt von', 'value' => (string) auth()->user()['display_name'], 'inline' => true],
                        ['name' => 'Begründung', 'value' => $reason !== '' ? mb_substr($reason, 0, 1000) : 'Keine', 'inline' => false],
                    ],
                    in_array($modAction, ['ban', 'timeout'], true) ? 16733552 : 9525247,
                );
                flash('success', 'Twitch-Aktion „' . $modAction . '“ wurde für ' . $target['display_name'] . ' ausgeführt.');
            } catch (TwitchApiException $exception) {
                record_mod_action((string) $target['id'], $modAction, $duration, $reason ?: null, false, $exception->response, $caseId);
                throw $exception;
            }
            redirect('twitch');
        }

        if ($action === 'twitch-shield') {
            require_permission('twitch.use');
            $active = input('active') === '1';
            $response = twitch()->setShieldMode($active);
            record_mod_action(null, $active ? 'shield_on' : 'shield_off', null, null, true, $response);
            audit($active ? 'twitch.shield_on' : 'twitch.shield_off', 'twitch_channel', twitch()->channel()['id']);
            flash('success', 'Shield Mode wurde ' . ($active ? 'aktiviert.' : 'deaktiviert.'));
            redirect('twitch');
        }

        if ($action === 'twitch-clear-chat') {
            require_permission('twitch.use');
            $messageId = trim((string) input('message_id', '')) ?: null;
            $response = twitch()->clearChat($messageId);
            record_mod_action(null, $messageId ? 'delete_message' : 'clear_chat', null, $messageId, true, $response);
            audit($messageId ? 'twitch.message_deleted' : 'twitch.chat_cleared', 'twitch_channel', twitch()->channel()['id']);
            flash('success', $messageId ? 'Chatnachricht wurde gelöscht.' : 'Der Chat wurde geleert.');
            redirect('twitch');
        }

        if ($action === 'twitch-moderator-change') {
            require_permission('twitch.configure');
            $target = resolve_twitch_target((string) input('twitch_login', ''));
            $add = input('mode') === 'add';
            $response = twitch()->changeModerator((string) $target['id'], $add);
            record_mod_action((string) $target['id'], $add ? 'mod_add' : 'mod_remove', null, null, true, $response);
            audit($add ? 'twitch.mod_added' : 'twitch.mod_removed', 'twitch_user', $target['id']);
            flash('success', $target['display_name'] . ' wurde als Moderator ' . ($add ? 'hinzugefügt.' : 'entfernt.'));
            redirect('twitch');
        }

        if ($action === 'twitch-term-add') {
            require_permission('twitch.use');
            $term = trim((string) input('term', ''));
            if ($term === '') {
                throw new InvalidArgumentException('Der blockierte Begriff darf nicht leer sein.');
            }
            $response = twitch()->addBlockedTerm($term);
            record_mod_action(null, 'blocked_term_add', null, $term, true, $response);
            audit('twitch.blocked_term_added', 'twitch_channel', twitch()->channel()['id']);
            flash('success', 'Blockierter Begriff wurde hinzugefügt.');
            redirect('twitch', ['show' => 'terms']);
        }

        if ($action === 'twitch-term-remove') {
            require_permission('twitch.use');
            $termId = trim((string) input('term_id', ''));
            $response = twitch()->removeBlockedTerm($termId);
            record_mod_action(null, 'blocked_term_remove', null, $termId, true, $response);
            audit('twitch.blocked_term_removed', 'twitch_channel', twitch()->channel()['id']);
            flash('success', 'Blockierter Begriff wurde entfernt.');
            redirect('twitch', ['show' => 'terms']);
        }

        throw new RuntimeException('Unbekannte Aktion.');
    } catch (Throwable $exception) {
        $message = $exception instanceof PDOException && !Env::bool('APP_DEBUG', false)
            ? 'Die Daten konnten nicht gespeichert werden. Möglicherweise ist ein Wert bereits vergeben.'
            : $exception->getMessage();
        flash('danger', $message);
        redirect($returnPage);
    }
}

if ($page === 'login') {
    if (auth()->check()) {
        redirect('dashboard');
    }
    render('login', ['title' => 'Anmelden']);
    exit;
}

require_login();

$pageModule = modules()->moduleForPage($page);
if ($pageModule !== null && !modules()->isEnabled($pageModule)) {
    http_response_code(404);
    render('error', ['title' => 'Modul deaktiviert', 'message' => 'Dieses Panel-Modul ist derzeit deaktiviert.']);
    exit;
}

if ($page === 'dashboard' && auth()->can('updates.manage') && database_table_exists('github_release_status') && github_releases()->isConfigured()) {
    try {
        github_releases()->checkLatest(false);
    } catch (Throwable) {
        // Der Fehler wird im GitHub-Bereich protokolliert; das Dashboard bleibt erreichbar.
    }
}

if ($page === 'twitch-connect') {
    require_permission('twitch.configure');
    try {
        $state = bin2hex(random_bytes(24));
        $_SESSION['twitch_oauth_state'] = $state;
        $mode = ($_GET['mode'] ?? 'owner') === 'moderator' ? 'moderator' : 'owner';
        header('Location: ' . twitch()->oauthUrl($state, $mode));
        exit;
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
        redirect('twitch');
    }
}

if ($page === 'twitch-callback') {
    require_permission('twitch.configure');
    try {
        if (isset($_GET['error'])) {
            throw new RuntimeException('Twitch-Verbindung wurde abgebrochen: ' . (string) ($_GET['error_description'] ?? $_GET['error']));
        }
        $state = (string) ($_GET['state'] ?? '');
        $expected = (string) ($_SESSION['twitch_oauth_state'] ?? '');
        unset($_SESSION['twitch_oauth_state']);
        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            throw new RuntimeException('Ungültiger OAuth-Status. Bitte starte die Verbindung erneut.');
        }
        $code = (string) ($_GET['code'] ?? '');
        if ($code === '') {
            throw new RuntimeException('Twitch hat keinen Autorisierungscode geliefert.');
        }
        $connection = twitch()->exchangeAuthorizationCode($code, (int) auth()->user()['id']);
        audit('twitch.connected', 'twitch_user', $connection['twitch_user_id']);
        flash('success', 'Twitch-Konto ' . $connection['display_name'] . ' wurde sicher verbunden.');
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
    }
    redirect('twitch');
}

try {
    switch ($page) {
        case 'dashboard':
            $stats = [
                'ideas' => (int) db()->query("SELECT COUNT(*) FROM ideas WHERE archived_at IS NULL AND status <> 'done'")->fetchColumn(),
                'notes' => (int) db()->query('SELECT COUNT(*) FROM notes WHERE archived_at IS NULL')->fetchColumn(),
                'links' => (int) db()->query('SELECT COUNT(*) FROM shared_links WHERE archived_at IS NULL')->fetchColumn(),
                'cases' => (int) db()->query("SELECT COUNT(*) FROM moderation_cases WHERE status <> 'closed'")->fetchColumn(),
            ];
            $recentIdeas = db()->query(
                'SELECT i.*, u.display_name AS creator_name FROM ideas i JOIN users u ON u.id = i.created_by
                 WHERE i.archived_at IS NULL ORDER BY i.updated_at DESC LIMIT 5'
            )->fetchAll();
            $recentActions = db()->query(
                'SELECT ma.*, tu.display_name AS target_name, u.display_name AS performer_name
                 FROM moderation_actions ma
                 LEFT JOIN twitch_users tu ON tu.twitch_user_id = ma.twitch_user_id
                 JOIN users u ON u.id = ma.performed_by ORDER BY ma.created_at DESC LIMIT 8'
            )->fetchAll();
            $dashboardNews = [];
            if (modules()->isEnabled('news') && database_table_exists('news_posts')) {
                $dashboardNews = db()->query(
                    "SELECT np.*, u.display_name AS creator_name FROM news_posts np
                     JOIN users u ON u.id = np.created_by
                     WHERE np.status = 'published' AND (np.publish_at IS NULL OR np.publish_at <= UTC_TIMESTAMP())
                     ORDER BY np.pinned DESC, COALESCE(np.publish_at, np.updated_at) DESC LIMIT 3"
                )->fetchAll();
            }
            render('dashboard', compact('stats', 'recentIdeas', 'recentActions', 'dashboardNews') + ['title' => 'Dashboard']);
            break;

        case 'news':
            if (!database_table_exists('news_posts')) {
                render('error', [
                    'title' => 'Migration erforderlich',
                    'message' => 'Öffne Einstellungen → System und führe dort die ausstehenden Datenbankmigrationen aus.',
                ]);
                break;
            }
            $newsPosts = db()->query(
                'SELECT np.*, creator.display_name AS creator_name, updater.display_name AS updater_name
                 FROM news_posts np
                 JOIN users creator ON creator.id = np.created_by
                 LEFT JOIN users updater ON updater.id = np.updated_by
                 ORDER BY np.pinned DESC, FIELD(np.status, "published", "draft", "archived"),
                          COALESCE(np.publish_at, np.updated_at) DESC'
            )->fetchAll();
            $editNews = null;
            if ((int) ($_GET['edit'] ?? 0) > 0) {
                $statement = db()->prepare('SELECT * FROM news_posts WHERE id = :id');
                $statement->execute(['id' => (int) $_GET['edit']]);
                $editNews = $statement->fetch() ?: null;
            }
            render('news', compact('newsPosts', 'editNews') + ['title' => 'News & Ankündigungen']);
            break;

        case 'ideas':
            $ideas = db()->query(
                'SELECT i.*, creator.display_name AS creator_name, assignee.display_name AS assignee_name
                 FROM ideas i JOIN users creator ON creator.id = i.created_by
                 LEFT JOIN users assignee ON assignee.id = i.assigned_to
                 WHERE i.archived_at IS NULL ORDER BY FIELD(i.priority, "urgent", "high", "normal", "low"), i.updated_at DESC'
            )->fetchAll();
            $team = db()->query('SELECT id, display_name FROM users WHERE active = 1 ORDER BY display_name')->fetchAll();
            $editIdea = null;
            if ((int) ($_GET['edit'] ?? 0) > 0) {
                $statement = db()->prepare('SELECT * FROM ideas WHERE id = :id AND archived_at IS NULL');
                $statement->execute(['id' => (int) $_GET['edit']]);
                $editIdea = $statement->fetch() ?: null;
            }
            render('ideas', compact('ideas', 'team', 'editIdea') + ['title' => 'Ideen']);
            break;

        case 'notes':
            $visibilitySql = in_array(auth()->user()['role'], ['owner', 'admin'], true) ? '1=1' : "n.visibility = 'team'";
            $notes = db()->query(
                'SELECT n.*, u.display_name AS creator_name, tu.display_name AS twitch_name, i.title AS idea_title
                 FROM notes n JOIN users u ON u.id = n.created_by
                 LEFT JOIN twitch_users tu ON tu.twitch_user_id = n.twitch_user_id
                 LEFT JOIN ideas i ON i.id = n.idea_id
                 WHERE n.archived_at IS NULL AND ' . $visibilitySql . '
                 ORDER BY n.pinned DESC, n.updated_at DESC'
            )->fetchAll();
            $twitchUsers = db()->query('SELECT twitch_user_id, display_name, login FROM twitch_users ORDER BY display_name LIMIT 500')->fetchAll();
            $ideasForNotes = db()->query('SELECT id, title FROM ideas WHERE archived_at IS NULL ORDER BY title')->fetchAll();
            $editNote = null;
            if ((int) ($_GET['edit'] ?? 0) > 0) {
                $statement = db()->prepare('SELECT * FROM notes n WHERE id = :id AND archived_at IS NULL AND ' . $visibilitySql);
                $statement->execute(['id' => (int) $_GET['edit']]);
                $editNote = $statement->fetch() ?: null;
            } elseif (!empty($_GET['twitch_user_id'])) {
                $statement = db()->prepare('SELECT twitch_user_id FROM twitch_users WHERE twitch_user_id = :id');
                $statement->execute(['id' => (string) $_GET['twitch_user_id']]);
                if ($statement->fetchColumn()) {
                    $editNote = ['twitch_user_id' => (string) $_GET['twitch_user_id']];
                }
            }
            render('notes', compact('notes', 'twitchUsers', 'ideasForNotes', 'editNote') + ['title' => 'Notizen']);
            break;

        case 'links':
            $links = db()->query(
                'SELECT l.*, u.display_name AS creator_name FROM shared_links l JOIN users u ON u.id = l.created_by
                 WHERE l.archived_at IS NULL ORDER BY l.category, l.updated_at DESC'
            )->fetchAll();
            $editLink = null;
            if ((int) ($_GET['edit'] ?? 0) > 0) {
                $statement = db()->prepare('SELECT * FROM shared_links WHERE id = :id AND archived_at IS NULL');
                $statement->execute(['id' => (int) $_GET['edit']]);
                $editLink = $statement->fetch() ?: null;
            }
            render('links', compact('links', 'editLink') + ['title' => 'Geteilte Links']);
            break;

        case 'twitch':
            $connection = twitch()->connection();
            $channel = twitch()->channel();
            $moderators = twitch()->cachedRoles('moderator');
            $bannedUsers = twitch()->cachedRoles('banned');
            $shield = null;
            $terms = [];
            $liveError = null;
            if ($connection !== null && $channel !== null) {
                try {
                    $shield = twitch()->getShieldMode();
                    if (($_GET['show'] ?? '') === 'terms') {
                        $terms = twitch()->blockedTerms();
                    }
                } catch (Throwable $exception) {
                    $liveError = $exception->getMessage();
                }
            }
            $openCases = db()->query(
                "SELECT mc.id, mc.title, tu.display_name FROM moderation_cases mc
                 JOIN twitch_users tu ON tu.twitch_user_id = mc.twitch_user_id
                 WHERE mc.status <> 'closed' ORDER BY mc.updated_at DESC"
            )->fetchAll();
            render('twitch', compact('connection', 'channel', 'moderators', 'bannedUsers', 'shield', 'terms', 'liveError', 'openCases') + ['title' => 'Twitch-Zentrale']);
            break;

        case 'ban-sync':
            $connection = twitch()->connection();
            $banSyncChannels = db()->query(
                'SELECT bsc.*, tu.login, tu.display_name, tu.profile_image_url,
                        creator.display_name AS creator_name
                 FROM ban_sync_channels bsc
                 JOIN twitch_users tu ON tu.twitch_user_id = bsc.twitch_user_id
                 JOIN users creator ON creator.id = bsc.created_by
                 ORDER BY bsc.enabled DESC, tu.display_name'
            )->fetchAll();
            $banSyncJobs = db()->query(
                'SELECT bsj.*, target.login AS target_login, target.display_name AS target_name,
                        requester.display_name AS requester_name
                 FROM ban_sync_jobs bsj
                 JOIN twitch_users target ON target.twitch_user_id = bsj.target_twitch_user_id
                 JOIN users requester ON requester.id = bsj.requested_by
                 ORDER BY bsj.created_at DESC LIMIT 50'
            )->fetchAll();
            $banSyncStats = [
                'channels' => (int) db()->query('SELECT COUNT(*) FROM ban_sync_channels WHERE enabled = 1')->fetchColumn(),
                'jobs' => (int) db()->query('SELECT COUNT(*) FROM ban_sync_jobs')->fetchColumn(),
                'successes' => (int) db()->query('SELECT COALESCE(SUM(success_count), 0) FROM ban_sync_jobs')->fetchColumn(),
                'partial' => (int) db()->query("SELECT COUNT(*) FROM ban_sync_jobs WHERE status = 'partial'")->fetchColumn(),
            ];
            $selectedBanSyncJob = null;
            $selectedBanSyncResults = [];
            $selectedJobId = (int) ($_GET['job'] ?? 0);
            if ($selectedJobId > 0) {
                $statement = db()->prepare(
                    'SELECT bsj.*, target.login AS target_login, target.display_name AS target_name,
                            requester.display_name AS requester_name
                     FROM ban_sync_jobs bsj
                     JOIN twitch_users target ON target.twitch_user_id = bsj.target_twitch_user_id
                     JOIN users requester ON requester.id = bsj.requested_by
                     WHERE bsj.id = :id'
                );
                $statement->execute(['id' => $selectedJobId]);
                $selectedBanSyncJob = $statement->fetch() ?: null;
                if ($selectedBanSyncJob !== null) {
                    $statement = db()->prepare(
                        'SELECT bsr.*, channel.login AS channel_login, channel.display_name AS channel_name,
                                channel.profile_image_url AS channel_image
                         FROM ban_sync_results bsr
                         JOIN twitch_users channel ON channel.twitch_user_id = bsr.channel_twitch_user_id
                         WHERE bsr.job_id = :job_id ORDER BY channel.display_name'
                    );
                    $statement->execute(['job_id' => $selectedJobId]);
                    $selectedBanSyncResults = $statement->fetchAll();
                }
            }
            render('ban-sync', compact(
                'connection',
                'banSyncChannels',
                'banSyncJobs',
                'banSyncStats',
                'selectedBanSyncJob',
                'selectedBanSyncResults',
            ) + ['title' => 'BanSync']);
            break;

        case 'twitch-users':
            $notesCountFilter = in_array(auth()->user()['role'], ['owner', 'admin'], true) ? '' : " AND n.visibility = 'team'";
            $users = db()->query(
                'SELECT tu.*,
                    (SELECT COUNT(*) FROM notes n WHERE n.twitch_user_id = tu.twitch_user_id AND n.archived_at IS NULL' . $notesCountFilter . ') AS notes_count,
                    (SELECT COUNT(*) FROM moderation_actions ma WHERE ma.twitch_user_id = tu.twitch_user_id) AS actions_count
                 FROM twitch_users tu ORDER BY tu.cached_at DESC LIMIT 500'
            )->fetchAll();
            $selectedUser = null;
            $selectedNotes = [];
            $selectedActions = [];
            if (!empty($_GET['id'])) {
                $statement = db()->prepare('SELECT * FROM twitch_users WHERE twitch_user_id = :id');
                $statement->execute(['id' => (string) $_GET['id']]);
                $selectedUser = $statement->fetch() ?: null;
                if ($selectedUser) {
                    $noteVisibilitySql = in_array(auth()->user()['role'], ['owner', 'admin'], true) ? '1=1' : "n.visibility = 'team'";
                    $statement = db()->prepare(
                        'SELECT n.*, u.display_name AS creator_name FROM notes n JOIN users u ON u.id = n.created_by
                         WHERE n.twitch_user_id = :id AND n.archived_at IS NULL AND ' . $noteVisibilitySql . ' ORDER BY n.created_at DESC'
                    );
                    $statement->execute(['id' => $selectedUser['twitch_user_id']]);
                    $selectedNotes = $statement->fetchAll();
                    $statement = db()->prepare(
                        'SELECT ma.*, u.display_name AS performer_name FROM moderation_actions ma
                         JOIN users u ON u.id = ma.performed_by WHERE ma.twitch_user_id = :id ORDER BY ma.created_at DESC LIMIT 100'
                    );
                    $statement->execute(['id' => $selectedUser['twitch_user_id']]);
                    $selectedActions = $statement->fetchAll();
                }
            }
            render('twitch-users', compact('users', 'selectedUser', 'selectedNotes', 'selectedActions') + ['title' => 'Twitch-Nutzer']);
            break;

        case 'cases':
            $cases = db()->query(
                'SELECT mc.*, tu.login, tu.display_name AS twitch_name, creator.display_name AS creator_name,
                        assignee.display_name AS assignee_name,
                        (SELECT COUNT(*) FROM moderation_actions ma WHERE ma.case_id = mc.id) AS actions_count
                 FROM moderation_cases mc JOIN twitch_users tu ON tu.twitch_user_id = mc.twitch_user_id
                 JOIN users creator ON creator.id = mc.created_by
                 LEFT JOIN users assignee ON assignee.id = mc.assigned_to
                 ORDER BY FIELD(mc.status, "open", "monitoring", "closed"), FIELD(mc.severity, "critical", "high", "normal", "low"), mc.updated_at DESC'
            )->fetchAll();
            $team = db()->query("SELECT id, display_name FROM users WHERE active = 1 AND role IN ('owner', 'admin', 'moderator') ORDER BY display_name")->fetchAll();
            $editCase = null;
            if ((int) ($_GET['edit'] ?? 0) > 0) {
                $statement = db()->prepare(
                    'SELECT mc.*, tu.login FROM moderation_cases mc JOIN twitch_users tu ON tu.twitch_user_id = mc.twitch_user_id WHERE mc.id = :id'
                );
                $statement->execute(['id' => (int) $_GET['edit']]);
                $editCase = $statement->fetch() ?: null;
            }
            render('cases', compact('cases', 'team', 'editCase') + ['title' => 'Moderationsfälle']);
            break;

        case 'discord-studio':
            require_permission('discord.studio');
            if (!database_table_exists('discord_message_templates')) {
                render('error', [
                    'title' => 'Migration erforderlich',
                    'message' => 'Öffne Einstellungen → System und führe dort die ausstehenden Datenbankmigrationen aus.',
                ]);
                break;
            }
            $templates = db()->query(
                'SELECT dmt.id, dmt.name, dmt.updated_at, u.display_name AS updated_by_name
                 FROM discord_message_templates dmt
                 LEFT JOIN users u ON u.id = dmt.updated_by
                 ORDER BY dmt.updated_at DESC, dmt.name'
            )->fetchAll();
            $selectedTemplate = [
                'id' => 0,
                'name' => '',
                'message_content' => '',
                'embed_title' => '',
                'embed_description' => '',
                'embed_url' => '',
                'embed_color' => 9525247,
                'embed_color_hex' => '#9147FF',
                'author_name' => '',
                'author_url' => '',
                'author_icon_url' => '',
                'thumbnail_url' => '',
                'image_url' => '',
                'footer_text' => 'Twitch ModDesk',
                'footer_icon_url' => '',
                'include_timestamp' => 1,
                'fields' => [],
            ];
            $selectedTemplateId = (int) ($_GET['template'] ?? 0);
            if ($selectedTemplateId > 0) {
                $statement = db()->prepare('SELECT * FROM discord_message_templates WHERE id = :id');
                $statement->execute(['id' => $selectedTemplateId]);
                $loadedTemplate = $statement->fetch();
                if ($loadedTemplate) {
                    try {
                        $loadedFields = json_decode((string) ($loadedTemplate['fields_json'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        $loadedFields = [];
                    }
                    $selectedTemplate = array_merge($selectedTemplate, $loadedTemplate, [
                        'embed_color_hex' => sprintf('#%06X', max(0, min(16777215, (int) $loadedTemplate['embed_color']))),
                        'fields' => is_array($loadedFields) ? $loadedFields : [],
                    ]);
                }
            }
            if (database_table_exists('discord_channel_routes')) {
                $studioRoutes = db()->query(
                    'SELECT dcr.event_key, ds.guild_id, dc.channel_id, ds.name AS server_name, dc.name AS channel_name
                     FROM discord_channel_routes dcr
                     JOIN discord_channels dc ON dc.id = dcr.channel_id
                     JOIN discord_servers ds ON ds.id = dc.server_id
                     WHERE dcr.enabled = 1 AND dc.enabled = 1 AND ds.enabled = 1
                     ORDER BY dcr.event_key, ds.name, dc.name'
                )->fetchAll();
            } else {
                $studioRoutes = db()->query(
                    'SELECT event_key, guild_id, channel_id, guild_id AS server_name, channel_id AS channel_name FROM discord_notification_routes
                     WHERE channel_id IS NOT NULL AND channel_id <> \'\' ORDER BY event_key'
                )->fetchAll();
            }
            $studioEvents = discord_event_definitions();
            $manualDeliveries = db()->query(
                "SELECT * FROM integration_deliveries
                 WHERE provider = 'discord' AND event_key = 'manual_message'
                 ORDER BY created_at DESC LIMIT 25"
            )->fetchAll();
            $discordConfigured = discord()->isConfigured();
            render('discord-studio', compact(
                'templates',
                'selectedTemplate',
                'studioRoutes',
                'studioEvents',
                'manualDeliveries',
                'discordConfigured',
            ) + ['title' => 'Discord Studio']);
            break;

        case 'design':
            require_permission('design.manage');
            $menuConfig = setting_json('menu_config_json');
            $menuEditor = [];
            foreach (navigation_definitions() as $menuKey => $definition) {
                $custom = isset($menuConfig[$menuKey]) && is_array($menuConfig[$menuKey]) ? $menuConfig[$menuKey] : [];
                $menuEditor[$menuKey] = [
                    'label' => (string) ($custom['label'] ?? $definition['label']),
                    'icon' => (string) ($custom['icon'] ?? $definition['icon']),
                    'order' => (int) ($custom['order'] ?? $definition['order']),
                    'enabled' => !array_key_exists('enabled', $custom) || filter_var($custom['enabled'], FILTER_VALIDATE_BOOLEAN),
                ];
            }
            $pageConfig = setting_json('page_content_json');
            $pageEditor = [];
            foreach (editable_page_definitions() as $pageKey => $defaultTitle) {
                $custom = isset($pageConfig[$pageKey]) && is_array($pageConfig[$pageKey]) ? $pageConfig[$pageKey] : [];
                $pageEditor[$pageKey] = [
                    'default_title' => $defaultTitle,
                    'title' => (string) ($custom['title'] ?? ''),
                    'top_text' => (string) ($custom['top_text'] ?? ''),
                ];
            }
            $designSettings = [
                'app_name' => (string) settings()->get('app_name', env('APP_NAME', 'Twitch ModDesk')),
                'header_eyebrow' => (string) settings()->get('header_eyebrow', 'CONTROL CENTER'),
                'header_version_text' => (string) settings()->get('header_version_text', 'Version {version}'),
                'footer_text' => (string) settings()->get('footer_text', ''),
                'theme' => theme_values(),
                'logo_set' => branding()->logoMetadata() !== null,
            ];
            render('design', compact('designSettings', 'menuEditor', 'pageEditor') + ['title' => 'Design-Editor']);
            break;

        case 'modules':
            require_permission('modules.manage');
            $moduleRows = modules()->all();
            $selectedModule = null;
            $moduleConfiguration = [];
            $selectedModuleKey = trim((string) ($_GET['edit'] ?? ''));
            if ($selectedModuleKey !== '') {
                $selectedModule = modules()->get($selectedModuleKey);
                if ($selectedModule !== null) {
                    $moduleConfiguration = modules()->configurationValues($selectedModule);
                }
            }
            $moduleSystemReady = modules()->ready();
            $zipAvailable = class_exists(ZipArchive::class);
            render('modules', compact(
                'moduleRows',
                'selectedModule',
                'moduleConfiguration',
                'moduleSystemReady',
                'zipAvailable',
            ) + ['title' => 'Module']);
            break;

        case 'team':
            require_permission('team.manage');
            $members = db()->query('SELECT id, username, display_name, email, role, active, last_login_at, created_at FROM users ORDER BY active DESC, display_name')->fetchAll();
            $editMember = null;
            if ((int) ($_GET['edit'] ?? 0) > 0) {
                $statement = db()->prepare('SELECT id, username, display_name, email, role, active FROM users WHERE id = :id');
                $statement->execute(['id' => (int) $_GET['edit']]);
                $editMember = $statement->fetch() ?: null;
            }
            render('team', compact('members', 'editMember') + ['title' => 'Team & Rechte']);
            break;

        case 'settings':
            require_permission('settings.manage');
            $integrationSettings = [
                'app_name' => (string) settings()->get('app_name', env('APP_NAME', 'Twitch ModDesk')),
                'app_url' => (string) settings()->get('app_url', env('APP_URL', '')),
                'url_rewrite_enabled' => settings()->bool('url_rewrite_enabled', false),
                'twitch_client_id' => (string) settings()->get('twitch_client_id', env('TWITCH_CLIENT_ID', '')),
                'twitch_redirect_uri' => (string) settings()->get('twitch_redirect_uri', env('TWITCH_REDIRECT_URI', '')),
                'twitch_secret_set' => settings()->hasValue('twitch_client_secret') || trim((string) env('TWITCH_CLIENT_SECRET', '')) !== '',
                'discord_enabled' => settings()->bool('discord_enabled', false),
                'discord_application_id' => (string) settings()->get('discord_application_id', ''),
                'discord_token_set' => settings()->hasValue('discord_bot_token'),
                'smtp_enabled' => settings()->bool('smtp_enabled', false),
                'smtp_host' => (string) settings()->get('smtp_host', ''),
                'smtp_port' => (string) settings()->get('smtp_port', '587'),
                'smtp_encryption' => (string) settings()->get('smtp_encryption', 'tls'),
                'smtp_auth' => (string) settings()->get('smtp_auth', 'login'),
                'smtp_username' => (string) settings()->get('smtp_username', ''),
                'smtp_password_set' => settings()->hasValue('smtp_password'),
                'smtp_from_email' => (string) settings()->get('smtp_from_email', ''),
                'smtp_from_name' => (string) settings()->get('smtp_from_name', 'Twitch ModDesk'),
            ];
            $discordRoutes = [];
            foreach (db()->query('SELECT * FROM discord_notification_routes ORDER BY id')->fetchAll() as $route) {
                $discordRoutes[(string) $route['event_key']] = $route;
            }
            $discordEvents = discord_event_definitions();
            $discordManagedReady = database_table_exists('discord_servers') && database_table_exists('discord_channels');
            $discordServers = [];
            $discordChannels = [];
            $discordChannelRoutes = [];
            if ($discordManagedReady) {
                $discordServers = db()->query('SELECT * FROM discord_servers ORDER BY name')->fetchAll();
                $discordChannels = db()->query(
                    'SELECT dc.*, ds.name AS server_name, ds.guild_id
                     FROM discord_channels dc JOIN discord_servers ds ON ds.id = dc.server_id
                     ORDER BY ds.name, dc.name'
                )->fetchAll();
                foreach (db()->query('SELECT channel_id, event_key FROM discord_channel_routes WHERE enabled = 1')->fetchAll() as $route) {
                    $discordChannelRoutes[(int) $route['channel_id']][] = (string) $route['event_key'];
                }
            }
            $integrationDeliveries = db()->query(
                'SELECT * FROM integration_deliveries ORDER BY created_at DESC LIMIT 100'
            )->fetchAll();
            $version = app_version();
            $updatesReady = database_table_exists('system_updates');
            $migrationStatus = SchemaMigrator::status(db(), base_path('database/migrations'));
            $systemUpdates = [];
            if (auth()->can('updates.manage') && $updatesReady) {
                $systemUpdates = db()->query(
                    'SELECT su.*, u.display_name AS applied_by_name
                     FROM system_updates su JOIN users u ON u.id = su.applied_by
                     ORDER BY su.created_at DESC LIMIT 20'
                )->fetchAll();
            }
            $zipAvailable = class_exists(ZipArchive::class);
            $githubSettings = [
                'enabled' => settings()->bool('github_updates_enabled', false),
                'repository' => (string) settings()->get('github_repository', ''),
                'asset_name' => (string) settings()->get('github_asset_name', 'twitch-moddesk.zip'),
                'interval' => (int) settings()->get('github_check_interval_hours', '6'),
                'token_set' => settings()->hasValue('github_token'),
            ];
            $githubReady = database_table_exists('github_release_status');
            $githubStatus = $githubReady ? github_update_status() : null;
            render('settings', compact(
                'integrationSettings',
                'discordRoutes',
                'discordEvents',
                'discordManagedReady',
                'discordServers',
                'discordChannels',
                'discordChannelRoutes',
                'integrationDeliveries',
                'version',
                'systemUpdates',
                'zipAvailable',
                'updatesReady',
                'migrationStatus',
                'githubSettings',
                'githubReady',
                'githubStatus',
            ) + ['title' => 'Einstellungen']);
            break;

        case 'audit':
            require_permission('audit.view');
            $logs = db()->query(
                'SELECT al.*, u.display_name AS user_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
                 ORDER BY al.created_at DESC LIMIT 250'
            )->fetchAll();
            render('audit', compact('logs') + ['title' => 'Audit-Protokoll']);
            break;

        default:
            $customModuleKey = modules()->moduleForPage($page);
            if ($customModuleKey !== null) {
                $customModule = modules()->get($customModuleKey);
                $customPageFile = modules()->customPageFile($customModuleKey);
                if ($customModule !== null && $customPageFile !== null) {
                    render_custom_module($customModule, $customPageFile);
                    break;
                }
            }
            http_response_code(404);
            render('error', ['title' => 'Nicht gefunden', 'message' => 'Diese Seite gibt es nicht.']);
    }
} catch (Throwable $exception) {
    http_response_code(500);
    $message = Env::bool('APP_DEBUG', false) ? $exception->getMessage() : 'Die Seite konnte nicht geladen werden. Prüfe Datenbank und Konfiguration.';
    render('error', ['title' => 'Fehler', 'message' => $message]);
}
