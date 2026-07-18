<?php

declare(strict_types=1);

final class Auth
{
    private ?array $user = null;
    private bool $resolved = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $id = (int) ($_SESSION['auth_user_id'] ?? 0);
        if ($id < 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, username, display_name, email, role, active, last_login_at, created_at
             FROM users WHERE id = :id AND active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $this->user = $statement->fetch() ?: null;

        if ($this->user === null) {
            unset($_SESSION['auth_user_id']);
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function attempt(string $username, string $password): bool
    {
        $username = strtolower(trim($username));
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

        $rate = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = :username AND ip_address = :ip AND successful = 0
             AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)'
        );
        $rate->execute(['username' => $username, 'ip' => $ip]);
        if ((int) $rate->fetchColumn() >= 5) {
            return false;
        }

        $statement = $this->pdo->prepare('SELECT * FROM users WHERE username = :username AND active = 1 LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
        $successful = is_array($user) && password_verify($password, (string) $user['password_hash']);

        $log = $this->pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address, successful) VALUES (:username, :ip, :successful)'
        );
        $log->execute(['username' => $username, 'ip' => $ip, 'successful' => $successful ? 1 : 0]);

        if (!$successful) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['auth_user_id'] = (int) $user['id'];
        $this->user = $user;
        $this->resolved = true;
        $this->pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $user['id']]);
        $this->pdo->prepare('DELETE FROM login_attempts WHERE username = :username AND ip_address = :ip AND successful = 0')
            ->execute(['username' => $username, 'ip' => $ip]);
        return true;
    }

    public function logout(): void
    {
        $this->user = null;
        $this->resolved = true;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function can(string $permission): bool
    {
        $role = (string) ($this->user()['role'] ?? 'guest');
        $permissions = [
            'owner' => ['*'],
            'admin' => [
                'content.write',
                'content.archive',
                'team.manage',
                'twitch.use',
                'twitch.configure',
                'settings.manage',
                'design.manage',
                'discord.studio',
                'audit.view',
            ],
            'moderator' => ['content.write', 'twitch.use'],
            'viewer' => [],
        ];

        return in_array('*', $permissions[$role] ?? [], true)
            || in_array($permission, $permissions[$role] ?? [], true);
    }
}
