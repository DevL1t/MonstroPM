<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

require_config_or_setup();

if (isset($_GET['logout'])) { auth_logout(); header('Location: login.php'); exit; }
require_auth_page();

if (!isset($_GET['page'])) { header('Location: index.php?page=profiles'); exit; }

$page = in_array($_GET['page'], ['dashboard', 'profiles', 'settings'], true) ? $_GET['page'] : 'profiles';
$table = cfg()['app']['table'];
$appTitle = APP_NAME;

# Проверяем БД заранее (до вывода), чтобы при сбое показать аккуратную ошибку, а не падать посреди вёрстки
$dbErr = !db_ok();

$colMeta = []; $pk = []; $groupCol = null;
if ($page === 'profiles' && !$dbErr)
{
    $cols = get_columns($table);
    $pk   = get_pk($table);
    $groupCol = cfg()['app']['group_column'] ?? null;

    # колонки, видимые по умолчанию (остальные — через «Колонки»)
    $showDefault = ['pid','data_create','party','platform','platform_version','browser','browser_version',
        'fingerprints','cookies','proxy','last_date_work','date_block','last_visit_sites','last_task',
        'domaincount','warm'];

    # поля, доступные в форме (остальные — авто/системные)
    $formFields = ['party','platform','platform_version','browser','browser_version',
        'fingerprints','cookies','localstorage','proxy'];
        
    foreach ($cols as $c)
    {
        $cn = $c['column_name'];
        $nullable = $c['is_nullable']==='YES';
        $hasDefault = $c['column_default']!==null;
        $colMeta[] = [
            'name'=>$cn,'title'=>$cn,'is_pk'=>$c['is_pk'],'is_bool'=>$c['is_bool'],
            'is_num'=>$c['is_numeric'],'is_ts'=>$c['is_ts'],'is_long'=>$c['is_long'],
            'is_auto'=>$c['is_auto'],'nullable'=>$nullable,'visible'=>in_array($cn,$showDefault,true),
            'has_default'=>$hasDefault,
            'maxlen'=>$c['character_maximum_length']?(int)$c['character_maximum_length']:null,
            'required'=>(!$nullable && !$hasDefault && !$c['is_auto'] && !$c['is_pk']),
            'form'=>in_array($cn,$formFields,true),
        ];
    }
}

function ico(string $n){ return '<svg class="ic" data-lucide="'.$n.'"></svg>'; }
?><!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($appTitle) ?> — <?= $page==='dashboard'?'Дашборд':'Профили' ?></title>
<link rel="icon" type="image/x-icon" href="<?= asset('favicon.ico') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/3.0.3/css/responsive.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.1.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="<?= asset('assets/app.css') ?>">
</head>
<body>
<button class="btn toggle" id="menuBtn"><?= ico('menu') ?></button>
<div class="scrim" id="scrim"></div>
<div class="layout">
  <aside class="side" id="side">
    <div class="brand">
      <img src="<?= asset('assets/logo.png') ?>" style="width:40px" alt="">
      <span class="wm"><i>MONSTRO</i><b>Profile <span>Manager</span></b></span>
    </div>

    <div class="nav-label">Обзор</div>
    <nav class="nav">
      <a href="?page=dashboard" class="<?= $page==='dashboard'?'active':'' ?>"><?= ico('layout-dashboard') ?> Дашборд</a>
      <a href="?page=profiles" class="<?= $page==='profiles'?'active':'' ?>"><?= ico('users') ?> Профили
