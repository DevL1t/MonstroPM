<?php

declare(strict_types=1);

# Планировщик: хранилище правил автопереноса групп (JSON) и движок применения.
# Условия (возраст в днях, кол-во доменов) используют тот же WHERE, что и фильтры таблицы.

# Путь к файлу правил
function rules_path(): string
{
    return __DIR__ . '/rules.json';
}

# Загрузка всего стора: { cron_key, rules: [], log: [] }
function rules_store(): array
{
    $def = ['cron_key' => '', 'rules' => [], 'log' => []];
    if (!is_file(rules_path())) return $def;
    $raw = @file_get_contents(rules_path());
    $j   = $raw ? json_decode($raw, true) : null;
    if (!is_array($j)) return $def;
    $j['cron_key'] = (string)($j['cron_key'] ?? '');
    $j['rules']    = isset($j['rules']) && is_array($j['rules']) ? array_values($j['rules']) : [];
    $j['log']      = isset($j['log'])   && is_array($j['log'])   ? array_values($j['log'])   : [];
    return $j;
}

# Добавить запись в журнал запусков (новые сверху, кап 200)
function rules_log_push(array &$store, array $entry): void
{
    if (!isset($store['log']) || !is_array($store['log'])) $store['log'] = [];
    array_unshift($store['log'], $entry);
    if (count($store['log']) > 200) $store['log'] = array_slice($store['log'], 0, 200);
}

# Атомарная запись стора
function rules_write(array $store): bool
{
    $tmp = rules_path() . '.tmp';
    $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, rules_path());
}

# Ключ для HTTP-вызова крона (генерируется при первом обращении)
function rules_cron_key(): string
{
    $s = rules_store();
    if ($s['cron_key'] === '')
    {
        $s['cron_key'] = bin2hex(random_bytes(16));
        rules_write($s);
    }
    return $s['cron_key'];
}

# Нормализация одного правила из входных данных (защита типов)
function rule_sanitize(array $in): array
{
    $num = function ($v)
    {
        $v = trim((string)$v);
        return ($v !== '' && ctype_digit($v)) ? (int)$v : null;
    };
    $name = trim((string)($in['name'] ?? ''));
    if ($name !== '') $name = function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80);
    return [
        'id'         => (is_string($in['id'] ?? null) && preg_match('/^[a-f0-9]{6,}$/', $in['id'])) ? $in['id'] : bin2hex(random_bytes(6)),
        'name'       => $name,
        'enabled'    => !empty($in['enabled']),
        'from_group' => trim((string)($in['from_group'] ?? '')),  # '' = любая, '__none__' = без группы
        'to_group'   => trim((string)($in['to_group'] ?? '')),
        'match'      => (($in['match'] ?? 'all') === 'any') ? 'any' : 'all',  # И / ИЛИ между условиями
        'age_on'     => !empty($in['age_on']),
        'dom_on'     => !empty($in['dom_on']),
        'grp_on'     => !empty($in['grp_on']),
        'age_min'    => $num($in['age_min'] ?? ''),
        'age_max'    => $num($in['age_max'] ?? ''),
        'dom_min'    => $num($in['dom_min'] ?? ''),
        'dom_max'    => $num($in['dom_max'] ?? ''),
        'grp_min'    => $num($in['grp_min'] ?? ''),  # дней в текущей группе
        'grp_max'    => $num($in['grp_max'] ?? ''),
        'created_at' => is_string($in['created_at'] ?? null) ? $in['created_at'] : date('c'),
        'last_run'   => $in['last_run']   ?? null,
        'last_moved' => isset($in['last_moved']) ? (int)$in['last_moved'] : null,
        'last_error' => $in['last_error'] ?? null,
    ];
}

