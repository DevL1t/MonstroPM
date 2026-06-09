<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!config_exists()) { http_response_code(503); json_out(['error' => 'Панель не настроена']); }
require_auth_api();

if ($_POST) $_GET = array_merge($_GET, $_POST);

$action = $_GET['action'] ?? '';
$table  = cfg()['app']['table'];
assert_table($table);

try
{
    switch ($action)
    {
        case 'data':    ssp_data($table);    break;
        case 'groups':  list_groups($table); break;
        case 'facets':  facets($table);      break;
        case 'stats':   stats($table);       break;
        case 'row':     get_row($table);     break;
        case 'save':    save_row($table);    break;
        case 'delete':  delete_row($table);  break;
        case 'delete_bulk': delete_bulk($table); break;
        case 'set_group_bulk': set_group_bulk($table); break;
        case 'save_settings':  save_settings();        break;
        default:        http_response_code(400); json_out(['error' => 'unknown action']);
    }
}
catch (Throwable $e)
{
    log_err($e);
    json_out(['error' => 'Ошибка сервера']);
}

# Сборка WHERE по доменным фильтрам
function build_filters(string $table, array &$where, array &$params): void
{
    $names = column_names($table);
    $g = group_col($table);
    $gv = $_GET['group'] ?? '';

    if ($g && $gv !== '' && in_array($g, $names, true))
    {
        if ($gv === '__none__') $where[] = "(" . qi($g) . " IS NULL OR " . qi($g) . " = '')";
        else { $where[] = qi($g) . " = :gval"; $params[':gval'] = $gv; }
    }
    
    $eq = function(string $col, string $param) use (&$where, &$params, $names)
    {
        $v = $_GET[$param] ?? '';
        if ($v !== '' && in_array($col, $names, true))
        {
            $ph = ':' . $param; $where[] = qi($col) . " = $ph"; $params[$ph] = $v;
        }
    };
    $eq('platform', 'f_platform');
    $eq('browser',  'f_browser');

    $dev = $_GET['f_device'] ?? '';
    if ($dev !== '' && in_array('ismobiledevice', $names, true))
    {
        $where[] = qi('ismobiledevice') . ' = ' . ($dev === 'mobile' ? 'true' : 'false');
    }
    $age = $_GET['f_age'] ?? '';
    $ageMap = ['24h'=>'1 day','3d'=>'3 days','7d'=>'7 days','30d'=>'30 days'];
    if (isset($ageMap[$age]) && in_array('data_create', $names, true))
    {
        $where[] = qi('data_create') . " > now() - interval '" . $ageMap[$age] . "'";
    }
    $act = $_GET['f_activity'] ?? '';
    if ($act !== '' && in_array('cookies_len', $names, true))
    {
        $c = qi('cookies_len');
        if ($act === 'fresh') $where[] = "$c = 0";
        elseif ($act === 'low')  $where[] = "$c > 0 AND $c < 50";
        elseif ($act === 'mid')  $where[] = "$c >= 50 AND $c < 150";
        elseif ($act === 'high') $where[] = "$c >= 150";
    }
}

