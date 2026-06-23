<?php

declare(strict_types=1);

# Обработчик планировщика. Прогоняет все включённые правила автопереноса групп.
# Запуск:
#   • из крона/CLI:  php cron.php           (без ключа, локально)
#   • по HTTP:       https://САЙТ/cron.php?key=КЛЮЧ   (ключ — со страницы «Планировщик»)

require __DIR__ . '/lib.php';
require __DIR__ . '/rules.php';

$cli = (PHP_SAPI === 'cli');

if (!$cli)
{
    header('Content-Type: application/json; charset=utf-8');
    $key = (string)($_GET['key'] ?? '');
    if (!config_exists() || $key === '' || !hash_equals(rules_cron_key(), $key))
    {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
}
elseif (!config_exists())
{
    fwrite(STDERR, "config.php не найден — панель не настроена\n");
    exit(1);
}

try
{
    $summary = rules_run_all();
    $total   = array_sum(array_map(fn($s) => $s['moved'] ?? 0, $summary));
    $out = [
        'ok'          => true,
        'time'        => date('c'),
        'rules_run'   => count($summary),
        'moved_total' => $total,
        'details'     => $summary,
    ];
    echo $cli
        ? json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        : json_encode($out, JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
    log_err($e);
    if (!$cli) http_response_code(500);
    # в CLI показываем реальную причину (вывод не публичный), по HTTP — общий текст
    $err = $cli ? ($e->getMessage() ?: 'cron failed') : 'cron failed';
    echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE) . ($cli ? "\n" : '');
    exit(1);
}
