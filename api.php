<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/rules.php';

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
        case 'export':  export_data($table); break;
        case 'import':  import_data($table); break;
        case 'rules_list':    rules_list($table);    break;
        case 'rules_save':    rules_save($table);    break;
        case 'rules_delete':  rules_delete($table);  break;
        case 'rules_run':     rules_run($table);     break;
        case 'rules_preview': rules_preview($table); break;
        case 'rules_log_clear': rules_log_clear($table); break;
        case 'gtrack_status':  gtrack_status($table);  break;
        case 'gtrack_enable':  gtrack_enable($table);  break;
        case 'gtrack_disable': gtrack_disable($table); break;
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
    # Возраст профиля в днях: диапазон от/до. Возраст = now - data_create.
    # возраст >= min дней  ⟺  создан не позже чем min дней назад (data_create <= now - min)
    # возраст <= max дней  ⟺  создан не раньше чем max дней назад (data_create >= now - max)
    if (in_array('data_create', $names, true))
    {
        $aMin = $_GET['f_age_min'] ?? '';
        $aMax = $_GET['f_age_max'] ?? '';
        if ($aMin !== '' && ctype_digit((string)$aMin))
            $where[] = qi('data_create') . " <= now() - interval '" . (int)$aMin . " days'";
        if ($aMax !== '' && ctype_digit((string)$aMax))
            $where[] = qi('data_create') . " >= now() - interval '" . (int)$aMax . " days'";
    }

    # Кол-во доменов: диапазон от/до
    if (in_array('domaincount', $names, true))
    {
        $dMin = $_GET['f_dom_min'] ?? '';
        $dMax = $_GET['f_dom_max'] ?? '';
        if ($dMin !== '' && ctype_digit((string)$dMin))
            $where[] = qi('domaincount') . ' >= ' . (int)$dMin;
        if ($dMax !== '' && ctype_digit((string)$dMax))
            $where[] = qi('domaincount') . ' <= ' . (int)$dMax;
    }

    # Дней в текущей группе (party_since ставит триггер БД при смене группы)
    if (in_array('party_since', $names, true))
    {
        $gMin = $_GET['f_grp_min'] ?? '';
        $gMax = $_GET['f_grp_max'] ?? '';
        if ($gMin !== '' && ctype_digit((string)$gMin))
            $where[] = qi('party_since') . " <= now() - interval '" . (int)$gMin . " days'";
        if ($gMax !== '' && ctype_digit((string)$gMax))
            $where[] = qi('party_since') . " >= now() - interval '" . (int)$gMax . " days'";
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

    # scope=filter — удалить ВСЕ записи по текущему фильтру (без перечисления ID, одним запросом)
    if (($_POST['scope'] ?? '') === 'filter')
    {
        try
        {
            $where = []; $params = [];
            build_filters($table, $where, $params);
            $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
            $st = db()->prepare("DELETE FROM " . qi($table) . $whereSql);
            $st->execute($params);
            json_out(['ok' => true, 'deleted' => $st->rowCount()]);
        }
        catch (Throwable $e)
        {
            log_err($e); http_response_code(422);
            json_out(['ok' => false, 'error' => 'Не удалось удалить (детали в логе)']);
        }
        return;
    }

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

    $scope = ($_POST['scope'] ?? '') === 'filter' ? 'filter' : 'ids';
    # целевая группа — отдельный параметр to_group, чтобы не путать с фильтром group в build_filters
    $group = trim((string)($_POST['to_group'] ?? ''));

    # ограничение длины по схеме колонки (если задано)
    foreach (get_columns($table) as $c)
    {
        if ($c['column_name'] === $col && $c['character_maximum_length'])
        {
            $max = (int)$c['character_maximum_length'];
            if (s_len($group) > $max) json_out(['ok'=>false,'error'=>"Название группы длиннее $max символов"]);
        }
    }

    # scope=filter — перенести ВСЕ записи по текущему фильтру одним запросом
    if ($scope === 'filter')
    {
        try
        {
            $where = []; $params = [];
            build_filters($table, $where, $params);
            $set = ($group === '') ? qi($col) . ' = NULL' : qi($col) . ' = :grp';
            if ($group !== '') $params[':grp'] = $group;
            $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
            $st = db()->prepare("UPDATE " . qi($table) . " SET $set" . $whereSql);
            $st->execute($params);
            json_out(['ok' => true, 'updated' => $st->rowCount()]);
        }
        catch (Throwable $e)
        {
            log_err($e); http_response_code(422);
            json_out(['ok' => false, 'error' => 'Не удалось сменить группу (детали в логе)']);
        }
        return;
    }

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || !$ids) json_out(['ok'=>false,'error'=>'не выбрано ни одной записи']);
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if (count($ids) > 5000) json_out(['ok'=>false,'error'=>'слишком много записей за один раз (макс. 5000)']);
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