# Данные таблицы для DataTables (server-side)
function ssp_data(string $table): void
{
    $cols   = get_columns($table);
    $names  = array_column($cols, 'column_name');
    $colMap = [];
    foreach ($cols as $c) $colMap[$c['column_name']] = $c;

    $draw   = (int)($_GET['draw'] ?? 1);
    $start  = max(0, (int)($_GET['start'] ?? 0));
    $length = (int)($_GET['length'] ?? cfg()['app']['per_page']);
    if ($length <= 0 || $length > 500) $length = cfg()['app']['per_page'];

    $where = []; $params = [];
    build_filters($table, $where, $params);

    # глобальный поиск
    $gs = trim((string)($_GET['search']['value'] ?? ''));
    if ($gs !== '')
    {
        $ors = [];
        foreach ($names as $n) $ors[] = "CAST(" . qi($n) . " AS text) ILIKE :gs";
        if ($ors) { $where[] = '(' . implode(' OR ', $ors) . ')'; $params[':gs'] = '%' . $gs . '%'; }
    }
    # поиск по колонкам
    foreach (($_GET['columns'] ?? []) as $rc)
    {
        $cn = $rc['data'] ?? ''; $cv = trim((string)($rc['search']['value'] ?? ''));
        if ($cv === '' || !in_array($cn, $names, true)) continue;
        $ph = ':c' . count($params);
        $where[] = "CAST(" . qi($cn) . " AS text) ILIKE $ph"; $params[$ph] = '%' . $cv . '%';
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    # сортировка
    $orderSql = '';
    $ord = $_GET['order'][0] ?? null;
    if ($ord)
    {
        $colData = $_GET['columns'][(int)($ord['column'] ?? 0)]['data'] ?? '';
        $dir = (strtolower($ord['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
        if (in_array($colData, $names, true)) $orderSql = ' ORDER BY ' . qi($colData) . " $dir";
    }
    if ($orderSql === '') { $pk = get_pk($table); if ($pk) $orderSql = ' ORDER BY ' . qi($pk[0]) . ' DESC'; }

    # важно: длинные/текстовые поля обрезаем В SQL через left(), чтобы
    # не тянуть мегабайты cookies/localstorage/fingerprints с удалённой БД
    $trunc = (int)cfg()['app']['truncate_len'];
    $sel = [];
    foreach ($cols as $c)
    {
        $n = $c['column_name'];
        if ($c['is_long']) $sel[] = 'left(cast(' . qi($n) . ' as text), ' . ($trunc + 1) . ') as ' . qi($n);
        else $sel[] = qi($n);
    }
    $selSql = implode(', ', $sel);

    $pdo = db();
    # общий total берём из короткого кэша (не считаем каждый раз)
    $total = table_count($table);
    if ($where)
    {
        # есть фильтры → отфильтрованное считаем «вживую» (дешёвый count без текстов)
        $stf = $pdo->prepare("SELECT count(*) FROM " . qi($table) . $whereSql);
        $stf->execute($params);
        $filtered = (int)$stf->fetchColumn();
    }
    else
    {
        $filtered = $total; # без фильтров — равно total, запрос не нужен
    }

    $st = $pdo->prepare("SELECT $selSql FROM " . qi($table) . $whereSql . $orderSql . " LIMIT $length OFFSET $start");
    $st->execute($params);
    $rows = $st->fetchAll();
    $out = [];
    foreach ($rows as $r)
    {
        $row = [];
        foreach ($names as $n)
        {
            $v = $r[$n]; $c = $colMap[$n];
            if ($c['is_bool'])
            {
                $row[$n] = ($v === true || $v === 't' || $v === '1' || $v === 1) ? '1' : (($v === null) ? null : '0');
            }
            elseif (is_string($v) && s_len($v) > $trunc)
            {
                $row[$n] = s_cut($v, $trunc) . '…';
            }
            else $row[$n] = $v;
        }
        $out[] = $row;
    }
    json_out(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $out]);
}

# Список групп со счётчиками
function list_groups(string $table): void
{
    $gc = group_col($table);
    if (!$gc) json_out([]);
    json_out(db()->query(
        "SELECT COALESCE(NULLIF(" . qi($gc) . ", ''), '__none__') AS g, count(*) AS n
           FROM " . qi($table) . " GROUP BY 1 ORDER BY n DESC")->fetchAll());
}

# Уникальные значения для фильтров (платформа/браузер)
function facets(string $table): void
{
    $names = column_names($table);
    $distinct = function(string $col) use ($names, $table)
    {
        if (!in_array($col, $names, true)) return [];
        return db()->query("SELECT DISTINCT " . qi($col) . " AS v FROM " . qi($table)
            . " WHERE " . qi($col) . " IS NOT NULL AND " . qi($col) . " <> '' ORDER BY 1")
            ->fetchAll(PDO::FETCH_COLUMN);
    };
    json_out([
        'platform' => $distinct('platform'),
        'browser'  => $distinct('browser'),
    ]);
}

# Статистика для дашборда (один запрос)
function stats(string $table): void
{
    $names = column_names($table);
    $q = qi($table);
    $has = fn($c) => in_array($c, $names, true);

    # ВСЯ статистика одним запросом (json_agg-подзапросы) — один round-trip к удалённой БД
    $sel = ["(SELECT count(*) FROM $q) AS total"];
    if ($has('party'))
        $sel[] = "(SELECT json_agg(r) FROM (SELECT COALESCE(NULLIF(party,''),'(без группы)') AS label, count(*) AS n FROM $q GROUP BY 1 ORDER BY n DESC) r) AS groups";
    if ($has('platform'))
        $sel[] = "(SELECT json_agg(r) FROM (SELECT COALESCE(NULLIF(platform,''),'?') AS label, count(*) AS n FROM $q GROUP BY 1 ORDER BY n DESC) r) AS platforms";
    if ($has('browser'))
        $sel[] = "(SELECT json_agg(r) FROM (SELECT COALESCE(NULLIF(browser,''),'?') AS label, count(*) AS n FROM $q GROUP BY 1 ORDER BY n DESC) r) AS browsers";
    if ($has('ismobiledevice'))
        $sel[] = "(SELECT count(*) FROM $q WHERE ismobiledevice) AS mobile";
    if ($has('data_create'))
    {
        # возрастные корзины из конфига
        $buckets = cfg()['app']['age_buckets'] ?? [['label'=>'все','days'=>null]];
        $whenL = []; $whenO = []; $elseL = 'прочее'; $elseO = count($buckets);
        foreach ($buckets as $i => $b)
        {
            $lbl = str_replace("'", '', (string)($b['label'] ?? ''));
            if (isset($b['days']) && $b['days'] !== null)
            {
                $d = (int)$b['days'];
                $whenL[] = "WHEN data_create > now()-interval '$d days' THEN '$lbl'";
                $whenO[] = "WHEN data_create > now()-interval '$d days' THEN " . ($i + 1);
            } else { $elseL = $lbl; $elseO = $i + 1; }
        }
        $caseL = 'CASE ' . implode(' ', $whenL) . " ELSE '$elseL' END";
        $caseO = 'CASE ' . implode(' ', $whenO) . " ELSE $elseO END";
        $sel[] = "(SELECT json_agg(r ORDER BY r.ord) FROM (
                    SELECT $caseL AS label, count(*) AS n, min($caseO) AS ord
                    FROM $q GROUP BY 1) r) AS age";
        $cdays = (int)cfg()['app']['chart_created_days'];
        $sel[] = "(SELECT json_agg(r ORDER BY r.d) FROM (
                    SELECT to_char(data_create::date,'DD.MM') AS label, data_create::date AS d, count(*) AS n
                    FROM $q WHERE data_create > now()-interval '$cdays days' GROUP BY data_create::date) r) AS created";
        $sel[] = "(SELECT count(*) FROM $q WHERE data_create::date = now()::date) AS created_today";
    }
    if ($has('cookies_len'))
    {
        $sel[] = "(SELECT json_agg(r ORDER BY r.ord) FROM (
                    SELECT CASE
                      WHEN cookies_len = 0 THEN 'Без активности'
                      WHEN cookies_len < 50 THEN 'Низкая'
                      WHEN cookies_len < 150 THEN 'Средняя'
                      ELSE 'Высокая' END AS label, count(*) AS n,
                      min(CASE
                      WHEN cookies_len = 0 THEN 1
                      WHEN cookies_len < 50 THEN 2
                      WHEN cookies_len < 150 THEN 3 ELSE 4 END) AS ord
                    FROM $q GROUP BY 1) r) AS activity";
        $sel[] = "(SELECT count(*) FROM $q WHERE cookies_len = 0) AS fresh";
    }
    if ($has('domaincount'))
        $sel[] = "(SELECT round(avg(domaincount),1) FROM $q) AS avg_domaincount";

    # прогретые: возраст ≥ N дней И доменов ≥ M (пороги из конфига)
    $warmDays = (int)cfg()['app']['warm_days'];
    $warmDom  = (int)cfg()['app']['warm_domains'];
    if ($has('data_create') && $has('domaincount'))
        $sel[] = "(SELECT count(*) FROM $q WHERE data_create < now() - interval '$warmDays days' AND domaincount >= $warmDom) AS warmed";

    $row = db()->query("SELECT " . implode(', ', $sel))->fetch();

    $res = ['total' => (int)$row['total']];
    foreach (['groups','platforms','browsers','age','created','activity'] as $k)
    {
        if (array_key_exists($k, $row)) $res[$k] = $row[$k] !== null ? (json_decode($row[$k], true) ?: []) : [];
    }
    if (array_key_exists('mobile', $row))
        $res['device'] = ['mobile' => (int)$row['mobile'], 'desktop' => $res['total'] - (int)$row['mobile']];
    if (array_key_exists('created_today', $row)) $res['created_today'] = (int)$row['created_today'];
    if (array_key_exists('fresh', $row))         $res['fresh'] = (int)$row['fresh'];
    if (array_key_exists('avg_domaincount', $row))
        $res['avg_domaincount'] = $row['avg_domaincount'] !== null ? (float)$row['avg_domaincount'] : null;
    if (array_key_exists('warmed', $row))
    {
        $res['warmed']       = (int)$row['warmed'];
        $res['warm_days']    = $warmDays;
        $res['warm_domains'] = $warmDom;
    }

    json_out($res);
}

