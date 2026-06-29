<?php

declare(strict_types=1);

# Автообновление: проверка версии на GitHub и накатывание свежего кода из официального репо.
# Тянет только с APP_REPO (хардкод). config.php / rules.json не трогаются (их нет в git).

# HTTP GET (cURL → fallback file_get_contents). Возвращает строку/бинарь или null.
function upd_http_get(string $url, bool $binary = false)
{
    $to = $binary ? 90 : 15;
    if (function_exists('curl_init'))
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $to,
            CURLOPT_USERAGENT      => 'MonstroPM-Updater',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $d = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($d !== false && $code >= 200 && $code < 300) ? $d : null;
    }
    if (ini_get('allow_url_fopen'))
    {
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: MonstroPM-Updater\r\n", 'timeout' => $to]]);
        $d = @file_get_contents($url, false, $ctx);
        return $d === false ? null : $d;
    }
    return null;
}

# Путь к кэшу проверки версии
function upd_cache_file(): string { return sys_get_temp_dir() . '/mpm_update.json'; }

# Сбросить кэш проверки (после обновления)
function upd_clear_cache(): void { @unlink(upd_cache_file()); }

# Статус обновления (кэш 24ч). force=true — проверить сейчас.
function upd_status(bool $force = false): array
{
    $cf = upd_cache_file();
    if (!$force && is_file($cf) && (time() - filemtime($cf) < 86400))
    {
        $c = json_decode((string)@file_get_contents($cf), true);
        if (is_array($c)) { $c['cached'] = true; return $c; }
    }

    $current = APP_VERSION;
    $latest  = null; $err = null;
    $raw = upd_http_get('https://raw.githubusercontent.com/' . APP_REPO . '/main/lib.php');
    if ($raw !== null && preg_match("/APP_VERSION\\s*=\\s*'([0-9.]+)'/", $raw, $m)) $latest = $m[1];
    else $err = 'не удалось получить версию с GitHub';

    $res = [
        'current' => $current,
        'latest'  => $latest,
        'update'  => ($latest !== null && version_compare($latest, $current, '>')),
        'error'   => $err,
        'checked' => date('c'),
        'cached'  => false,
    ];
    @file_put_contents($cf, json_encode($res));
    return $res;
}

# Накатить свежий код из main.zip. Возвращает [ok, updated[], count, backup, error].
function upd_apply(): array
{
    if (!class_exists('ZipArchive')) return ['ok' => false, 'error' => 'на сервере нет расширения PHP zip'];

    $zipUrl = 'https://github.com/' . APP_REPO . '/archive/refs/heads/main.zip';
    $data = upd_http_get($zipUrl, true);
    if ($data === null) return ['ok' => false, 'error' => 'не удалось скачать архив с GitHub'];

    $tmp = sys_get_temp_dir() . '/mpm_upd_' . bin2hex(random_bytes(4));
    if (!@mkdir($tmp, 0700, true)) return ['ok' => false, 'error' => 'нет доступа к temp-папке'];
    $zipFile = $tmp . '/src.zip';
    if (@file_put_contents($zipFile, $data) === false) { upd_rrm($tmp); return ['ok' => false, 'error' => 'не удалось записать архив']; }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) { upd_rrm($tmp); return ['ok' => false, 'error' => 'битый архив']; }
    $zip->extractTo($tmp);
    $zip->close();

    # корень распакованного архива (GitHub кладёт в РЕПО-main/)
    $root = null;
    foreach (glob($tmp . '/*', GLOB_ONLYDIR) ?: [] as $d)
        if (is_file($d . '/index.php') || is_file($d . '/lib.php')) { $root = $d; break; }
    if (!$root) { upd_rrm($tmp); return ['ok' => false, 'error' => 'не найден корень проекта в архиве']; }

    # эти файлы/папки не трогаем никогда
    $protect = ['config.php', 'config.php.tmp', 'rules.json', 'rules.json.tmp'];

    $backup = __DIR__ . '/backups/update-' . date('Ymd-His');
    @mkdir($backup, 0775, true);

    $updated = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f)
    {
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        if ($rel === '' || in_array($rel, $protect, true)) continue;
        if (strpos($rel, '.git/') === 0 || strpos($rel, 'backups/') === 0) continue;

        $dest = __DIR__ . '/' . $rel;
        if ($f->isDir()) { @mkdir($dest, 0775, true); continue; }

        @mkdir(dirname($dest), 0775, true);
        if (is_file($dest)) { @mkdir(dirname($backup . '/' . $rel), 0775, true); @copy($dest, $backup . '/' . $rel); }
        if (@copy($f->getPathname(), $dest)) $updated[] = $rel;
    }

    upd_rrm($tmp);
    upd_clear_cache();
    return ['ok' => true, 'updated' => $updated, 'count' => count($updated), 'backup' => basename($backup)];
}

# Рекурсивное удаление временной папки
function upd_rrm(string $dir): void
{
    if (!is_dir($dir)) { @unlink($dir); return; }
    foreach (scandir($dir) ?: [] as $e)
    {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        is_dir($p) ? upd_rrm($p) : @unlink($p);
    }
    @rmdir($dir);
}
