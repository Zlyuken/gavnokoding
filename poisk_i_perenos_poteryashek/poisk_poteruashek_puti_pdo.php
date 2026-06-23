<?php
/**
 * Скрипт поиска файлов-потерях в /upload/.
 * Версия с хранением данных в БД для экономии памяти.
 *
 * Таблицы в БД:
 * - upload_files_scan    — список файлов для проверки
 * - upload_files_skipped — игнорируемые файлы
 *
 * Запуск:
 *   php скрипт.php --step=1  // сканирование и запись в БД
 *   php скрипт.php --step=2  // поиск в БД
 *   php скрипт.php --step=3  // перемещение
 */
// ===== НАСТРОЙКИ =====
ini_set('memory_limit', '8192M');
set_time_limit(0);
@ignore_user_abort(true);
$dbhost = 'dbhost';
$dbuser = 'dbuser';
$dbpass = 'dbpass';
$dbname = 'dbname';
$_SERVER["DOCUMENT_ROOT"] = $_SERVER["DOCUMENT_ROOT"] ?: '/home/bitrix/www';
$isCLI = (php_sapi_name() === 'cli');
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
// require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
if (!$isCLI) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Поиск потерях</title></head><body><pre>';
}
// Директории
$uploadDir = $_SERVER["DOCUMENT_ROOT"] . "/upload";
$outputDir = $_SERVER["DOCUMENT_ROOT"] . "/upload_logs";
$poiskpoteryah_Dir = $_SERVER["DOCUMENT_ROOT"] . "/upload_not_founded_files";
// Настройки
$batchSize = 100;
$minCellLength = 5;
$excludeTables = [
    'b_cache_tag', 'b_cache_hash', 'b_stack_cache',
    'b_search_content', 'b_search_index', 'b_event_log',
    'b_stat_*', 'b_perf_*', 'b_clouds_*',
    'upload_files_scan', 'upload_files_skipped',
];
$ignoreFileTypes = [
    'php', 'html', 'htm', 'js', 'css', 'map', 'log', 'tmp', 'bak', 'old',
    'zip', 'tar', 'gz', 'rar', '7z', 'bz2', 'xz',
    'sql', 'dump', 'csv', 'xml', 'json',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    'exe', 'dll', 'so', 'dylib', 'bin', 'bat', 'sh', 'cmd',
    'ttf', 'woff', 'woff2', 'eot', 'otf',
    'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'm4v',
    'mp3', 'wav', 'flac', 'ogg', 'aac', 'wma', 'm4a',
    'psd', 'ai', 'eps', 'svg', 'cdr', 'indd', 'ico',
    'Thumbs.db', '.DS_Store', 'desktop.ini', '.htaccess',
];
$ignoreSettings = [
    'ignore_no_extension' => false,
    'ignore_hidden' => true,
    'max_file_size' => 0,
];
// ===== ОБРАБОТКА --step =====
$step = '1';
if ($isCLI) {
    foreach (($_SERVER['argv'] ?? []) as $arg) {
        if (strpos($arg, '--step=') === 0) { $step = substr($arg, 7); break; }
    }
} else {
    foreach ($_GET as $key => $value) {
        if ($key === '--step' || strpos($key, '--step=') === 0) {
            $step = $value ?: substr($key, 7) ?: '1';
            break;
        }
    }
    if (preg_match('/--step=(\d+)/', $_SERVER['QUERY_STRING'] ?? '', $m)) $step = $m[1];
}
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
// ===== ФУНКЦИИ =====
function output($msg) {
    global $isCLI;
    echo $msg . "\n";
    if (!$isCLI && ob_get_level() > 0) { ob_flush(); flush(); }
}
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    return round($bytes / pow(1024, min($pow, count($units)-1)), $precision) . ' ' . $units[min($pow, count($units)-1)];
}
function isSystemFile($f) { return in_array($f, ['Thumbs.db', '.DS_Store', 'desktop.ini', '.htaccess']); }
function isIgnoredFilename($f, $arr) { return in_array($f, $arr); }
/**
 * Создание подключения к БД с нужными настройками
 */
