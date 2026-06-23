<?php
# =====================================================================
#  lib.php — БД, интроспекция, авторизация, хелперы
# =====================================================================
declare(strict_types=1);

const APP_NAME    = 'Monstro Profile Manager';   # имя продукта — фиксированное, не настройка
const APP_VERSION = '1.2.0';                     # версия — свойство кода, не конфига (меняется при релизе)

# Полифил array_is_list() для PHP 8.0
if (!function_exists('array_is_list'))
{
    function array_is_list(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}

# Лого-локап бренда (login/setup)
function brand_logo(): string
{
    return '<div class="login-logo">'
         . '<img class="login-logo-ic" src="' . asset('assets/logo.png') . '" alt="">'
         . '<i>MONSTRO</i>Profile <span>Manager</span></div>';
}

function config_path(): string { return __DIR__ . '/config.php'; }
function config_exists(): bool { return is_file(config_path()); }

# Дефолтные (НЕ секретные) настройки приложения
function app_defaults(): array
{
    return [
        'password'      => '',
        'monstro_login' => '',
        'table'         => 'profiles',
        'group_column'  => 'party',
        'sort_column'   => 'data_create',
        'sort_dir'      => 'desc',
        'per_page'      => 50,
        'truncate_len'  => 80,
        'warm_days'     => 10,
        'warm_domains'  => 10,
        'chart_created_days' => 14,
        'age_buckets'   => [
            ['label' => '< 24ч',     'days' => 1],
            ['label' => '1–3 дня',   'days' => 3],
            ['label' => '3–7 дней',  'days' => 7],
            ['label' => '7–30 дней', 'days' => 30],
            ['label' => '> 30 дней', 'days' => null],
        ],
    ];
}

# Конфиг приложения (кэш; app поверх дефолтов)
function cfg(): array
{
    static $c = null;
    if ($c === null)
    {
        if (config_exists())
        {
            $loaded = require config_path();
            # мерджим app поверх дефолтов — новые ключи настроек работают и со старым конфигом
            $c = [
                'db'  => array_merge(['schema' => 'public'], $loaded['db'] ?? []),  # schema по умолчанию public
                'app' => array_merge(app_defaults(), $loaded['app'] ?? []),
            ];
        }
        else
        {
            $c = ['db' => [], 'app' => app_defaults()];
        }
    }
    return $c;
}

# Экспорт значения в PHP с короткими массивами [] и аккуратными отступами
function php_export($v, int $indent = 1): string
{
    if (is_array($v))
    {
        if ($v === []) return '[]';
        $pad = str_repeat('    ', $indent);
        $padEnd = str_repeat('    ', $indent - 1);
        $list = array_is_list($v);
        $out = "[\n";
        foreach ($v as $k => $vv)
        {
            $key = $list ? '' : var_export($k, true) . ' => ';
            $out .= $pad . $key . php_export($vv, $indent + 1) . ",\n";
        }
        return $out . $padEnd . ']';
    }
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null)  return 'null';
    if (is_int($v) || is_float($v)) return (string)$v;
    return var_export($v, true);   # строки — с корректным экранированием кавычек/спецсимволов
}

# Записать конфиг обратно в config.php
function write_config(array $cfg): bool
{
    $php = "<?php\n"
         . "return " . php_export($cfg) . ";\n";
    $tmp = config_path() . '.tmp';
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) return false;
    return @rename($tmp, config_path());  # атомарная замена
}

# Проверить подключение к БД с заданными параметрами
function db_test(array $d): ?string
{
    try
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=6',
            $d['host'] ?? '', $d['port'] ?? '5432', $d['dbname'] ?? '');
        $pdo = new PDO($dsn, $d['user'] ?? '', $d['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query('SELECT 1');
        return null;
    }
    catch (Throwable $e)
    {
        return $e->getMessage();
    }
}

# Для страниц: если конфига ещё нет
function require_config_or_setup(): void
{
    if (!config_exists()) { header('Location: setup.php'); exit; }
}

# Длина/обрезка строки
function s_len(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }
function s_cut(string $s, int $n): string { return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }

# Проверка окружения для работы скрипта
function system_checks(): array
{
    $pgsql = extension_loaded('pdo_pgsql');
    $writable = is_writable(__DIR__);
    return [
        ['label' => 'PHP ≥ 8.0',            'ok' => PHP_VERSION_ID >= 80000, 'critical' => true,
         'detail' => 'текущая ' . PHP_VERSION],
        ['label' => 'Расширение pdo_pgsql', 'ok' => $pgsql,                  'critical' => true,
         'detail' => $pgsql ? 'включено' : 'добавьте extension=pdo_pgsql в php.ini'],
        ['label' => 'Папка доступна для записи', 'ok' => $writable,         'critical' => true,
         'detail' => $writable ? 'ок' : 'нет прав на запись'],
    ];
}