# Одна запись по первичному ключу
function get_row(string $table): void
{
    $pk = get_pk($table);
    if (!$pk) json_out(['error' => 'no primary key']);
    [$where, $params] = pk_where($table, $pk);
    $st = db()->prepare("SELECT * FROM " . qi($table) . " WHERE $where LIMIT 1");
    $st->execute($params);
    json_out($st->fetch() ?: ['error' => 'not found']);
}

# Создание/обновление записи (валидация, авто-PK, авто-ismobiledevice)
function save_row(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $cols = get_columns($table); $pk = get_pk($table);
    $colMap = []; foreach ($cols as $c) $colMap[$c['column_name']] = $c;
    $isEdit = isset($_POST['__edit']) && $_POST['__edit'] === '1';
    $input = $_POST;
    $set = []; $params = [];
    $errReq = []; $errFmt = [];

    foreach ($cols as $c)
    {
        $name = $c['column_name'];
        if (!$isEdit && ($c['is_auto'] || $c['is_pk'])) continue; # PK при вставке генерим отдельно
        if ($isEdit && $c['is_pk'])   continue;                    # PK при редактировании не меняем

        if ($c['is_bool'])
        {
            $val = isset($input[$name]) && $input[$name] !== '' && $input[$name] !== '0';
            $set[$name] = $val ? 'true' : 'false';
            continue;
        }

        $raw     = array_key_exists($name, $input) ? $input[$name] : null;
        $isEmpty = ($raw === null || $raw === '');
        $hasDef  = ($c['column_default'] !== null);
        $nullable= ($c['is_nullable'] === 'YES');

        if ($isEmpty)
        {
            if ($hasDef)        continue;                          # есть дефолт → применится (now()/0/false)
            elseif ($nullable){ $set[$name]=':'.$name; $params[':'.$name]=null; } # можно NULL
            else               $errReq[] = $name;                 # NOT NULL без дефолта → обязательное
            continue;
        }

        # непустое значение — проверки формата
        if ($c['is_numeric'] && !is_numeric($raw)) { $errFmt[] = "$name: нужно число"; continue; }
        $len = $c['character_maximum_length'];
        if ($len !== null && s_len((string)$raw) > (int)$len) { $errFmt[] = "$name: макс. $len символов"; continue; }

        $set[$name] = ':' . $name; $params[':' . $name] = $raw;
    }

    # вставка: для PK без автоинкремента берём MAX(pk)+1 (на боевой базе нет последовательностей)
    if (!$isEdit)
    {
        foreach ($pk as $pkcol)
        {
            $pc = $colMap[$pkcol] ?? null;
            if ($pc && $pc['is_auto']) continue;                  # есть sequence → отдаёт БД
            $prov = isset($input[$pkcol]) && $input[$pkcol] !== '';
            if ($prov) { $set[$pkcol]=':'.$pkcol; $params[':'.$pkcol]=$input[$pkcol]; }
            else
            {
                $next = (int)db()->query("SELECT COALESCE(MAX(".qi($pkcol)."),0)+1 FROM ".qi($table))->fetchColumn();
                $set[$pkcol]=':'.$pkcol; $params[':'.$pkcol]=$next;
            }
        }
    }

    # ismobiledevice определяем автоматически по платформе (моб = Android/iOS)
    if (isset($colMap['ismobiledevice']) && array_key_exists('platform', $input))
    {
        $plat = (string)$input['platform'];
        $set['ismobiledevice'] = in_array($plat, ['Android','iOS'], true) ? 'true' : 'false';
    }

    if ($errReq) { http_response_code(422); json_out(['ok'=>false,'error'=>'Заполните обязательные поля: '.implode(', ', $errReq)]); }
    if ($errFmt) { http_response_code(422); json_out(['ok'=>false,'error'=>'Неверный формат — '.implode('; ', $errFmt)]); }
    if (!$set)   { http_response_code(422); json_out(['ok'=>false,'error'=>'Нет данных для сохранения']); }

    try
    {
        if ($isEdit)
        {
            [$where, $pkP] = pk_where($table, $pk);
            $assign = []; foreach ($set as $col => $ph) $assign[] = qi($col) . ' = ' . $ph;
            $st = db()->prepare("UPDATE " . qi($table) . " SET " . implode(', ', $assign) . " WHERE $where");
            $st->execute($params + $pkP);
            json_out(['ok' => true, 'mode' => 'update']);
        }
        else
        {
            $st = db()->prepare("INSERT INTO " . qi($table) . " (" . implode(', ', array_map('qi', array_keys($set)))
                . ") VALUES (" . implode(', ', array_values($set)) . ")" . ($pk ? ' RETURNING ' . qi($pk[0]) : ''));
            $st->execute($params);
            json_out(['ok' => true, 'mode' => 'insert', 'id' => $pk ? $st->fetchColumn() : null]);
        }
    }
    catch (Throwable $e)
    {
        log_err($e); http_response_code(422);
        json_out(['ok' => false, 'error' => 'Не удалось сохранить (детали в логе)']);
    }
}