# Экспорт профилей — SQL-дамп или CSV, потоково (scope: all|filter|ids)
function export_data(string $table): void
{
    $format = ($_GET['format'] ?? 'sql') === 'csv' ? 'csv' : 'sql';
    $scope  = $_GET['scope'] ?? 'all';
    $names  = column_names($table);
    $pk     = get_pk($table);
    $pdo    = db();

    # bool-колонки выгружаем как true/false (PDO отдаёт их как '1'/'', а '' в bool не импортнётся)
    $boolCols = [];
    foreach (get_columns($table) as $c) if ($c['is_bool']) $boolCols[] = $c['column_name'];

    # WHERE по охвату; значения инлайним безопасно — серверный курсор не принимает плейсхолдеры
    $where = [];
    if ($scope === 'filter')
    {
        $params = [];
        build_filters($table, $where, $params);
        $sql = $where ? implode(' AND ', $where) : '';
        foreach ($params as $k => $v) $sql = str_replace($k, $pdo->quote((string)$v), $sql);
        $where = $sql !== '' ? [$sql] : [];
    }
    elseif ($scope === 'ids')
    {
        $ids = array_values(array_unique(array_map('strval', (array)($_GET['ids'] ?? []))));
        if ($pk && $ids)
        {
            $lst = array_map(fn($v) => ctype_digit($v) ? $v : $pdo->quote($v), $ids);
            $where[] = qi($pk[0]) . ' IN (' . implode(',', $lst) . ')';
        }
        else $where[] = '1=0';
    }
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    $fname = $table . '_' . date('Ymd_His') . '.' . $format;
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=utf-8' : 'application/sql; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . $fname . '"');

    $selSql  = implode(', ', array_map('qi', $names));
    $colList = '(' . implode(', ', array_map('qi', $names)) . ')';
    $onConf  = $pk ? (' ON CONFLICT (' . qi($pk[0]) . ') DO NOTHING') : '';

    $fp = null;
    if ($format === 'sql')
    {
        echo "-- Monstro Profile Manager — экспорт «" . $table . "» (" . date('Y-m-d H:i') . ")\n\n";
    }
    else
    {
        $fp = fopen('php://output', 'w');
        fputcsv($fp, $names);
    }

    # потоковая выгрузка курсором — тысячи тяжёлых строк нельзя держать в памяти
    $pdo->beginTransaction();
    $pdo->exec("DECLARE mpm_exp NO SCROLL CURSOR FOR SELECT $selSql FROM " . qi($table) . $whereSql);
    while (true)
    {
        $rows = $pdo->query("FETCH 500 FROM mpm_exp")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) break;
        if ($format === 'sql')
        {
            $vals = [];
            foreach ($rows as $r)
            {
                $cells = [];
                foreach ($names as $n)
                {
                    $v = $r[$n];
                    if ($v === null) $cells[] = 'NULL';
                    elseif (in_array($n, $boolCols, true)) $cells[] = ($v === '1' || $v === 't' || $v === true) ? 'true' : 'false';
                    else $cells[] = $pdo->quote((string)$v);
                }
                $vals[] = '(' . implode(',', $cells) . ')';
            }
            echo "INSERT INTO " . qi($table) . " $colList VALUES\n" . implode(",\n", $vals) . $onConf . ";\n";
        }
        else
        {
            foreach ($rows as $r)
            {
                $line = [];
                foreach ($names as $n)
                {
                    $v = $r[$n];
                    if ($v !== null && in_array($n, $boolCols, true)) $v = ($v === '1' || $v === 't' || $v === true) ? 't' : 'f';
                    $line[] = $v;
                }
                fputcsv($fp, $line);
            }
        }
        flush();
    }
    $pdo->exec("CLOSE mpm_exp");
    $pdo->commit();

    # Футер дампа: после вставки явных PID секвенцию identity-ключа надо подтянуть
    # к max(pk) на целевом сервере, иначе софт ловит конфликт PID при создании профилей.
    if ($format === 'sql' && $pk)
    {
        $pkc = $pk[0];
        echo "\n-- Синхронизация секвенции identity-ключа (без неё софт не создаст новые профили)\n";
        echo "DO \$\$\nDECLARE s text;\nBEGIN\n"
           . "  s := pg_get_serial_sequence(" . $pdo->quote($table) . ", " . $pdo->quote($pkc) . ");\n"
           . "  IF s IS NOT NULL THEN\n"
           . "    PERFORM setval(s, (SELECT COALESCE(MAX(" . qi($pkc) . "), 1) FROM " . qi($table) . "), true);\n"
           . "  END IF;\nEND \$\$;\n";
    }
    exit;
}