# URL ассета с анти-кэш меткой по времени изменения файла
function asset(string $rel): string
{
    $rel = ltrim($rel, '/');
    $m = @filemtime(__DIR__ . '/' . $rel);
    return $rel . ($m ? '?v=' . $m : '');
}

# Лог ошибок
function log_err(Throwable $e): void
{
    $f = sys_get_temp_dir() . '/mpm_error.log';
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . "\n", FILE_APPEND);
}

# БД (бросает RuntimeException при неудаче — без вывода; обрабатывают вызывающие)
function db(): PDO
{
    static $pdo = null;
    static $failed = false;
    if ($pdo !== null) return $pdo;
    if ($failed) throw new RuntimeException('Нет подключения к БД');
    $d = cfg()['db'];
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=8', $d['host'] ?? '', $d['port'] ?? '', $d['dbname'] ?? '');
    try
    {
        $pdo = new PDO($dsn, $d['user'] ?? '', $d['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            # НЕ используем persistent: на общей с Monstro БД (лимит max_connections)
            # постоянные коннекты висят в idle по воркерам FPM и выжирают слоты.
            PDO::ATTR_PERSISTENT         => false,
        ]);
        $pdo->exec("SET search_path TO " . qi($d['schema'] ?? 'public'));
    }
    catch (Throwable $e)
    {
        log_err($e);
        $failed = true;
        throw new RuntimeException('Нет подключения к БД');
    }
    return $pdo;
}

# Доступна ли БД (проверка без падения)
function db_ok(): bool
{
    try { db(); return true; }
    catch (Throwable $e) { return false; }
}

function qi(string $ident): string { return '"' . str_replace('"', '""', $ident) . '"'; }

# Кэш схемы (статична) в файле
function schema_all(): array
{
    static $mem = null;
    if ($mem !== null) return $mem;
    $d = cfg()['db'];
    $f = sys_get_temp_dir() . '/mpm_schema_' . md5($d['host'].$d['port'].$d['dbname'].$d['schema']) . '.json';
    if (is_file($f) && (time() - filemtime($f) < 600))
    {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && isset($j['tables'])) return $mem = $j;
    }
    $mem = ['tables' => _db_tables(), 'columns' => [], 'pk' => []];
    foreach ($mem['tables'] as $t)
    {
        $tn = $t['table_name'];
        $mem['pk'][$tn]      = _db_pk($tn);
        $mem['columns'][$tn] = _db_columns($tn, $mem['pk'][$tn]);
    }
    @file_put_contents($f, json_encode($mem, JSON_UNESCAPED_UNICODE));
    return $mem;
}

# Принудительно сбросить кэш схемы
function schema_flush(): void
{
    $d = cfg()['db'];
    @unlink(sys_get_temp_dir() . '/mpm_schema_' . md5($d['host'].$d['port'].$d['dbname'].$d['schema']) . '.json');
}

function get_tables(): array  { return schema_all()['tables']; }
function get_columns(string $table): array { return schema_all()['columns'][$table] ?? []; }
function get_pk(string $table): array      { return schema_all()['pk'][$table] ?? []; }

#  Общее число строк таблицы с коротким файловым кэшем
function table_count(string $table, int $ttl = 60): int
{
    $d = cfg()['db'];
    $f = sys_get_temp_dir() . '/mpm_cnt_' . md5($d['host'] . $d['dbname'] . $table) . '.txt';
    if (is_file($f) && (time() - filemtime($f) < $ttl))
    {
        $v = trim((string)@file_get_contents($f));
        if ($v !== '' && ctype_digit($v)) return (int)$v;
    }
    $n = (int)db()->query("SELECT count(*) FROM " . qi($table))->fetchColumn();
    @file_put_contents($f, (string)$n);
    return $n;
}