# Удалить одну запись по PK
function delete_row(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $pk = get_pk($table);
    if (!$pk) json_out(['ok' => false, 'error' => 'no primary key']);
    try
    {
        [$where, $params] = pk_where($table, $pk);
        $st = db()->prepare("DELETE FROM " . qi($table) . " WHERE $where");
        $st->execute($params);
        json_out(['ok' => true, 'deleted' => $st->rowCount()]);
    }
    catch (Throwable $e)
    {
        log_err($e); http_response_code(422);
        json_out(['ok' => false, 'error' => 'Не удалось удалить (детали в логе)']);
    }
}

# Массовое удаление по списку значений одиночного PK
function delete_bulk(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $pk = get_pk($table);
    if (count($pk) !== 1) json_out(['ok'=>false,'error'=>'массовое удаление доступно только для таблицы с одиночным ключом']);
    $col = $pk[0];
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || !$ids) json_out(['ok'=>false,'error'=>'не выбрано ни одной записи']);
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if (count($ids) > 5000) json_out(['ok'=>false,'error'=>'слишком много записей за один раз (макс. 5000)']);
    try
    {
        $ph = []; $params = [];
        foreach ($ids as $i => $v) { $k = ':id'.$i; $ph[] = $k; $params[$k] = $v; }
        $st = db()->prepare("DELETE FROM " . qi($table) . " WHERE " . qi($col) . " IN (" . implode(',', $ph) . ")");
        $st->execute($params);
        json_out(['ok' => true, 'deleted' => $st->rowCount()]);
    }
    catch (Throwable $e)
    {
        log_err($e); http_response_code(422);
        json_out(['ok' => false, 'error' => 'Не удалось удалить (детали в логе)']);
    }
}

