<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

if (config_exists()) { header('Location: index.php'); exit; }

$err = '';
$v = [
    'db_host' => '127.0.0.1', 'db_port' => '5432', 'db_dbname' => '', 'db_user' => '',
    'db_schema' => 'public', 'app_monstro_login' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? $v[$k]));
    $db = [
        'host'   => $v['db_host'],
        'port'   => $v['db_port'] !== '' ? $v['db_port'] : '5432',
        'dbname' => $v['db_dbname'],
        'user'   => $v['db_user'],
        'pass'   => (string)($_POST['db_pass'] ?? ''),
        'schema' => $v['db_schema'] !== '' ? $v['db_schema'] : 'public',
    ];
    $pw  = (string)($_POST['login_password'] ?? '');
    $pw2 = (string)($_POST['login_password2'] ?? '');

    if ($db['host'] === '' || $db['dbname'] === '' || $db['user'] === '')
    {
        $err = 'Заполните host, имя базы и пользователя.';
    }
    elseif ($pw === '')
    {
        $err = 'Задайте пароль на вход в панель.';
    }
    elseif ($pw !== $pw2)
    {
        $err = 'Пароли на вход не совпадают.';
    }
    elseif ($v['app_monstro_login'] === '')
    {
        $err = 'Укажите логин Monstro — он используется в ссылках меню.';
    }
    elseif (($t = db_test($db)) !== null)
    {
        $err = 'Не удалось подключиться к БД: ' . $t;
    }
    else
    {
        $app = array_merge(app_defaults(), [
            'monstro_login' => $v['app_monstro_login'],
            'password'      => password_hash($pw, PASSWORD_DEFAULT),
        ]);
        if (write_config(['db' => $db, 'app' => $app]))
        {
            header('Location: login.php'); exit;
        }
        $err = 'Не удалось записать config.php — проверьте права на запись в папке панели.';
    }
}

$h = fn($s) => htmlspecialchars((string)$s);
$checks  = system_checks();
$blocked = (bool)array_filter($checks, fn($c) => $c['critical'] && !$c['ok']);

?><!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(APP_NAME) ?> — первая настройка</title>
<link rel="icon" type="image/x-icon" href="<?= asset('favicon.ico') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body class="login-body">
<div class="login-wrap">
  <form class="login-card setup-card" method="post" autocomplete="off">
    <?= brand_logo() ?>
    <div class="login-ver">v<?= htmlspecialchars(APP_VERSION) ?></div>

    <div class="setup-sec">Проверка окружения</div>
    <div class="checks">
      <?php foreach ($checks as $c): ?>
      <div class="chk-row <?= $c['ok'] ? 'ok' : ($c['critical'] ? 'bad' : 'warn') ?>">
        <span class="chk-ic"><?= $c['ok'] ? '✓' : '✕' ?></span>
        <span class="chk-lbl"><?= $h($c['label']) ?></span>
        <?php if ($c['detail']): ?><span class="chk-det"><?= $h($c['detail']) ?></span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($blocked): ?><div class="login-err">Исправьте критичные пункты выше и обновите страницу — без них панель не заработает.</div><?php endif; ?>

    <?php if ($err): ?><div class="login-err"><?= $h($err) ?></div><?php endif; ?>

    <div class="setup-sec">База данных (PostgreSQL)</div>
    <div class="setup-grid">
      <div class="hostport">
        <label class="setup-f"><span>Хост</span><input name="db_host" value="<?= $h($v['db_host']) ?>" required></label>
        <label class="setup-f"><span>Порт</span><input name="db_port" value="<?= $h($v['db_port']) ?>"></label>
      </div>
      <label class="setup-f"><span>База</span><input name="db_dbname" value="<?= $h($v['db_dbname']) ?>" required></label>
    </div>
    <div class="setup-grid">
      <label class="setup-f"><span>Пользователь</span><input name="db_user" value="<?= $h($v['db_user']) ?>" required></label>
      <label class="setup-f"><span>Пароль БД</span><input type="password" name="db_pass" value=""></label>
    </div>

    <div class="setup-sec">Вход в панель</div>
    <div class="setup-grid">
      <label class="setup-f"><span>Пароль на вход</span><input type="password" name="login_password" required></label>
      <label class="setup-f"><span>Повтор пароля</span><input type="password" name="login_password2" required></label>
    </div>

    <div class="setup-sec">Monstro</div>
    <div class="setup-grid">
      <label class="setup-f col-2"><span>Логин Monstro</span><input name="app_monstro_login" value="<?= $h($v['app_monstro_login']) ?>" required></label>
    </div>

    <button class="login-btn" type="submit"<?= $blocked ? ' disabled' : '' ?>>Продолжить →</button>
  </form>
  <div class="login-foot">Powered by <a href="https://profitweb.net/@litwin/" target="_blank" rel="noopener">LITWIN</a></div>
</div>
</body></html>
