<?php
/**
 * Страница для проверки функций login() и logout() из api/auth.php.
 * Размести этот файл в корне проекта Electro-diary рядом с папкой api.
 * Открой страницу через локальный PHP-сервер, а не командой php auth_test.php.
 */

require_once __DIR__ . '/../api/auth.php';

// Обычно session_start() уже вызывается в api/auth.php.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Токен нужен, чтобы посторонний сайт не мог отправить форму от имени пользователя.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Ошибка проверки формы. Обнови страницу и попробуй снова.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $username = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $status = 'empty';
        } else {
            try {
                $status = login($username, $password) ? 'success' : 'invalid';
            } catch (Throwable $e) {
                error_log('Ошибка авторизации: ' . $e->getMessage());
                $status = 'error';
            }
        }

        header('Location: auth_test.php?status=' . $status, true, 303);
        exit;
    }

    if ($action === 'logout') {
        logout();

        // Если твоя logout() не удаляет содержимое сессии полностью,
        // на всякий случай удаляем идентификатор пользователя.
        unset($_SESSION['user_id']);

        header('Location: auth_test.php?status=logged_out', true, 303);
        exit;
    }
}

$isAuthenticated = isset($_SESSION['user_id']);
$userId = $isAuthenticated ? (string) $_SESSION['user_id'] : null;
$status = $_GET['status'] ?? '';

$messages = [
    'empty'      => ['error', 'Укажи логин и пароль.'],
    'invalid'    => ['error', 'Неверный логин или пароль.'],
    'error'      => ['error', 'Ошибка сервера. Проверь журнал PHP.'],
    'logged_out' => ['success', 'Ты успешно вышел из аккаунта.'],
];

if ($status === 'success' && $isAuthenticated) {
    $messages['success'] = ['success', 'Авторизация прошла успешно!'];
}

$message = $messages[$status] ?? null;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Проверка авторизации — Electro-diary</title>
    <style>
        :root { color-scheme: dark; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; padding: 24px; display: grid; place-items: center; background: #101114; color: #f5f5f6; }
        .card { width: min(100%, 420px); padding: 32px; background: #1b1c21; border: 1px solid #35363e; border-radius: 18px; box-shadow: 0 18px 48px #0005; }
        .eyebrow { margin: 0 0 6px; color: #a6a7b2; font-size: 12px; text-transform: uppercase; letter-spacing: .12em; }
        h1 { margin: 0 0 10px; font-size: 25px; line-height: 1.2; }
        .hint { margin: 0 0 26px; color: #a6a7b2; font-size: 14px; line-height: 1.5; }
        label { display: block; margin: 15px 0 7px; font-size: 13px; font-weight: 600; }
        input:not([type="hidden"]) { width: 100%; padding: 12px 14px; color: #fff; background: #111216; border: 1px solid #444650; border-radius: 10px; outline: 0; font: inherit; }
        input:focus { border-color: #c3c5ce; box-shadow: 0 0 0 3px #ffffff13; }
        button { width: 100%; margin-top: 22px; padding: 12px 16px; border: 0; border-radius: 10px; background: #f1f1f3; color: #16171b; font: inherit; font-weight: 700; cursor: pointer; }
        button:hover { background: #d7d7dc; }
        button.secondary { color: #f1f1f3; background: #34353d; }
        button.secondary:hover { background: #44454e; }
        .status { margin: 0 0 22px; padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.45; }
        .status.success { color: #b7efc5; background: #143324; border: 1px solid #2c6644; }
        .status.error { color: #ffc2c2; background: #3a1e22; border: 1px solid #713b41; }
        .info { margin: 0; padding: 16px; background: #121318; border: 1px solid #35363e; border-radius: 10px; overflow-wrap: anywhere; }
        .info span { display: block; margin-bottom: 5px; color: #a6a7b2; font-size: 12px; }
        .info strong { font-size: 18px; }
        .footnote { margin: 20px 0 0; color: #878995; font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<main class="card">
    <p class="eyebrow">Electro-diary / Test</p>
    <h1>Проверка авторизации</h1>
    <p class="hint">Тестовая страница для функций <code>login()</code> и <code>logout()</code> из <code>api/auth.php</code>.</p>

    <?php if ($message): ?>
        <div class="status <?= htmlspecialchars($message[0], ENT_QUOTES, 'UTF-8') ?>" role="status">
            <?= htmlspecialchars($message[1], ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($isAuthenticated): ?>
        <div class="info">
            <span>Статус</span>
            <strong>Авторизован</strong>
        </div>
        <div class="info" style="margin-top: 10px;">
            <span>ID пользователя (из сессии)</span>
            <strong><?= htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="secondary">Выйти из аккаунта</button>
        </form>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="login">
            <label for="login">Логин</label>
            <input id="login" name="login" type="text" autocomplete="username" required autofocus>
            <label for="password">Пароль</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit">Войти</button>
        </form>
    <?php endif; ?>

    <p class="footnote">Для локальной проверки. Не публикуй тестовую страницу на рабочем сервере.</p>
</main>
</body>
</html>