# Массовая смена группы у выбранных профилей
function set_group_bulk(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $pk = get_pk($table);
    if (count($pk) !== 1) json_out(['ok'=>false,'error'=>'операция доступна только для таблицы с одиночным ключом']);
    $col = group_col($table);
    if (!$col) json_out(['ok'=>false,'error'=>'колонка группы не настроена']);
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || !$ids) json_out(['ok'=>false,'error'=>'не выбрано ни одной записи']);
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if (count($ids) > 5000) json_out(['ok'=>false,'error'=>'слишком много записей за один раз (макс. 5000)']);
    $group = trim((string)($_POST['group'] ?? ''));
    # ограничение длины по схеме колонки (если задано)
    foreach (get_columns($table) as $c)
    {
        if ($c['column_name'] === $col && $c['character_maximum_length'])
        {
            $max = (int)$c['character_maximum_length'];
            if (s_len($group) > $max) json_out(['ok'=>false,'error'=>"Название группы длиннее $max символов"]);
        }
    }
    try
    {
        $ph = []; $params = [];
        foreach ($ids as $i => $v) { $k = ':id'.$i; $ph[] = $k; $params[$k] = $v; }
        $set = ($group === '') ? qi($col) . ' = NULL' : qi($col) . ' = :grp';
        if ($group !== '') $params[':grp'] = $group;
        $st = db()->prepare("UPDATE " . qi($table) . " SET $set WHERE " . qi($pk[0]) . " IN (" . implode(',', $ph) . ")");
        $st->execute($params);
        json_out(['ok' => true, 'updated' => $st->rowCount()]);
    }
    catch (Throwable $e)
    {
        log_err($e); http_response_code(422);
        json_out(['ok' => false, 'error' => 'Не удалось сменить группу (детали в логе)']);
    }
}

