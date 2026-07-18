SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'viewer',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_active (role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    payload MEDIUMBLOB NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    last_activity INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sessions_last_activity (last_activity),
    INDEX idx_sessions_user (user_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_lookup (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_name VARCHAR(190) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value MEDIUMTEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ideas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description MEDIUMTEXT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'new',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    due_date DATE NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ideas_status_priority (status, priority),
    INDEX idx_ideas_archived (archived_at),
    CONSTRAINT fk_ideas_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_ideas_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS twitch_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    twitch_user_id VARCHAR(40) NOT NULL UNIQUE,
    login VARCHAR(80) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    profile_image_url VARCHAR(1000) NULL,
    description TEXT NULL,
    broadcaster_type VARCHAR(30) NULL,
    account_created_at DATETIME NULL,
    cached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_twitch_users_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS twitch_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    twitch_user_id VARCHAR(40) NOT NULL UNIQUE,
    login VARCHAR(80) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    profile_image_url VARCHAR(1000) NULL,
    access_token MEDIUMTEXT NOT NULL,
    refresh_token MEDIUMTEXT NOT NULL,
    scopes JSON NOT NULL,
    expires_at DATETIME NOT NULL,
    last_validated_at DATETIME NULL,
    connected_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_twitch_connection_user FOREIGN KEY (connected_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS twitch_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_id VARCHAR(40) NOT NULL,
    twitch_user_id VARCHAR(40) NOT NULL,
    role VARCHAR(30) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    metadata JSON NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_twitch_role (channel_id, twitch_user_id, role),
    INDEX idx_twitch_roles_channel_active (channel_id, role, active),
    CONSTRAINT fk_twitch_roles_user FOREIGN KEY (twitch_user_id) REFERENCES twitch_users(twitch_user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    tags VARCHAR(500) NULL,
    visibility VARCHAR(20) NOT NULL DEFAULT 'team',
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    twitch_user_id VARCHAR(40) NULL,
    idea_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notes_pinned_created (pinned, created_at),
    INDEX idx_notes_archived (archived_at),
    CONSTRAINT fk_notes_twitch_user FOREIGN KEY (twitch_user_id) REFERENCES twitch_users(twitch_user_id) ON DELETE SET NULL,
    CONSTRAINT fk_notes_idea FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE SET NULL,
    CONSTRAINT fk_notes_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_notes_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shared_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    description TEXT NULL,
    category VARCHAR(80) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_links_category (category),
    INDEX idx_links_archived (archived_at),
    CONSTRAINT fk_links_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moderation_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    twitch_user_id VARCHAR(40) NOT NULL,
    title VARCHAR(180) NOT NULL,
    summary MEDIUMTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    severity VARCHAR(20) NOT NULL DEFAULT 'normal',
    created_by BIGINT UNSIGNED NOT NULL,
    assigned_to BIGINT UNSIGNED NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cases_status_severity (status, severity),
    CONSTRAINT fk_cases_twitch_user FOREIGN KEY (twitch_user_id) REFERENCES twitch_users(twitch_user_id),
    CONSTRAINT fk_cases_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_cases_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS moderation_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id BIGINT UNSIGNED NULL,
    twitch_user_id VARCHAR(40) NULL,
    action VARCHAR(50) NOT NULL,
    duration_seconds INT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    success TINYINT(1) NOT NULL DEFAULT 1,
    api_response JSON NULL,
    performed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mod_actions_created (created_at),
    INDEX idx_mod_actions_target (twitch_user_id),
    CONSTRAINT fk_actions_case FOREIGN KEY (case_id) REFERENCES moderation_cases(id) ON DELETE SET NULL,
    CONSTRAINT fk_actions_twitch_user FOREIGN KEY (twitch_user_id) REFERENCES twitch_users(twitch_user_id) ON DELETE SET NULL,
    CONSTRAINT fk_actions_performer FOREIGN KEY (performed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ban_sync_channels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    twitch_user_id VARCHAR(40) NOT NULL UNIQUE,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    validation_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
    validated_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ban_sync_channels_enabled (enabled, validation_status),
    CONSTRAINT fk_ban_sync_channel_twitch_user FOREIGN KEY (twitch_user_id) REFERENCES twitch_users(twitch_user_id) ON DELETE CASCADE,
    CONSTRAINT fk_ban_sync_channel_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ban_sync_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    target_twitch_user_id VARCHAR(40) NOT NULL,
    action VARCHAR(10) NOT NULL,
    reason VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    channel_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    success_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    requested_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_ban_sync_jobs_created (created_at),
    INDEX idx_ban_sync_jobs_target (target_twitch_user_id, created_at),
    CONSTRAINT fk_ban_sync_job_target FOREIGN KEY (target_twitch_user_id) REFERENCES twitch_users(twitch_user_id),
    CONSTRAINT fk_ban_sync_job_requester FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ban_sync_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED NOT NULL,
    channel_twitch_user_id VARCHAR(40) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    http_status SMALLINT UNSIGNED NULL,
    error_message VARCHAR(1000) NULL,
    api_response JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ban_sync_result_channel (job_id, channel_twitch_user_id),
    INDEX idx_ban_sync_results_success (success, created_at),
    CONSTRAINT fk_ban_sync_result_job FOREIGN KEY (job_id) REFERENCES ban_sync_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_ban_sync_result_channel FOREIGN KEY (channel_twitch_user_id) REFERENCES twitch_users(twitch_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_notification_routes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(80) NOT NULL UNIQUE,
    guild_id VARCHAR(40) NULL,
    channel_id VARCHAR(40) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_discord_routes_enabled (enabled, event_key),
    CONSTRAINT fk_discord_route_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO discord_notification_routes (event_key) VALUES
    ('ban_sync'),
    ('moderation_action'),
    ('idea_created'),
    ('case_created');

CREATE TABLE IF NOT EXISTS integration_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(20) NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    destination VARCHAR(190) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    response_status SMALLINT UNSIGNED NULL,
    error_message VARCHAR(1000) NULL,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_integration_deliveries_created (created_at),
    INDEX idx_integration_deliveries_provider (provider, success, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branding_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_key VARCHAR(80) NOT NULL UNIQUE,
    mime_type VARCHAR(80) NOT NULL,
    file_data MEDIUMBLOB NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    width SMALLINT UNSIGNED NULL,
    height SMALLINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_branding_asset_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_message_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    message_content VARCHAR(2000) NULL,
    embed_title VARCHAR(256) NULL,
    embed_description TEXT NULL,
    embed_url VARCHAR(1000) NULL,
    embed_color INT UNSIGNED NOT NULL DEFAULT 9525247,
    author_name VARCHAR(256) NULL,
    author_url VARCHAR(1000) NULL,
    author_icon_url VARCHAR(1000) NULL,
    thumbnail_url VARCHAR(1000) NULL,
    image_url VARCHAR(1000) NULL,
    footer_text VARCHAR(2048) NULL,
    footer_icon_url VARCHAR(1000) NULL,
    include_timestamp TINYINT(1) NOT NULL DEFAULT 1,
    fields_json JSON NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_discord_templates_updated (updated_at),
    CONSTRAINT fk_discord_template_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_discord_template_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_updates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_version VARCHAR(40) NOT NULL,
    to_version VARCHAR(40) NOT NULL,
    package_name VARCHAR(190) NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    backup_path VARCHAR(500) NULL,
    error_message VARCHAR(2000) NULL,
    details JSON NULL,
    applied_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_system_updates_created (created_at),
    INDEX idx_system_updates_status (status, created_at),
    CONSTRAINT fk_system_update_user FOREIGN KEY (applied_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
    source VARCHAR(20) NOT NULL DEFAULT 'builtin',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    protected TINYINT(1) NOT NULL DEFAULT 0,
    directory_name VARCHAR(100) NULL,
    manifest JSON NULL,
    installed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_modules_enabled (enabled, source),
    CONSTRAINT fk_modules_installer FOREIGN KEY (installed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO modules (module_key, name, description, version, source, enabled, protected) VALUES
    ('news', 'News & Ankündigungen', 'Interne oder veröffentlichte Neuigkeiten für das ModDesk-Team.', '1.0.0', 'builtin', 1, 0),
    ('ideas', 'Ideen', 'Ideen-Board und Planung.', '1.0.0', 'builtin', 1, 0),
    ('notes', 'Notizen', 'Teamnotizen und Wissensablage.', '1.0.0', 'builtin', 1, 0),
    ('links', 'Links', 'Geteilte Links und Ressourcen.', '1.0.0', 'builtin', 1, 0),
    ('twitch', 'Twitch', 'Twitch OAuth, Nutzer und Moderationswerkzeuge.', '1.0.0', 'builtin', 1, 0),
    ('ban-sync', 'BanSync', 'Kanalübergreifende Bans und Banlog.', '1.0.0', 'builtin', 1, 0),
    ('cases', 'Moderationsfälle', 'Interne Moderationsfälle und Aktionsverlauf.', '1.0.0', 'builtin', 1, 0),
    ('discord', 'Discord', 'Discord Studio, Bot und Benachrichtigungen.', '1.0.0', 'builtin', 1, 0),
    ('team', 'Team & Rechte', 'Lokale Zugänge und Rollenverwaltung.', '1.0.0', 'builtin', 1, 0),
    ('design', 'Design-Editor', 'Branding, Navigation und Seiteninhalte.', '1.0.0', 'builtin', 1, 0),
    ('audit', 'Audit-Protokoll', 'Nachvollziehbares System- und Aktionsprotokoll.', '1.0.0', 'builtin', 1, 0);

CREATE TABLE IF NOT EXISTS module_migrations (
    module_key VARCHAR(80) NOT NULL,
    migration_name VARCHAR(190) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (module_key, migration_name),
    CONSTRAINT fk_module_migrations_module FOREIGN KEY (module_key) REFERENCES modules(module_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    publish_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_news_status_publish (status, publish_at),
    INDEX idx_news_pinned_updated (pinned, updated_at),
    CONSTRAINT fk_news_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_news_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_servers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guild_id VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_discord_servers_enabled (enabled, name),
    CONSTRAINT fk_discord_server_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_discord_server_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_channels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    server_id BIGINT UNSIGNED NOT NULL,
    channel_id VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_discord_channels_server (server_id, enabled, name),
    CONSTRAINT fk_discord_channel_server FOREIGN KEY (server_id) REFERENCES discord_servers(id) ON DELETE CASCADE,
    CONSTRAINT fk_discord_channel_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_discord_channel_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_channel_routes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(80) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_discord_channel_event (channel_id, event_key),
    INDEX idx_discord_channel_routes_event (event_key, enabled),
    CONSTRAINT fk_discord_route_channel FOREIGN KEY (channel_id) REFERENCES discord_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO discord_notification_routes (event_key) VALUES ('news_published'), ('changelog');

CREATE TABLE IF NOT EXISTS github_release_status (
    id TINYINT UNSIGNED PRIMARY KEY,
    repository VARCHAR(190) NOT NULL,
    release_id BIGINT UNSIGNED NULL,
    tag_name VARCHAR(120) NULL,
    version VARCHAR(40) NULL,
    release_name VARCHAR(190) NULL,
    release_body MEDIUMTEXT NULL,
    html_url VARCHAR(1000) NULL,
    asset_id BIGINT UNSIGNED NULL,
    asset_name VARCHAR(190) NULL,
    asset_api_url VARCHAR(1000) NULL,
    browser_download_url VARCHAR(1000) NULL,
    asset_size BIGINT UNSIGNED NULL,
    asset_digest VARCHAR(120) NULL,
    published_at DATETIME NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    error_message VARCHAR(1000) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id VARCHAR(80) NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