<?php if (!$dbErr): ?><span class="cnt"><?= number_format(table_count($table),0,'',' ') ?></span><?php endif; ?>
      </a>
    </nav>

    <?php
    $login = cfg()['app']['monstro_login'];
    if ($login !== ''):
      $base = 'https://monstro.ru/users/' . rawurlencode($login) . '/';
      $ext = [
        ['box-projects',      'Проекты',  'folder-kanban'],
        ['servers',           'Серверы',  'server'],
        ['keys',              'Ключи',    'key-round'],
        ['proxy_links',       'Прокси',   'network'],
        ['fingerprint_links', 'Отпечатки','fingerprint'],
      ];
    ?>
    <div class="nav-label">Monstro</div>
    <nav class="nav">
      <?php foreach ($ext as $e): ?>
        <a href="<?= htmlspecialchars($base . $e[0] . '/') ?>" target="_blank" rel="noopener">
          <?= ico($e[2]) ?> <?= htmlspecialchars($e[1]) ?>
          <svg class="ic ext" data-lucide="external-link"></svg>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <nav class="nav nav-logout">
      <a href="?page=settings" class="<?= $page==='settings'?'active':'' ?>"><?= ico('settings') ?> Настройки</a>
      <a href="?logout=1"><?= ico('log-out') ?> Выйти</a>
    </nav>

    <div class="side-foot">
      <div class="credit">Powered by <a href="https://profitweb.net/@litwin/" target="_blank" rel="noopener">LITWIN</a></div>
      <div class="ver"><?= htmlspecialchars(APP_NAME) ?> v<?= htmlspecialchars(APP_VERSION) ?> · © <?= date('Y') ?></div>
    </div>
  </aside>

  <main class="main">
  <?php if ($dbErr && $page !== 'settings'): ?>
    <div class="db-down">
      <?= ico('database') ?>
      <h2>Не удалось подключиться к базе</h2>
      <p>Проверьте доступы к PostgreSQL в <a href="?page=settings">настройках</a>.</p>
    </div>
  <?php elseif ($page === 'dashboard'): ?>
    <div class="topbar">
      <h1>Дашборд</h1>
      <span class="spacer"></span>
      <button class="btn" id="refreshBtn"><?= ico('refresh-cw') ?> Обновить</button>
    </div>
    <div class="cards" id="cards"></div>
    <?php
    # блок графика: $id — id canvas, $ic — иконка, $t — заголовок, $col — ширина (4/6/8/12)
    function chartBox(string $id, string $ic, string $t, int $col = 4)
    {
        echo '<div class="chart col-'.$col.'"><h3>'.ico($ic).' '.htmlspecialchars($t).'</h3>'
           . '<div class="canvas-wrap"><div class="ph"><div class="spinner"></div></div>'
           . '<canvas id="'.$id.'"></canvas></div></div>';
    }
    $cdays = (int)cfg()['app']['chart_created_days'];
    ?>
    <div class="charts">
      <?php
      chartBox('chDevices',   'monitor-smartphone', 'Устройства');
      chartBox('chPlatforms', 'smartphone',  'Платформы');
      chartBox('chBrowsers',  'globe',       'Браузеры');
      chartBox('chGroups',    'pie-chart',   'Профили по группам');
      chartBox('chAge',       'clock',       'Возраст профилей');
      chartBox('chActivity',  'activity',    'Активность');
      chartBox('chCreated',   'trending-up', "Создано профилей ($cdays дней)", 12);
      ?>
    </div>
  <?php elseif ($page === 'settings'):
    $a = cfg()['app']; $db = cfg()['db'];
    $val = fn($x) => htmlspecialchars((string)$x);
  ?>
    <div class="topbar"><h1><?= ico('settings') ?> Настройки</h1></div>
    <div class="panel" style="padding:22px">
      <form id="settingsForm">
        <div class="setup-sec" style="border-top:0;padding-top:0">Общие</div>
        <div class="setup-grid">
          <label class="setup-f col-2"><span>Логин Monstro (для ссылок в меню)</span><input name="monstro_login" value="<?= $val($a['monstro_login']) ?>"></label>
        </div>

        <div class="setup-sec">Таблица</div>
        <div class="setup-grid">
          <label class="setup-f"><span>Строк на странице</span><input type="number" min="1" name="per_page" value="<?= $val($a['per_page']) ?>"></label>
          <label class="setup-f"><span>Сортировка по умолчанию</span>
            <select class="sel" name="sort_dir">
              <option value="desc"<?= $a['sort_dir']==='desc'?' selected':'' ?>>Новые сверху (desc)</option>
              <option value="asc"<?= $a['sort_dir']==='asc'?' selected':'' ?>>Старые сверху (asc)</option>
            </select>
          </label>
          <label class="setup-f col-2"><span>Обрезка длинных значений в ячейках (символов)</span><input type="number" min="0" name="truncate_len" value="<?= $val($a['truncate_len']) ?>"></label>
        </div>

        <div class="setup-sec">Прогрев профилей</div>
        <div class="setup-grid">
          <label class="setup-f"><span>Мин. возраст, дней</span><input type="number" min="0" name="warm_days" value="<?= $val($a['warm_days']) ?>"></label>
          <label class="setup-f"><span>Мин. доменов</span><input type="number" min="0" name="warm_domains" value="<?= $val($a['warm_domains']) ?>"></label>
        </div>

        <div class="setup-sec">Графики</div>
        <div class="setup-grid">
          <label class="setup-f col-2"><span>«Создано профилей» — за сколько дней</span><input type="number" min="1" name="chart_created_days" value="<?= $val($a['chart_created_days']) ?>"></label>
        </div>

        <div class="setup-sec">База данных (PostgreSQL)</div>
        <div class="setup-grid">
          <div class="hostport">
            <label class="setup-f"><span>Хост</span><input name="db_host" value="<?= $val($db['host'] ?? '') ?>"></label>
            <label class="setup-f"><span>Порт</span><input name="db_port" value="<?= $val($db['port'] ?? '5432') ?>"></label>
          </div>
          <label class="setup-f"><span>База</span><input name="db_dbname" value="<?= $val($db['dbname'] ?? '') ?>"></label>
        </div>
        <div class="setup-grid">
          <label class="setup-f"><span>Пользователь</span><input name="db_user" value="<?= $val($db['user'] ?? '') ?>"></label>
          <label class="setup-f"><span>Пароль БД</span><input type="password" name="db_pass" placeholder="оставьте пустым — без изменений"></label>
        </div>

        <div class="setup-sec">Безопасность</div>
        <div class="setup-grid">
          <label class="setup-f col-2"><span>Новый пароль на вход</span><input type="password" name="new_password" placeholder="оставьте пустым — без изменений"></label>
        </div>

        <div class="actions" style="display:flex;gap:10px;align-items:center;margin-top:18px">
          <button class="btn primary" type="submit"><?= ico('check') ?> Сохранить</button>
          <span class="err" id="settingsErr"></span>
        </div>
      </form>
    </div>
  <?php else: ?>
    <div class="topbar">
      <h1><?= ico('users') ?> Профили</h1>
      <span class="spacer"></span>
      <?php if ($pk): ?><button class="btn primary" id="addBtn"><?= ico('plus') ?> Добавить</button><?php endif; ?>
    </div>

    <?php if ($groupCol): ?>
    <div class="filters" id="filters">
      <div class="filter-row">
        <span class="lab"><?= ico('users') ?> Группа</span>
        <div id="groupChips" style="display:flex;gap:8px;flex-wrap:wrap">
          <span class="chip active" data-g="">Все</span>
        </div>
      </div>
      <div class="filter-row">
        <span class="lab"><?= ico('sliders-horizontal') ?> Фильтры</span>
        <select class="sel" id="fPlatform"><option value="">Платформа: все</option></select>
        <select class="sel" id="fBrowser"><option value="">Браузер: все</option></select>
        <select class="sel" id="fDevice">
          <option value="">Устройство: все</option>
          <option value="mobile">📱 Мобильные</option>
          <option value="desktop">🖥 Десктоп</option>
        </select>
        <select class="sel" id="fAge">
          <option value="">Возраст: любой</option>
          <option value="24h">&lt; 24 часов</option>
          <option value="3d">&lt; 3 дней</option>
          <option value="7d">&lt; 7 дней</option>
          <option value="30d">&lt; 30 дней</option>
        </select>
        <select class="sel" id="fActivity">
          <option value="">Активность: любая</option>
          <option value="fresh">Без активности (0 cookies)</option>
          <option value="low">Низкая</option>
          <option value="mid">Средняя</option>
          <option value="high">Высокая</option>
        </select>
        <span class="reset-f" id="resetF"><?= ico('x') ?> сбросить</span>
      </div>
    </div>
    <?php endif; ?>

    <?php $bulk = $pk && count($pk) === 1; ?>
    <?php if ($bulk): ?>
    <div class="bulkbar" id="bulkbar">
      <span class="bulk-info"><?= ico('check-square') ?> Выбрано: <b id="bulkCount">0</b></span>
      <span class="spacer"></span>
      <?php if ($groupCol): ?>
      <div class="bulk-group">
        <?= ico('users') ?>
        <select class="sel" id="bulkGroupSel"><option value="">— группа —</option></select>
        <input type="text" class="sel" id="bulkGroupNew" placeholder="новая группа" style="display:none">
        <button class="btn" id="bulkGroupApply"><?= ico('folder-input') ?> Перенести</button>
      </div>
      <?php endif; ?>
      <button class="btn ghost" id="bulkClear"><?= ico('x') ?> Снять выделение</button>
      <button class="btn danger" id="bulkDel"><?= ico('trash-2') ?> Удалить выбранные</button>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div id="loadOverlay" class="show"><span class="spinner"></span></div>
      <table id="grid" class="display nowrap" style="width:100%">
        <thead><tr>
          <?php if ($bulk): ?><th class="selcol"></th><?php endif; ?>
          <?php foreach ($colMeta as $c): ?><th><?= htmlspecialchars($c['title']) ?></th><?php endforeach; ?>
          <?php if ($pk): ?><th>Действия</th><?php endif; ?>
        </tr></thead>
      </table>
    </div>
  <?php endif; ?>
  </main>