# Импорт профилей из CSV (выгруженного панелью). mode: skip|overwrite|new
function import_data(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $pk = get_pk($table);
    if (count($pk) !== 1) json_out(['ok'=>false,'error'=>'импорт доступен только для таблицы с одиночным ключом']);

    # пустые $_POST и $_FILES при ненулевом теле запроса = файл больше post_max_size (PHP молча отбросил)
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES)
    {
        json_out(['ok'=>false, 'error'=>'Файл больше post_max_size (' . ini_get('post_max_size')
            . '). Поднимите лимиты в php.ini, импортируйте меньшими частями (по фильтру/выбранным) или залейте SQL-дамп через psql.']);
    }
    # понятные сообщения по кодам ошибок загрузки
    $uerr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!isset($_FILES['file']) || $uerr !== UPLOAD_ERR_OK)
    {
        $msg = [
            UPLOAD_ERR_INI_SIZE   => 'файл больше upload_max_filesize (' . ini_get('upload_max_filesize') . ') — поднимите лимит или импортируйте частями',
            UPLOAD_ERR_FORM_SIZE  => 'файл слишком большой',
            UPLOAD_ERR_PARTIAL    => 'файл загрузился частично — попробуйте ещё раз',
            UPLOAD_ERR_NO_FILE    => 'файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'нет временной папки для загрузки (настройка сервера)',
            UPLOAD_ERR_CANT_WRITE => 'не удалось записать загруженный файл (права сервера)',
        ];
        json_out(['ok'=>false, 'error'=>$msg[$uerr] ?? 'файл не загружен']);
    }
    $mode  = in_array($_POST['mode'] ?? '', ['skip','overwrite','new'], true) ? $_POST['mode'] : 'skip';
    $pkcol = $pk[0];
    $cols  = column_names($table);

    $fp = fopen($_FILES['file']['tmp_name'], 'r');
    if (!$fp) json_out(['ok'=>false,'error'=>'не удалось прочитать файл']);
    $header = fgetcsv($fp);
    if (!$header) { fclose($fp); json_out(['ok'=>false,'error'=>'пустой CSV']); }

    # позиция в строке → имя колонки (только существующие в таблице)
    $useIdx = [];
    foreach ($header as $i => $h) { $h = trim((string)$h); if (in_array($h, $cols, true)) $useIdx[$i] = $h; }
    if (!$useIdx) { fclose($fp); json_out(['ok'=>false,'error'=>'в файле нет колонок, совпадающих с таблицей']); }

    $nextId = ($mode === 'new')
        ? (int)db()->query("SELECT COALESCE(MAX(" . qi($pkcol) . "),0)+1 FROM " . qi($table))->fetchColumn()
        : null;

    # Список вставляемых колонок фиксирован заголовком CSV (+ pk в режиме new)
    $colList = array_values($useIdx);
    if ($mode === 'new' && !in_array($pkcol, $colList, true)) $colList[] = $pkcol;

    # ON CONFLICT собираем один раз
    if ($mode === 'overwrite')
    {
        $upd = [];
        foreach ($colList as $c) if ($c !== $pkcol) $upd[] = qi($c) . ' = EXCLUDED.' . qi($c);
        $conflict = $upd ? ' ON CONFLICT (' . qi($pkcol) . ') DO UPDATE SET ' . implode(', ', $upd)
                         : ' ON CONFLICT (' . qi($pkcol) . ') DO NOTHING';
    }
    else
    {
        $conflict = ' ON CONFLICT (' . qi($pkcol) . ') DO NOTHING';
    }

    $colSql = implode(', ', array_map('qi', $colList));
    $insSql = "INSERT INTO " . qi($table) . " ($colSql) VALUES ";
    $BATCH  = 200;

    $imported = 0; $skipped = 0; $bad = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try
    {
        $buf = []; # накопленные строки (массивы значений в порядке $colList)

        # Сброс пакета одним многострочным INSERT
        $flush = function() use (&$buf, &$imported, &$skipped, $pdo, $insSql, $colList, $conflict)
        {
            if (!$buf) return;
            $groups = []; $params = []; $n = 0;
            foreach ($buf as $vals)
            {
                $phs = [];
                foreach ($colList as $j => $c) { $k = ':v' . $n . '_' . $j; $phs[] = $k; $params[$k] = $vals[$j]; }
                $groups[] = '(' . implode(', ', $phs) . ')';
                $n++;
            }
            $st = $pdo->prepare($insSql . implode(', ', $groups) . $conflict);
            $st->execute($params);
            $aff = $st->rowCount();
            $imported += $aff;
            $skipped  += count($buf) - $aff;
            $buf = [];
        };

        while (($row = fgetcsv($fp)) !== false)
        {
            if (count($row) === 1 && ($row[0] === null || $row[0] === '')) continue;
            $data = [];
            foreach ($useIdx as $i => $name) { $v = $row[$i] ?? null; $data[$name] = ($v === '') ? null : $v; }

            if ($mode === 'new') $data[$pkcol] = $nextId++;
            elseif (!isset($data[$pkcol]) || $data[$pkcol] === null) { $bad++; continue; }

            $vals = [];
            foreach ($colList as $c) $vals[] = $data[$c] ?? null;
            $buf[] = $vals;

            if (count($buf) >= $BATCH) $flush();
        }
        $flush();
        $pdo->commit();
    }
    catch (Throwable $e)
    {
        $pdo->rollBack();
        log_err($e);
        fclose($fp);
        json_out(['ok'=>false,'error'=>'Ошибка импорта (детали в логе)']);
    }
    fclose($fp);

    # Синхронизируем секвенцию identity/serial-ключа с max(pk).
    # Иначе при вставке явных PID Postgres не двигает секвенцию,
    # и софт (Monstro) ловит конфликт PID при создании новых профилей.
    $seqSynced = false;
    try
    {
        $seq = $pdo->query("SELECT pg_get_serial_sequence(" . $pdo->quote($table) . ", " . $pdo->quote($pkcol) . ")")->fetchColumn();
        if ($seq)
        {
            $pdo->query("SELECT setval(" . $pdo->quote($seq) . ", (SELECT COALESCE(MAX(" . qi($pkcol) . "), 1) FROM " . qi($table) . "), true)");
            $seqSynced = true;
        }
    }
    catch (Throwable $e) { log_err($e); }

    json_out(['ok'=>true, 'imported'=>$imported, 'skipped'=>$skipped, 'bad'=>$bad, 'mode'=>$mode, 'seq_synced'=>$seqSynced]);
}