# Сборка WHERE/params для условий правила (без SET). Бросает при ошибке конфигурации.
function rule_where(string $table, array $rule, array &$where, array &$params): string
{
    $g     = cfg()['app']['group_column'] ?? null;
    $names = column_names($table);
    if (!$g || !in_array($g, $names, true)) throw new RuntimeException('колонка группы не настроена');
    if (trim((string)$rule['to_group']) === '') throw new RuntimeException('не задана целевая группа');

    # исходная группа
    $from = (string)$rule['from_group'];
    if ($from === '__none__')   $where[] = '(' . qi($g) . " IS NULL OR " . qi($g) . " = '')";
    elseif ($from !== '')       { $where[] = qi($g) . ' = :from'; $params[':from'] = $from; }

    # включена ли группа условий (флаг отсутствует у старых правил → считаем включённой)
    $on = fn($k) => !array_key_exists($k, $rule) || !empty($rule[$k]);

    $groups = [];
    # возраст профиля в днях
    if ($on('age_on') && in_array('data_create', $names, true))
    {
        $s = [];
        if ($rule['age_min'] !== null) $s[] = qi('data_create') . " <= now() - interval '" . (int)$rule['age_min'] . " days'";
        if ($rule['age_max'] !== null) $s[] = qi('data_create') . " >= now() - interval '" . (int)$rule['age_max'] . " days'";
        if ($s) $groups[] = '(' . implode(' AND ', $s) . ')';
    }
    # количество доменов
    if ($on('dom_on') && in_array('domaincount', $names, true))
    {
        $s = [];
        if ($rule['dom_min'] !== null) $s[] = qi('domaincount') . ' >= ' . (int)$rule['dom_min'];
        if ($rule['dom_max'] !== null) $s[] = qi('domaincount') . ' <= ' . (int)$rule['dom_max'];
        if ($s) $groups[] = '(' . implode(' AND ', $s) . ')';
    }
    # сколько дней профиль в текущей группе (party_since ставит триггер БД при смене party)
    if ($on('grp_on') && in_array('party_since', $names, true))
    {
        $s = [];
        if ($rule['grp_min'] !== null) $s[] = qi('party_since') . " <= now() - interval '" . (int)$rule['grp_min'] . " days'";
        if ($rule['grp_max'] !== null) $s[] = qi('party_since') . " >= now() - interval '" . (int)$rule['grp_max'] . " days'";
        if ($s) $groups[] = '(' . implode(' AND ', $s) . ')';
    }

    # объединяем условия по И (all) или ИЛИ (any)
    if ($groups)
    {
        $op = (($rule['match'] ?? 'all') === 'any') ? ' OR ' : ' AND ';
        $where[] = (count($groups) > 1) ? '(' . implode($op, $groups) . ')' : $groups[0];
    }

    # не трогаем тех, кто уже в целевой группе (всегда AND)
    $where[] = qi($g) . ' IS DISTINCT FROM :tochk';
    $params[':tochk'] = $rule['to_group'];

    return $g;
}

# Сколько профилей подпадает под правило (без переноса)
function rule_count(PDO $pdo, string $table, array $rule): int
{
    $where = []; $params = [];
    rule_where($table, $rule, $where, $params);
    $sql = "SELECT count(*) FROM " . qi($table) . ' WHERE ' . implode(' AND ', $where);
    $st  = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

# Применить правило: перенести подходящие профили в целевую группу. Возвращает кол-во.
function rule_apply(PDO $pdo, string $table, array $rule): int
{
    $where = []; $params = [];
    $g = rule_where($table, $rule, $where, $params);
    $params[':toset'] = $rule['to_group'];
    $sql = "UPDATE " . qi($table) . ' SET ' . qi($g) . ' = :toset WHERE ' . implode(' AND ', $where);
    $st  = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

# Прогон всех включённых правил (для крона и кнопки «запустить все»). Пишет статус и журнал.
function rules_run_all(string $source = 'cron'): array
{
    $store = rules_store();
    $table = cfg()['app']['table'];
    $pdo   = db();
    $summary = [];
    $ran = 0; $movedTotal = 0; $errs = 0;
    foreach ($store['rules'] as &$r)
    {
        if (empty($r['enabled'])) continue;
        try
        {
            $n = rule_apply($pdo, $table, $r);
            $r['last_run'] = date('c'); $r['last_moved'] = $n; $r['last_error'] = null;
            $ran++; $movedTotal += $n;
            $summary[] = ['id' => $r['id'], 'name' => $r['name'], 'moved' => $n, 'ok' => true];
        }
        catch (Throwable $e)
        {
            log_err($e);
            $r['last_run'] = date('c'); $r['last_error'] = $e->getMessage();
            $errs++;
            $summary[] = ['id' => $r['id'], 'name' => $r['name'], 'ok' => false, 'error' => $e->getMessage()];
        }
    }
    unset($r);

    # Heartbeat-запись о прогоне (даже если правил нет / перенесено 0) — видно, что крон жив
    rules_log_push($store, [
        'time'   => date('c'),
        'source' => $source,
        'kind'   => 'run',
        'rules'  => $ran,
        'moved'  => $movedTotal,
        'errors' => $errs,
        'ok'     => ($errs === 0),
    ]);
    rules_write($store);
    return $summary;
}