</div>

<!-- модалка CRUD -->
<div class="modal" id="modal"><div class="box">
  <h2 id="modalTitle"><?= ico('pencil') ?> Запись</h2>
  <form id="form">
    <input type="hidden" name="__edit" id="f_edit" value="0">
    <div class="form-grid" id="formGrid"></div>
    <div class="err" id="formErr"></div>
    <div class="actions">
      <button type="button" class="btn" id="cancelBtn"><?= ico('x') ?> Отмена</button>
      <button type="submit" class="btn primary"><?= ico('check') ?> Сохранить</button>
    </div>
  </form>
</div></div>

<!-- модалка подтверждения массового удаления -->
<div class="modal" id="confirmModal"><div class="box sm">
  <h2 class="danger-h"><?= ico('alert-triangle') ?> Удаление</h2>
  <p id="confirmText" class="confirm-text">Удалить выбранные записи?</p>
  <div class="actions">
    <button type="button" class="btn" id="confirmNo"><?= ico('x') ?> Отмена</button>
    <button type="button" class="btn danger" id="confirmYes"><?= ico('trash-2') ?> Удалить</button>
  </div>
</div></div>

<div class="toast" id="toast"><svg class="ic" data-lucide="check-circle"></svg><span id="toastMsg"></span></div>

<script>
window.APP = {
  page:   <?= json_encode($page) ?>,
  table:  <?= json_encode($table) ?>,
  cols:   <?= json_encode($colMeta, JSON_UNESCAPED_UNICODE) ?>,
  pk:     <?= json_encode($pk) ?>,
  groupc: <?= json_encode($groupCol) ?>,
  perPage:<?= (int)cfg()['app']['per_page'] ?>,
  entity: 'профиль',
  sortCol:<?= json_encode(cfg()['app']['sort_column'] ?? '') ?>,
  sortDir:<?= json_encode(cfg()['app']['sort_dir'] ?? 'desc') ?>,
  dbErr:  <?= $dbErr ? 'true' : 'false' ?>
};
</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/3.0.3/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.colVis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/lucide@1.17.0/dist/umd/lucide.min.js"></script>
<script src="<?= asset('assets/app.js') ?>"></script>
</body></html>