# Имя колонки-группы из конфига
function group_col(string $table): ?string
{
    return cfg()['app']['group_column'] ?? null;
}

# Планировщик: список правил + данные для UI (ключ крона, базовый URL)
function rules_list(string $table): void
{
    $store = rules_store();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    json_out([
        'ok'       => true,
        'rules'    => $store['rules'],
        'log'      => array_slice($store['log'] ?? [], 0, 100),
        'cron_key' => rules_cron_key(),
        'cron_url' => "$scheme://$host$dir/cron.php?key=" . rules_cron_key(),
    ]);
}

# Планировщик: создать/обновить правило (upsert по id)
function rules_save(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $rule = rule_sanitize($_POST);
    if ($rule['to_group'] === '') json_out(['ok'=>false,'error'=>'Укажите целевую группу']);
    if ($rule['name'] === '') $rule['name'] = ($rule['from_group'] !== '' ? $rule['from_group'] : 'любая') . ' → ' . $rule['to_group'];

    $store = rules_store();
    $found = false;
    foreach ($store['rules'] as &$r)
    {
        if (($r['id'] ?? '') === $rule['id'])
        {
            # сохраняем историю прогона при редактировании
            $rule['last_run']   = $r['last_run']   ?? null;
            $rule['last_moved'] = $r['last_moved'] ?? null;
            $rule['created_at'] = $r['created_at'] ?? $rule['created_at'];
            $r = $rule; $found = true; break;
        }
    }
    unset($r);
    if (!$found) $store['rules'][] = $rule;
    if (!rules_write($store)) json_out(['ok'=>false,'error'=>'Не удалось сохранить (нет прав на запись rules.json?)']);
    json_out(['ok'=>true, 'rule'=>$rule]);
}