# Сохранение настроек из панели → перезапись config.php
function save_settings(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $cur    = config_exists() ? require config_path() : ['db'=>[], 'app'=>app_defaults()];
    $app    = array_merge(app_defaults(), $cur['app'] ?? []);
    $db     = $cur['db'] ?? [];

    # app — не секретные строки
    foreach (['monstro_login','sort_dir'] as $k)
        if (isset($_POST[$k])) $app[$k] = trim((string)$_POST[$k]);
    unset($app['title'], $app['version']);   # имя и версия — константы APP_NAME/APP_VERSION, в конфиге не храним
    # app — числа
    foreach (['per_page','truncate_len','warm_days','warm_domains','chart_created_days'] as $k)
        if (isset($_POST[$k]) && $_POST[$k] !== '') $app[$k] = max(0, (int)$_POST[$k]);
    if (!in_array($app['sort_dir'], ['asc','desc'], true)) $app['sort_dir'] = 'desc';
    if ((int)$app['per_page'] < 1) $app['per_page'] = 50;

    # пароль входа — меняем только если введён новый (храним хэшем)
    $newpw = (string)($_POST['new_password'] ?? '');
    if ($newpw !== '') $app['password'] = password_hash($newpw, PASSWORD_DEFAULT);

    # db — реквизиты; пароль БД меняем только если введён
    foreach (['host','port','dbname','user','schema'] as $k)
        if (isset($_POST['db_'.$k])) $db[$k] = trim((string)$_POST['db_'.$k]);
    if (($_POST['db_pass'] ?? '') !== '') $db['pass'] = (string)$_POST['db_pass'];

    # не сохраняем заведомо нерабочее подключение
    if (($t = db_test($db)) !== null) json_out(['ok'=>false, 'error'=>'БД недоступна: '.$t]);

    if (write_config(['db'=>$db, 'app'=>$app])) json_out(['ok'=>true]);
    json_out(['ok'=>false, 'error'=>'Не удалось записать config.php — проверьте права на запись.']);
}

# Имя колонки-группы из конфига
function group_col(string $table): ?string
{
    return cfg()['app']['group_column'] ?? null;
}

# WHERE по первичному ключу из параметров запроса
function pk_where(string $table, array $pk): array
{
    $pkIn = $_REQUEST['pk'] ?? []; $parts = []; $params = [];
    foreach ($pk as $col)
    {
        if (!isset($pkIn[$col])) { http_response_code(400); json_out(['error' => "missing pk: $col"]); }
        $ph = ':pk_' . $col; $parts[] = qi($col) . ' = ' . $ph; $params[$ph] = $pkIn[$col];
    }
    return [implode(' AND ', $parts), $params];
}
