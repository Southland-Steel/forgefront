<?php
if (session_status() === PHP_SESSION_NONE) session_start();

class Auth {
    const REMEMBER_COOKIE  = 'ff_remember';
    const REMEMBER_TTL     = 259200;  // 3 days
    const REFRESH_INTERVAL = 3600;   // roll window forward at most once per hour

    private static function getDB(): PDO {
        return getPDO();
    }

    public static function login(string $username, string $password, bool $remember = false): bool {
        $user = self::fetchUserWithPermissions('username', $username);
        if ($user && password_verify($password, $user['password_hash'])) {
            self::setSession($user);
            self::getDB()->prepare("UPDATE ff_users SET last_login = NOW() WHERE user_id = ?")->execute([$user['user_id']]);
            if ($remember) self::issueRememberToken($user['user_id']);
            return true;
        }
        return false;
    }

    public static function logout(): void {
        self::clearRememberToken();
        session_destroy();
        header('Location: /auth/login.php');
        exit;
    }

    private static function fetchUserWithPermissions(string $column, $value): ?array {
        $stmt = self::getDB()->prepare("
            SELECT u.*, GROUP_CONCAT(p.permission_name) AS permissions
            FROM ff_users u
            LEFT JOIN ff_user_roles ur ON u.user_id = ur.user_id
            LEFT JOIN ff_role_permissions rp ON ur.role_id = rp.role_id
            LEFT JOIN ff_permissions p ON rp.permission_id = p.permission_id
            WHERE u.$column = ? AND u.is_active = 1
            GROUP BY u.user_id
        ");
        $stmt->execute([$value]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function setSession(array $user): void {
        $_SESSION['user_id']     = $user['user_id'];
        $_SESSION['username']    = $user['username'];
        $_SESSION['full_name']   = trim($user['first_name'] . ' ' . $user['last_name']);
        $_SESSION['permissions'] = $user['permissions'] ? explode(',', $user['permissions']) : [];
    }

    // -------------------------------------------------------------------------
    // Remember me
    // -------------------------------------------------------------------------

    public static function attemptRememberMe(): void {
        if (isset($_SESSION['user_id'])) {
            if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
                $last = $_SESSION['remember_refreshed_at'] ?? 0;
                if (time() - $last >= self::REFRESH_INTERVAL) {
                    self::refreshRememberToken();
                    $_SESSION['remember_refreshed_at'] = time();
                }
            }
            return;
        }

        if (empty($_COOKIE[self::REMEMBER_COOKIE])) return;

        $parts = explode(':', $_COOKIE[self::REMEMBER_COOKIE], 2);
        if (count($parts) !== 2) { self::clearRememberCookie(); return; }
        [$selector, $validator] = $parts;

        try {
            $db   = self::getDB();
            $stmt = $db->prepare("SELECT * FROM ff_remember_tokens WHERE selector = ? LIMIT 1");
            $stmt->execute([$selector]);
            $token = $stmt->fetch();

            if (!$token || strtotime($token['expires']) < time()
                || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
                self::clearRememberCookie();
                return;
            }

            $user = self::fetchUserWithPermissions('user_id', $token['user_id']);
            if (!$user) { self::deleteRememberToken($selector); self::clearRememberCookie(); return; }

            self::setSession($user);
            self::refreshRememberToken();
            $_SESSION['remember_refreshed_at'] = time();
        } catch (Exception $e) {
            error_log('Auth attemptRememberMe: ' . $e->getMessage());
        }
    }

    private static function issueRememberToken(int $userId): void {
        try {
            $selector  = bin2hex(random_bytes(12));
            $validator = bin2hex(random_bytes(32));
            $expires   = date('Y-m-d H:i:s', time() + self::REMEMBER_TTL);

            self::getDB()->prepare("
                INSERT INTO ff_remember_tokens (user_id, selector, validator_hash, expires, created_at, last_used_at, user_agent)
                VALUES (?, ?, ?, ?, NOW(), NOW(), ?)
            ")->execute([$userId, $selector, hash('sha256', $validator), $expires, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

            self::setRememberCookie($selector . ':' . $validator);
            self::getDB()->prepare("DELETE FROM ff_remember_tokens WHERE expires < NOW()")->execute();
        } catch (Exception $e) {
            error_log('Auth issueRememberToken: ' . $e->getMessage());
        }
    }

    private static function refreshRememberToken(): void {
        if (empty($_COOKIE[self::REMEMBER_COOKIE])) return;
        $parts = explode(':', $_COOKIE[self::REMEMBER_COOKIE], 2);
        if (count($parts) !== 2) return;
        [$selector, $validator] = $parts;

        try {
            $stmt = self::getDB()->prepare("SELECT validator_hash FROM ff_remember_tokens WHERE selector = ? LIMIT 1");
            $stmt->execute([$selector]);
            $token = $stmt->fetch();
            if (!$token || !hash_equals($token['validator_hash'], hash('sha256', $validator))) return;

            $expires = date('Y-m-d H:i:s', time() + self::REMEMBER_TTL);
            self::getDB()->prepare("UPDATE ff_remember_tokens SET expires = ?, last_used_at = NOW() WHERE selector = ?")
                ->execute([$expires, $selector]);
            self::setRememberCookie($selector . ':' . $validator);
        } catch (Exception $e) {
            error_log('Auth refreshRememberToken: ' . $e->getMessage());
        }
    }

    private static function clearRememberToken(): void {
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            $parts = explode(':', $_COOKIE[self::REMEMBER_COOKIE], 2);
            if (count($parts) === 2) self::deleteRememberToken($parts[0]);
            self::clearRememberCookie();
        }
    }

    private static function deleteRememberToken(string $selector): void {
        try {
            self::getDB()->prepare("DELETE FROM ff_remember_tokens WHERE selector = ?")->execute([$selector]);
        } catch (Exception $e) {
            error_log('Auth deleteRememberToken: ' . $e->getMessage());
        }
    }

    private static function setRememberCookie(string $value): void {
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => time() + self::REMEMBER_TTL,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::REMEMBER_COOKIE] = $value;
    }

    private static function clearRememberCookie(): void {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public static function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }

    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header('Location: /auth/login.php');
            exit;
        }
    }

    public static function requireLoginJson(): void {
        if (!self::isLoggedIn()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Not authenticated', 'redirect' => '/auth/login.php']);
            exit;
        }
    }

    public static function hasPermission(string $permission): bool {
        return in_array($permission, $_SESSION['permissions'] ?? []);
    }

    public static function hasAnyPermission(array $permissions): bool {
        foreach ($permissions as $p) {
            if (self::hasPermission($p)) return true;
        }
        return false;
    }

    public static function requirePermission(string $permission): void {
        self::requireLogin();
        if (!self::hasPermission($permission)) {
            header('Location: /unauthorized.php');
            exit;
        }
    }

    public static function getUserId(): ?int   { return $_SESSION['user_id']  ?? null; }
    public static function getUsername(): ?string { return $_SESSION['username'] ?? null; }
    public static function getFullName(): string  { return $_SESSION['full_name'] ?? self::getUsername() ?? 'Unknown'; }
}

Auth::attemptRememberMe();