# Планировщик: удалить правило по id
function rules_delete(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $id = (string)($_POST['id'] ?? '');
    $store = rules_store();
    $before = count($store['rules']);
    $store['rules'] = array_values(array_filter($store['rules'], fn($r) => ($r['id'] ?? '') !== $id));
    if (count($store['rules']) === $before) json_out(['ok'=>false,'error'=>'правило не найдено']);
    if (!rules_write($store)) json_out(['ok'=>false,'error'=>'Не удалось сохранить']);
    json_out(['ok'=>true]);
}

# Планировщик: запустить одно правило (id) или все (all=1) вручную
function rules_run(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    if (!empty($_POST['all']))
    {
        $summary = rules_run_all('manual');
        $total = array_sum(array_map(fn($s) => $s['moved'] ?? 0, $summary));
        json_out(['ok'=>true, 'moved_total'=>$total, 'details'=>$summary]);
    }
    $id = (string)($_POST['id'] ?? '');
    $store = rules_store();
    foreach ($store['rules'] as &$r)
    {
        if (($r['id'] ?? '') === $id)
        {
            try
            {
                $n = rule_apply(db(), $table, $r);
                $r['last_run'] = date('c'); $r['last_moved'] = $n; $r['last_error'] = null;
                rules_log_push($store, ['time'=>date('c'),'kind'=>'rule','name'=>$r['name'],'moved'=>$n,'source'=>'manual','ok'=>true]);
                rules_write($store);
                json_out(['ok'=>true, 'moved'=>$n]);
            }
            catch (Throwable $e)
            {
                log_err($e);
                $r['last_run'] = date('c'); $r['last_error'] = $e->getMessage();
                rules_log_push($store, ['time'=>date('c'),'kind'=>'rule','name'=>$r['name'],'source'=>'manual','ok'=>false,'error'=>$e->getMessage()]);
                rules_write($store);
                json_out(['ok'=>false, 'error'=>$e->getMessage()]);
            }
        }
    }
    unset($r);
    json_out(['ok'=>false, 'error'=>'правило не найдено']);
}

# Планировщик: очистить журнал запусков
function rules_log_clear(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $store = rules_store();
    $store['log'] = [];
    rules_write($store);
    json_out(['ok'=>true]);
}

