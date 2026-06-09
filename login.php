<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';
require_config_or_setup();

if (is_authed()) { header('Location: index.php'); exit; }

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') 
{
    if (auth_locked()) 
    {
        $err = 'Слишком много попыток. Подождите ~10 минут.';
    } 
    elseif (auth_login((string)($_POST['password'] ?? ''))) 
    {
        header('Location: index.php'); exit;
    } 
    else 
    {
        $err = auth_locked() ? 'Слишком много попыток. Подождите ~10 минут.' : 'Неверный пароль';
        usleep(400000);
    }
}

$title = APP_NAME;

?><!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — вход</title>
<link rel="icon" type="image/x-icon" href="<?= asset('favicon.ico') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body class="login-body">
<div class="login-wrap">
  <form class="login-card" method="post" autocomplete="off">
    <?= brand_logo() ?>
    <label class="login-field">
      <svg class="ic" data-lucide="lock"></svg>
      <input type="password" name="password" placeholder="Пароль" autofocus required>
    </label>
    <?php if ($err): ?><div class="login-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <button class="login-btn" type="submit">Войти →</button>
  </form>
  <div class="login-foot">Powered by <a href="https://profitweb.net/@litwin/" target="_blank" rel="noopener">LITWIN</a></div>  
</div>
<script src="https://unpkg.com/lucide@1.17.0/dist/umd/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body></html>
