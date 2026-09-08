<?php
declare(strict_types=1);

/**
 * Admin authentication helpers.
 */

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']) && !empty($_SESSION['admin_username']);
}

function require_login(): void
{
    if (is_logged_in()) {
        return;
    }
    if (is_api_request()) {
        json_response(false, 'Unauthorized.', null, 401);
    }
    header('Location: ' . url('admin/login.php'));
    exit;
}

function require_admin_api(): void
{
    if (!is_logged_in()) {
        json_response(false, 'Unauthorized.', null, 401);
    }
}

function attempt_login(string $username, string $password): bool
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return false;
    }

    $stmt = db()->prepare('SELECT id, username, password FROM users WHERE username = :u LIMIT 1');
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string) $user['password'])) {
        app_log('warning', 'Authentication failure', ['username' => $username]);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $user['id'];
    $_SESSION['admin_username'] = (string) $user['username'];
    csrf_token(); // ensure token exists after login
    app_log('info', 'Admin login', ['username' => $user['username']]);
    return true;
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function current_admin_username(): string
{
    return (string) ($_SESSION['admin_username'] ?? '');
}