# Отслеживание групп: проверка наличия колонки party_since и триггера в БД
function gtrack_status(string $table): void
{
    try
    {
        $pdo = db();
        $c = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = 'party_since'");
        $c->execute([$table]);
        $hasCol = (bool)$c->fetchColumn();
        $hasTrg = (bool)$pdo->query("SELECT 1 FROM pg_trigger WHERE tgname = 'mpm_party_since_trg' AND NOT tgisinternal")->fetchColumn();
        json_out(['ok'=>true, 'column'=>$hasCol, 'trigger'=>$hasTrg, 'enabled'=>($hasCol && $hasTrg)]);
    }
    catch (Throwable $e) { log_err($e); json_out(['ok'=>false, 'error'=>$e->getMessage()]); }
}

# Отслеживание групп: включить (колонка + бэкфилл + триггер). Безопасно для Monstro.
function gtrack_enable(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $g = group_col($table);
    if (!$g) json_out(['ok'=>false, 'error'=>'колонка группы не настроена']);
    $names = column_names($table);
    if (!in_array($g, $names, true)) json_out(['ok'=>false, 'error'=>"в таблице нет колонки группы «$g»"]);
    $backfill = in_array('data_create', $names, true) ? qi('data_create') : 'now()';
    $t  = qi($table);
    $gc = qi($g);
    try
    {
        $pdo = db();
        $pdo->exec("ALTER TABLE $t ADD COLUMN IF NOT EXISTS party_since timestamptz");
        $pdo->exec("UPDATE $t SET party_since = $backfill WHERE party_since IS NULL");
        # триггер следит за колонкой группы; EXECUTE PROCEDURE — совместимо со старыми PG
        $pdo->exec(
            "CREATE OR REPLACE FUNCTION mpm_party_since() RETURNS trigger AS \$mpm\$\n" .
            "BEGIN\n" .
            "  IF TG_OP = 'INSERT' THEN\n" .
            "    IF NEW.party_since IS NULL THEN NEW.party_since := now(); END IF;\n" .
            "  ELSIF NEW.$gc IS DISTINCT FROM OLD.$gc THEN\n" .
            "    NEW.party_since := now();\n" .
            "  END IF;\n" .
            "  RETURN NEW;\n" .
            "END;\n\$mpm\$ LANGUAGE plpgsql"
        );
        $pdo->exec("DROP TRIGGER IF EXISTS mpm_party_since_trg ON $t");
        $pdo->exec("CREATE TRIGGER mpm_party_since_trg BEFORE INSERT OR UPDATE ON $t FOR EACH ROW EXECUTE PROCEDURE mpm_party_since()");
        schema_flush();  # чтобы новая колонка сразу появилась в фильтрах/правилах
        json_out(['ok'=>true]);
    }
    catch (Throwable $e)
    {
        log_err($e);
        json_out(['ok'=>false, 'error'=>'Не удалось включить: ' . $e->getMessage()]);
    }
}

# Отслеживание групп: отключить (снимаем триггер, функцию и колонку — полностью)
function gtrack_disable(string $table): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_out(['error'=>'POST only']); }
    $t = qi($table);
    try
    {
        $pdo = db();
        $pdo->exec("DROP TRIGGER IF EXISTS mpm_party_since_trg ON $t");
        $pdo->exec("DROP FUNCTION IF EXISTS mpm_party_since()");
        $pdo->exec("ALTER TABLE $t DROP COLUMN IF EXISTS party_since");
        schema_flush();
        json_out(['ok'=>true]);
    }
    catch (Throwable $e) { log_err($e); json_out(['ok'=>false, 'error'=>$e->getMessage()]); }
}

# Планировщик: сколько профилей подпадёт под условия (живой предпросмотр в форме)
function rules_preview(string $table): void
{
    try
    {
        $rule = rule_sanitize($_REQUEST);
        if ($rule['to_group'] === '') { json_out(['ok'=>true, 'count'=>null]); }
        $n = rule_count(db(), $table, $rule);
        json_out(['ok'=>true, 'count'=>$n]);
    }
    catch (Throwable $e)
    {
        json_out(['ok'=>false, 'error'=>$e->getMessage()]);
    }
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