function createPDOConnection($dbhost, $dbuser, $dbpass, $dbname) {
    $pdo = new PDO(
        "mysql:host={$dbhost};dbname={$dbname};charset=utf8mb4",
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
    // Настройки уровня сессии
    $sessionSettings = [
        "SET SESSION wait_timeout = 86400",
        "SET SESSION interactive_timeout = 86400",
        "SET SESSION net_read_timeout = 86400",
        "SET SESSION net_write_timeout = 86400",
        "SET SESSION max_execution_time = 0",
        "SET SESSION group_concat_max_len = 1048576",
        "SET SESSION sql_mode = ''",
    ];
    foreach ($sessionSettings as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Игнорируем ошибки
        }
    }
    return $pdo;
}
/**
 * Проверка и переподключение при потере соединения
 */
function checkConnection($pdo, $dbhost, $dbuser, $dbpass, $dbname) {
    try {
        $pdo->query("SELECT 1");
        return $pdo;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'server has gone away') !== false ||
            strpos($e->getMessage(), 'Lost connection') !== false) {
            output("[WARNING] Соединение потеряно, переподключаемся...");
            $newPdo = createPDOConnection($dbhost, $dbuser, $dbpass, $dbname);
            output("[OK] Переподключение успешно");
            return $newPdo;
        }
        throw $e;
    }
}
// ===== PDO ПОДКЛЮЧЕНИЕ =====
try {
    $pdo = createPDOConnection($dbhost, $dbuser, $dbpass, $dbname);
    output("PDO подключение установлено.");
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}
// ===== РАБОТА С ТАБЛИЦАМИ (PDO) =====
function createScanTables($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `upload_files_scan` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `file_name` VARCHAR(500) NOT NULL,
            `file_path` VARCHAR(1000) NOT NULL,
            `status` TINYINT DEFAULT 0 COMMENT '0-не проверен,1-найден,2-потеряха',
            `found_table` VARCHAR(100) DEFAULT NULL,
            `checked_at` DATETIME DEFAULT NULL,
            INDEX `idx_name` (`file_name`(255)),
            INDEX `idx_status` (`status`),
            INDEX `idx_checked` (`status`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `upload_files_skipped` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `file_path` VARCHAR(1000) NOT NULL,
            `file_name` VARCHAR(500) NOT NULL,
            `reason` VARCHAR(50) NOT NULL,
            `file_size` BIGINT DEFAULT 0,
            `modified` DATETIME DEFAULT NULL,
            INDEX `idx_reason` (`reason`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    output("Таблицы созданы/проверены.");
}
function clearScanTables($pdo) {
    $pdo->exec("TRUNCATE TABLE `upload_files_scan`");
    $pdo->exec("TRUNCATE TABLE `upload_files_skipped`");
    output("Таблицы очищены.");
}
function insertScanBatch($pdo, $batch) {
    if (empty($batch)) return;
    $sql = "INSERT INTO `upload_files_scan` (`file_name`, `file_path`, `status`) VALUES ";
    $values = [];
    $params = [];
    foreach ($batch as $i => $item) {
        $values[] = "(:name_{$i}, :path_{$i}, 0)";
        $params["name_{$i}"] = $item['name'];
        $params["path_{$i}"] = $item['path'];
    }
    $stmt = $pdo->prepare($sql . implode(',', $values));
    $stmt->execute($params);
}
function insertSkippedBatch($pdo, $batch) {
    if (empty($batch)) return;
    $sql = "INSERT INTO `upload_files_skipped` (`file_path`, `file_name`, `reason`, `file_size`, `modified`) VALUES ";
    $values = [];
    $params = [];
    foreach ($batch as $i => $item) {
        $values[] = "(:path_{$i}, :name_{$i}, :reason_{$i}, :size_{$i}, :modified_{$i})";
        $params["path_{$i}"] = $item['path'];
        $params["name_{$i}"] = $item['name'];
        $params["reason_{$i}"] = $item['reason'];
        $params["size_{$i}"] = $item['size'];
        $params["modified_{$i}"] = $item['modified'];
    }
    $stmt = $pdo->prepare($sql . implode(',', $values));
    $stmt->execute($params);
}
function getAllTables($pdo, $exclude) {
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $t = $row[0];
        if (empty($t)) continue;
        $skip = false;
        foreach ($exclude as $p) {
            if (preg_match('/^' . str_replace('*', '.*', $p) . '$/i', $t)) {
                $skip = true;
                break;
            }
        }
        if (!$skip) $tables[] = $t;
    }
    return $tables;
}
function getTextColumns($pdo, $tableName) {
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
        while ($row = $stmt->fetch()) {
            $type = strtolower($row['Type'] ?? '');
            if (empty($row['Field'])) continue;
            if (strpos($type, 'char') !== false || strpos($type, 'text') !== false ||
                strpos($type, 'blob') !== false || strpos($type, 'enum') !== false ||
                strpos($type, 'set') !== false || strpos($type, 'json') !== false) {
                $cols[] = $row['Field'];
            }
        }
    } catch (\Throwable $e) {}
    return $cols;
}
function searchInAllTables($pdo, $fileName, $allTables) {
    if (empty($fileName)) return false;
    foreach ($allTables as $tableName) {
        $cols = getTextColumns($pdo, $tableName);
        if (empty($cols)) continue;
        $parts = [];
        $params = [];
        foreach ($cols as $i => $col) {
            $parts[] = "`{$col}` LIKE :search_{$i}";
            $params["search_{$i}"] = '%' . $fileName . '%';
        }
        if (empty($parts)) continue;
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM `{$tableName}` WHERE " . implode(' OR ', $parts) . " LIMIT 1");
            $stmt->execute($params);
            if ($stmt->fetch()) return $tableName;
        } catch (\Throwable $e) {}
    }
    return false;
}
// ============================================
// ЗАПУСК
// ============================================
output("=== ПОИСК ФАЙЛОВ-ПОТЕРЯХ (PDO-версия) ===");
output("Шаг: {$step} | " . date('Y-m-d H:i:s') . " | PHP: " . PHP_VERSION . "\n");
if ($step === '1') {
    // ===== ШАГ 1: Сканирование =====
    output("ШАГ 1: Сканирование /upload/ с записью в БД...");
    createScanTables($pdo);
    clearScanTables($pdo);
    $scanBatch = [];
    $skipBatch = [];
    $totalScanned = $totalFound = $totalSkipped = 0;
    $batchLimit = 500;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (strpos(str_replace('\\', '/', $file->getPathname()), '/resize_cache/') !== false) continue;
        $totalScanned++;
        $filename = $file->getFilename();
        // Формируем относительный путь
        $fullPath = str_replace('\\', '/', $file->getPathname());
        $docRoot = str_replace('\\', '/', $_SERVER["DOCUMENT_ROOT"]);
        $docRoot = rtrim($docRoot, '/');
        // Убираем DOCUMENT_ROOT из начала пути
        if (stripos($fullPath, $docRoot) === 0) {
            $relativePath = substr($fullPath, strlen($docRoot));
        } else {
            $relativePath = $fullPath;
        }
        // Обеспечиваем начало с /
        $relativePath = '/' . ltrim($relativePath, '/');
        // Убираем двойные слеши
        $relativePath = preg_replace('#/+#', '/', $relativePath);
        $fileSize = $file->getSize();
        $isSkipped = false;
        $skipReason = '';
        if (!$isSkipped && !empty($ignoreSettings['ignore_hidden']) && strpos($filename, '.') === 0) {
            $isSkipped = true;
            $skipReason = 'hidden_file';
        }
        if (!$isSkipped && isSystemFile($filename)) {
            $isSkipped = true;
            $skipReason = 'system_file';
        }
        if (!$isSkipped && isIgnoredFilename($filename, $ignoreFileTypes)) {
            $isSkipped = true;
            $skipReason = 'ignored_filename';
        }
        if (!$isSkipped) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (empty($ext) && !empty($ignoreSettings['ignore_no_extension'])) {
                $isSkipped = true;
                $skipReason = 'no_extension';
            } elseif (!empty($ext) && in_array($ext, $ignoreFileTypes)) {
                $isSkipped = true;
                $skipReason = 'ignored_extension';
            }
        }
        if ($isSkipped) {
            $skipBatch[] = [
                'path' => $relativePath,
                'name' => $filename,
                'reason' => $skipReason,
                'size' => $fileSize,
                'modified' => date('Y-m-d H:i:s', $file->getMTime())
            ];
            $totalSkipped++;
        } else {
            $scanBatch[] = [
                'name' => $filename,
                'path' => $relativePath
            ];
            $totalFound++;
        }
        if (count($scanBatch) >= $batchLimit) {
            insertScanBatch($pdo, $scanBatch);
            $scanBatch = [];
        }
        if (count($skipBatch) >= $batchLimit) {
            insertSkippedBatch($pdo, $skipBatch);
            $skipBatch = [];
        }
        if ($totalScanned % 100000 === 0) {
            $pdo = checkConnection($pdo, $dbhost, $dbuser, $dbpass, $dbname);
            output("[СКАН] {$totalScanned} файлов... (найдено: {$totalFound}, пропущено: {$totalSkipped})");
        }
    }
    if (!empty($scanBatch)) insertScanBatch($pdo, $scanBatch);
    if (!empty($skipBatch)) insertSkippedBatch($pdo, $skipBatch);
    $stmt = $pdo->query("SELECT COUNT(DISTINCT file_name) FROM `upload_files_scan`");
    $uniqueCount = $stmt->fetchColumn();
    file_put_contents($outputDir . '/scan_stats.json', json_encode([
        'date' => date('Y-m-d H:i:s'),
        'total_scanned' => $totalScanned,
        'total_found' => $totalFound,
        'unique_names' => $uniqueCount,
        'total_skipped' => $totalSkipped
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    output("\n[ГОТОВО] Данные в БД:");
    output("  Всего записей: {$totalFound}");
    output("  Уникальных имён: {$uniqueCount}");
    output("  Пропущено: {$totalSkipped}");
    output("\nЗапустите: php " . __FILE__ . " --step=2");
} elseif ($step === '2') {
    // ===== ШАГ 2: Поиск в БД =====
    output("ШАГ 2: Поиск файлов в БД...");
    $stmt = $pdo->query("SELECT COUNT(*) FROM `upload_files_scan` WHERE `status` = 0");
    $totalLeft = $stmt->fetchColumn();
    output("Осталось проверить: {$totalLeft}");
    if ($totalLeft == 0) {
        output("Все файлы уже проверены.");
        exit;
    }
    $uniqueNames = [];
    $stmt = $pdo->query("SELECT DISTINCT `file_name` FROM `upload_files_scan` WHERE `status` = 0");
    while ($row = $stmt->fetch()) {
        $uniqueNames[] = $row['file_name'];
    }
    $totalUnique = count($uniqueNames);
    output("Уникальных непроверенных имён: {$totalUnique}");
    output("Получение списка таблиц БД...");
    $allTables = getAllTables($pdo, $excludeTables);
    output("Таблиц для поиска: " . count($allTables));
    $progressFile = $outputDir . '/progress.json';
    $startIndex = 0;
    $lostCount = 0;
    if (file_exists($progressFile)) {
        $progress = json_decode(file_get_contents($progressFile), true) ?: [];
        if (!empty($progress['completed'])) {
            output("Предыдущий запуск завершён. Удалите {$progressFile} для повтора.");
            exit;
        }
        $startIndex = $progress['last_index'] ?? 0;
        $lostCount = $progress['poiskpoteryah_count'] ?? 0;
        output("Продолжаем с индекса: {$startIndex} (потерях: {$lostCount})");
    }
    output("\nНачинаем проверку...\n");
    $startTime = time();
    $checkedCount = 0;
    // Подготавливаем запросы для обновления статусов
    $updateFoundStmt = $pdo->prepare("UPDATE `upload_files_scan` SET `status` = 1, `found_table` = :table, `checked_at` = NOW() WHERE `file_name` = :name AND `status` = 0");
    $updateLostStmt = $pdo->prepare("UPDATE `upload_files_scan` SET `status` = 2, `checked_at` = NOW() WHERE `file_name` = :name AND `status` = 0");
    for ($i = $startIndex; $i < $totalUnique; $i++) {
        $fileName = $uniqueNames[$i];
        $checkedCount++;
        $foundTable = searchInAllTables($pdo, $fileName, $allTables);
        if ($foundTable) {
            $updateFoundStmt->execute([':table' => $foundTable, ':name' => $fileName]);
        } else {
            $updateLostStmt->execute([':name' => $fileName]);
            $lostCount++;
        }
        if ($i % 500 === 0) gc_collect_cycles();
        if ($i % $batchSize === 0 && $i > 0) {
            file_put_contents($progressFile, json_encode([
                'last_index' => $i + 1,
                'poiskpoteryah_count' => $lostCount,
                'checked_count' => $checkedCount,
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
        }
        if ($i % 10000 === 0 && $i > 0) {
            $pdo = checkConnection($pdo, $dbhost, $dbuser, $dbpass, $dbname);
            $elapsed = time() - $startTime;
            $speed = $i / max($elapsed, 1);
            $remaining = ($totalUnique - $i) / max($speed, 0.001);
            output(sprintf(
                "[%d/%d] %.1f%% | %.1f/сек | ~%.0f мин | потерях: %d | память: %s",
                $i, $totalUnique, ($i/$totalUnique)*100,
                $speed, $remaining/60, $lostCount,
                formatBytes(memory_get_usage(true))
            ));
        }
    }
    file_put_contents($progressFile, json_encode([
        'last_index' => $totalUnique,
        'poiskpoteryah_count' => $lostCount,
        'checked_count' => $checkedCount,
        'updated_at' => date('Y-m-d H:i:s'),
        'completed' => true,
    ]));
    output("\nЭкспорт потерях в файлы...");
    $lostJsonFile = $outputDir . '/poisk_poteruashek_naydeno.json';
    $lostTxtFile = $outputDir . '/poisk_poteruashek_puti.txt';
    $lostData = [];
    $lostPaths = [];
    $stmt = $pdo->query("SELECT `file_name`, `file_path` FROM `upload_files_scan` WHERE `status` = 2");
    while ($row = $stmt->fetch()) {
        $lostData[] = [
            'file_name' => $row['file_name'],
            'path' => $row['file_path'],
            'found_at' => date('Y-m-d H:i:s')
        ];
        $lostPaths[] = $row['file_path'];
    }
    file_put_contents($lostJsonFile, json_encode($lostData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    file_put_contents($lostTxtFile, implode("\n", $lostPaths) . "\n");
    $elapsed = time() - $startTime;
    output("\n=== ЗАВЕРШЕНО ===");
    output("Проверено: {$checkedCount}");
    output("Найдено потерях: {$lostCount}");
    output("Время: " . round($elapsed/60, 1) . " мин");
    output("\nЗапустите: php " . __FILE__ . " --step=3");
} elseif ($step === '3') {
    // ===== ШАГ 3: Перемещение =====
    if (!is_dir($poiskpoteryah_Dir)) {
        mkdir($poiskpoteryah_Dir, 0755, true) or die("Не создать {$poiskpoteryah_Dir}");
    }
    // Получаем данные о потерянных файлах напрямую из БД
    $stmt = $pdo->query("SELECT `file_name`, `file_path` FROM `upload_files_scan` WHERE `status` = 2");
    $lostData = $stmt->fetchAll();
    if (empty($lostData)) {
        output("[ОШИБКА] Нет потерянных файлов в БД. Запустите шаг 2.");
        exit;
    }
    $total = count($lostData);
    $moved = $errors = $notFound = 0;
    output("ШАГ 3: Перемещение {$total} файлов...\n");
    foreach ($lostData as $index => $item) {
        // Путь из БД - относительный, например: /upload/iblock/ec3/file.jpg
        $relativePath = $item['file_path'];
        // Формируем полный путь к исходному файлу
        $src = $_SERVER["DOCUMENT_ROOT"] . '/' . ltrim($relativePath, '/\\');
        $src = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $src);
        $src = preg_replace('#[/\\\\]+#', DIRECTORY_SEPARATOR, $src);
        // Формируем путь назначения - заменяем /upload/ на /upload_not_founded_files/
        $dst = str_replace(
            DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR . 'upload_not_founded_files' . DIRECTORY_SEPARATOR,
            $src
        );
        // Проверяем существование исходного файла
        if (!file_exists($src)) {
            $notFound++;
            continue;
        }
        // Создаем целевую директорию
        $dstDir = dirname($dst);
        if (!is_dir($dstDir)) {
            if (!mkdir($dstDir, 0755, true)) {
                $errors++;
                continue;
            }
        }
        // Перемещаем файл
        if (rename($src, $dst)) {
            $moved++;
            if ($moved % 10 === 0) output("[ПЕРЕМЕЩЕНО] {$moved}/{$total}");
        } else {
            $errors++;
        }
    }
    // Сохраняем статистику
    file_put_contents($outputDir . '/statistika_perenosa.json', json_encode([
        'date' => date('Y-m-d H:i:s'),
        'total' => $total,
        'moved' => $moved,
        'errors' => $errors,
        'not_found' => $notFound
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    output("\n=== ГОТОВО ===");
    output("Всего в списке: {$total}");
    output("Перемещено: {$moved}");
    output("Ошибок: {$errors}");
    output("Не найдено: {$notFound}");
    output("\nЛог сохранен: {$outputDir}/statistika_perenosa.json");
} else {
    output("Использование:");
    output("  php " . __FILE__ . " --step=1  — Сканирование /upload/ (запись в БД)");
    output("  php " . __FILE__ . " --step=2  — Поиск в БД");
    output("  php " . __FILE__ . " --step=3  — Перемещение потерях");
}
if (!$isCLI) echo '</pre></body></html>';