# «Cырые» построители (ходят в БД)
function _db_tables(): array
{
    $schema = cfg()['db']['schema'];
    $st = db()->prepare(
        "SELECT t.table_name,
                COALESCE(c.reltuples::bigint, 0) AS approx_rows
           FROM information_schema.tables t
           LEFT JOIN pg_class c ON c.relname = t.table_name
           LEFT JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = t.table_schema
          WHERE t.table_schema = :s AND t.table_type = 'BASE TABLE'
          ORDER BY t.table_name");
    $st->execute([':s' => $schema]);
    return $st->fetchAll();
}

# Колонки таблицы + вычисленные флаги типов
function _db_columns(string $table, array $pk): array
{
    $schema = cfg()['db']['schema'];
    $st = db()->prepare(
        "SELECT column_name, data_type, udt_name, is_nullable,
                column_default, character_maximum_length
           FROM information_schema.columns
          WHERE table_schema = :s AND table_name = :t
          ORDER BY ordinal_position");
    $st->execute([':s' => $schema, ':t' => $table]);
    $cols = $st->fetchAll();
    foreach ($cols as &$c)
    {
        $c['is_pk']      = in_array($c['column_name'], $pk, true);
        $c['is_bool']    = ($c['udt_name'] === 'bool');
        $c['is_numeric'] = in_array($c['udt_name'], ['int2','int4','int8','float4','float8','numeric'], true);
        $c['is_ts']      = (strpos($c['udt_name'], 'timestamp') === 0 || $c['udt_name'] === 'date');
        $len = $c['character_maximum_length'];
        $c['is_long']    = ($c['data_type'] === 'text' || $c['data_type'] === 'json' || $c['data_type'] === 'jsonb'
                            || ($len !== null && (int)$len > 120));
        $c['is_auto']    = ($c['column_default'] !== null && strpos((string)$c['column_default'], 'nextval(') !== false);
    }
    unset($c);
    return $cols;
}

# Колонки первичного ключа
function _db_pk(string $table): array
{
    $schema = cfg()['db']['schema'];
    $st = db()->prepare(
        "SELECT a.attname FROM pg_index i
           JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
          WHERE i.indrelid = (:rel)::regclass AND i.indisprimary");
    $st->execute([':rel' => qi($schema) . '.' . qi($table)]);
    return array_column($st->fetchAll(), 'attname');
}

# Есть ли колонка в таблице
function has_column(string $table, string $col): bool
{
    return in_array($col, column_names($table), true);
}

# Имена колонок таблицы
function column_names(string $table): array
{
    return array_column(get_columns($table), 'column_name');
}

# Проверка существования таблицы (иначе 404)
function assert_table(string $table): void
{
    foreach (get_tables() as $t) if ($t['table_name'] === $table) return;
    http_response_code(404);
    die('Нет такой таблицы: ' . htmlspecialchars($table));
}

# Отдать JSON и завершить
function json_out($data): void
{
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

# Авторизация
function auth_boot(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE)
    {
        # безопасные флаги cookie сессии
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') == 443)
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,      # недоступна из JS
            'secure'   => $https,    # только по HTTPS
            'samesite' => 'Lax',     # защита от CSRF с других сайтов
        ]);
        session_name('monstro_adm');
        session_start();
    }
}

# Авторизован ли текущий запрос
function is_authed(): bool
{
    auth_boot();
    return !empty($_SESSION['authed']);
}

# Защита от перебора: файл попыток по IP
function auth_lock_file(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    return sys_get_temp_dir() . '/mpm_login_' . md5($ip) . '.json';
}

# Состояние блокировки входа по IP
function auth_lock_state(): array
{
    $f = auth_lock_file();
    if (is_file($f)) { $j = json_decode((string)@file_get_contents($f), true); if (is_array($j)) return $j; }
    return ['fails' => 0, 'until' => 0];
}

# Заблокирован ли вход сейчас
function auth_locked(): bool
{
    $s = auth_lock_state();
    return ($s['until'] ?? 0) > time();
}

# Засчитать неудачную попытку (5 → лок 10 мин)
function auth_register_fail(): void
{
    $s = auth_lock_state();
    $s['fails'] = ($s['fails'] ?? 0) + 1;
    if ($s['fails'] >= 5) { $s['until'] = time() + 600; $s['fails'] = 0; } # лок на 10 мин
    @file_put_contents(auth_lock_file(), json_encode($s));
}
function auth_reset_fails(): void { @unlink(auth_lock_file()); }

# Проверка пароля и вход (хэш или открытый текст)
function auth_login(string $password): bool
{
    if (auth_locked()) return false;
    $real = (string)cfg()['app']['password'];
    if ($real === '') return false;
    # поддержка bcrypt/argon-хэша ($2y$… / $argon…) ИЛИ открытого текста
    $isHash = (strncmp($real, '$2y$', 4) === 0 || strncmp($real, '$2a$', 4) === 0 || strncmp($real, '$argon', 6) === 0);
    $ok = $isHash ? password_verify($password, $real) : hash_equals($real, $password);
    if ($ok)
    {
        auth_reset_fails();
        auth_boot();
        session_regenerate_id(true);
        $_SESSION['authed'] = true;
        return true;
    }
    auth_register_fail();
    return false;
}

# Выход: уничтожить сессию
function auth_logout(): void
{
    auth_boot();
    $_SESSION = [];
    session_destroy();
}

# Защита страницы: редирект на логин
function require_auth_page(): void
{
    if (!is_authed()) { header('Location: login.php'); exit; }
}

# Защита API: 401 JSON
function require_auth_api(): void
{
    if (!is_authed()) { http_response_code(401); json_out(['error' => 'unauthorized']); }
}