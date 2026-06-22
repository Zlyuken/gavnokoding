<?php
/**
 * Скрипт поиска файлов-потерях в /upload/. Файлы есть в папке но на них нет ссылок в базе (такое бывает при переезде с сервера на сервер, или при обновление или при слитие нескольких баз)
 *
 * Создаваемые файлы:
 * spisok_failov_na_ftp.json - список файлов для проверки в шаге 2 (создаётся на первом шаге)
 * skipped_files.json - список игнорируемых файлов с причинами (создаётся на первом шаге)
 * poisk_poteruashek_naydeno.json - список файлов-потерях (создаётся на втором шаге)
 * poisk_poteruashek_puti.txt - пути к файлам-потеряхам (создаётся на втором шаге)
 * progress.json - прогресс + итог работы скрипта (создаётся на втором шаге)
 * statistika_perenosa.json - статистика переноса (создаётся на третьем шаге)
 *
 * Этап 1: Сканирует /upload/ и создаёт 2 JSON-файла:
 *   - spisok_failov_na_ftp.json (файлы для проверки)
 *   - skipped_files.json (игнорируемые файлы)
 * Этап 2: Ищет каждый файл из spisok_failov_na_ftp.json во ВСЕХ таблицах БД
 * Этап 3: Перемещает файлы-потеряхи в /upload_not_founded_files/ с сохранением структуры
 *
 * Запуск через браузер:
 *   /local/cron/poisk_i_perenos_failov_kotorih_net_v_baze_sql.php?--step=1 //сканирование файлов в папке
 *   /local/cron/poisk_i_perenos_failov_kotorih_net_v_baze_sql.php?--step=2 //поиск по базе
 *   /local/cron/poisk_i_perenos_failov_kotorih_net_v_baze_sql.php?--step=3 //перемещение
 *
 * Запуск через CLI:
 *   php /home/bitrix/www/poisk_i_perenos_failov_kotorih_net_v_baze_sql.php --step=1
 *   php /home/bitrix/www/poisk_i_perenos_failov_kotorih_net_v_baze_sql.php --step=2
 *   php /home/bitrix/www/poisk_i_perenos_failov_kotorih_net_v_baze_sql.php --step=3
 */
@ignore_user_abort(true);// Позволяет скрипту работать после отключения SSH
if($_SERVER["DOCUMENT_ROOT"]){$_SERVER["DOCUMENT_ROOT"] = $_SERVER["DOCUMENT_ROOT"];}else{$_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';}//для запуска через крон/ssh
// Определяем режим запуска
$isCLI = (php_sapi_name() === 'cli');
if ($isCLI){
    define("NO_KEEP_STATISTIC", true);
    define("NOT_CHECK_PERMISSIONS", true);
    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
} else {
    require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
    set_time_limit(0);// Снимает ограничение на время выполнения
    // ini_set('memory_limit', '2048M');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Поиск файлов-потерях</title></head><body><pre>';
}
// ============================================
// НАСТРОЙКИ
// ============================================
$uploadDir = $_SERVER["DOCUMENT_ROOT"] . "/upload";//проверяемая директория
$outputDir = $_SERVER["DOCUMENT_ROOT"] . "/upload_logs";//куда пишем все логи
$poiskpoteryah_Dir = $_SERVER["DOCUMENT_ROOT"] . "/upload_not_founded_files";//куда переносим не найденные в базе файлы с сохранением структуры
$batchSize = 100;
$delayBetweenBatches = 0.01;//задержка
$delayBetweenQueries = 0.002;//задержка
$minCellLength = 5;//минимум символов в ячейке в таблице
$excludeTables = [
    'b_cache_tag',
    'b_cache_hash',
    'b_stack_cache',
    'b_search_content',
    'b_search_index',
    'b_event_log',
    'b_stat_*',
    'b_perf_*',
    'b_clouds_*',
];//скипаемые таблицы
// ============================================
// НАСТРОЙКА: Типы файлов для игнорирования при сканировании
// ============================================
$ignoreFileTypes = [
    // Системные файлы
    'php', 'html', 'htm', 'js', 'css', 'map', 'log', 'tmp', 'bak', 'old',
    // Архивы
    'zip', 'tar', 'gz', 'rar', '7z', 'bz2', 'xz',
    // Дампы и данные
    'sql', 'dump', 'csv', 'xml', 'json',
    // Документы
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    // Исполняемые файлы
    'exe', 'dll', 'so', 'dylib', 'bin', 'bat', 'sh', 'cmd',
    // Шрифты
    'ttf', 'woff', 'woff2', 'eot', 'otf',
    // Видео
    'mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'm4v',
    // Аудио
    'mp3', 'wav', 'flac', 'ogg', 'aac', 'wma', 'm4a',
    // Графика и дизайн
    'psd', 'ai', 'eps', 'svg', 'cdr', 'indd', 'ico',
    // Системные файлы ОС (точные имена файлов)
    'Thumbs.db', '.DS_Store', 'desktop.ini', '.htaccess',
];
// Дополнительные настройки фильтрации
$ignoreSettings = [
    'ignore_no_extension' => false,    // Игнорировать файлы без расширения
    'ignore_hidden' => true,           // Игнорировать скрытые файлы (начинающиеся с точки)
    'max_file_size' => 0,              // Максимальный размер файла в байтах (0 - без ограничений)
];
// ============================================
// ОБРАБОТКА ПАРАМЕТРА --step
// ============================================
$step = '1';
if ($isCLI){
    if (isset($_SERVER['argv'])){
        foreach ($_SERVER['argv'] as $arg){
            if (strpos($arg, '--step=') === 0){
                $step = substr($arg, 7);
                break;
            }
        }
    }
} else {
    foreach ($_GET as $key => $value){
        if ($key === '--step' || $key === '--step='){
            $step = $value ?: '1';
            break;
        }
        if (strpos($key, '--step=') === 0){
            $step = substr($key, 7);
            break;
        }
    }
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    if (preg_match('/--step=(\d+)/', $queryString, $matches)){
        $step = $matches[1];
    }
}
// ============================================
if (!is_dir($outputDir)){
    mkdir($outputDir, 0755, true);
}
/**
 * Вывод сообщения с учётом режима (CLI или Web)
 */
function output($message){
    global $isCLI;
    if ($isCLI){
        echo $message . "\n";
    } else {
        echo $message . "\n";
        if (ob_get_level() > 0){
            ob_flush();
        }
        flush();
    }
}
/**
 * Получает список ВСЕХ таблиц в БД, кроме исключённых
 */
function getAllTables($excludeTables = []){
    global $DB;
    $tables = [];
    $sql = "SHOW TABLES";
    $res = $DB->Query($sql);
    while ($row = $res->Fetch()){
        $tableName = array_values($row)[0];
        $skip = false;
        foreach ($excludeTables as $excludePattern){
            $pattern = '/^' . str_replace('*', '.*', $excludePattern) . '$/i';
            if (preg_match($pattern, $tableName)){
                $skip = true;
                break;
            }
        }
        if (!$skip){
            $tables[] = $tableName;
        }
    }
    usort($tables, function ($a, $b){
        $priority = [
            'b_file',
            'b_utm_crm_lead',
            'b_utm_crm_deal',
            'b_utm_crm_contact',
            'b_utm_crm_company',
            'b_iblock_element_property',
            'b_iblock_element',
            'b_iblock_section',
            'b_medialib_collection_item',
        ];//смена приоритета, перемещаем в начало списка, чтобы сначало проверилось наличие файла в этих таблицах
        $aPos = array_search($a, $priority);
        $bPos = array_search($b, $priority);
        if ($aPos === false && $bPos === false) return 0;
        if ($aPos === false) return 1;
        if ($bPos === false) return -1;
        return $aPos - $bPos;
    });
    return $tables;
}
/**
 * Проверяет, является ли имя файла системным (точное совпадение)
 */
function isSystemFile($filename){
    $systemFiles = ['Thumbs.db', '.DS_Store', 'desktop.ini', '.htaccess'];
    return in_array($filename, $systemFiles);
}
/**
 * Проверяет, является ли имя файла в списке игнорируемых (точное совпадение)
 */
function isIgnoredFilename($filename, $ignoreFileTypes){
    return in_array($filename, $ignoreFileTypes);
}
/**
 * Форматирует байты в читаемый вид
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
/**
 * Сканирует директорию и возвращает два списка:
 * 1. files - файлы для проверки (прошли фильтрацию)
 * 2. skipped - пропущенные файлы (с причинами)
 */
function scanUploadDir($uploadDir, $ignoreFileTypes = [], $ignoreSettings = []){
    // Файлы, которые прошли фильтрацию (для шага 2)
    $filesForCheck = [];
    // Файлы, которые были пропущены (игнорированы)
    $skippedFiles = [];
    // Статистика
    $stats = [
        'total_scanned' => 0,        // Всего просканировано файлов
        'total_found' => 0,          // Прошли фильтрацию
        'total_skipped' => 0,        // Пропущено всего
        'skipped_by_extension' => 0, // Пропущено по расширению
        'skipped_hidden' => 0,       // Пропущено скрытых
        'skipped_system' => 0,       // Пропущено системных
        'skipped_no_extension' => 0, // Пропущено без расширения
        'skipped_by_size' => 0,      // Пропущено по размеру
        'skipped_by_filename' => 0,  // Пропущено по имени файла
    ];
    $directoryIterator = new RecursiveDirectoryIterator(
        $uploadDir,
        FilesystemIterator::SKIP_DOTS
    );
    $iterator = new RecursiveIteratorIterator($directoryIterator);
    foreach ($iterator as $file){
        if (!$file->isFile()){
            continue;
        }
        // Пропускаем файлы в директории resize_cache
        $filePath = str_replace('\\', '/', $file->getPathname());
        if (strpos($filePath, '/resize_cache/') !== false){
            continue;
        }
        $stats['total_scanned']++;
        $filename = $file->getFilename();
        $fullPath = str_replace('\\', '/', $file->getPathname());
        $relativePath = '/' . str_replace($_SERVER["DOCUMENT_ROOT"] . '/', '', $fullPath);
        $relativePath = str_replace('//', '/', $relativePath);
        $fileSize = $file->getSize();
        $fileModified = date('Y-m-d H:i:s', $file->getMTime());
        $isSkipped = false;
        $skipReason = '';
        $skipReasonText = '';
        $skipDetails = [];
        // Проверка 1: Скрытые файлы (начинающиеся с точки)
        if (!$isSkipped && !empty($ignoreSettings['ignore_hidden']) && strpos($filename, '.') === 0){
            $isSkipped = true;
            $skipReason = 'hidden_file';
            $skipReasonText = 'Скрытый файл (начинается с точки)';
            $stats['skipped_hidden']++;
        }
        // Проверка 2: Системные файлы
        if (!$isSkipped && isSystemFile($filename)){
            $isSkipped = true;
            $skipReason = 'system_file';
            $skipReasonText = 'Системный файл ОС';
            $stats['skipped_system']++;
        }
        // Проверка 3: Точное совпадение имени файла в списке игнорирования
        if (!$isSkipped && isIgnoredFilename($filename, $ignoreFileTypes)){
            $isSkipped = true;
            $skipReason = 'ignored_filename';
            $skipReasonText = 'Имя файла в списке игнорирования';
            $stats['skipped_by_filename']++;
        }
        // Проверка 4: Расширение файла
        if (!$isSkipped){
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (empty($extension) && $filename === pathinfo($filename, PATHINFO_FILENAME)){
                // Файл без расширения
                if (!empty($ignoreSettings['ignore_no_extension'])){
                    $isSkipped = true;
                    $skipReason = 'no_extension';
                    $skipReasonText = 'Файл без расширения';
                    $stats['skipped_no_extension']++;
                }
            } elseif (!empty($extension) && in_array($extension, $ignoreFileTypes)){
                // Игнорируемое расширение
                $isSkipped = true;
                $skipReason = 'ignored_extension';
                $skipReasonText = "Игнорируемое расширение: .{$extension}";
                $skipDetails['extension'] = $extension;
                $stats['skipped_by_extension']++;
            }
        }
        // Проверка 5: Размер файла
        if (!$isSkipped && !empty($ignoreSettings['max_file_size']) && $ignoreSettings['max_file_size'] > 0){
            if ($fileSize > $ignoreSettings['max_file_size']){
                $isSkipped = true;
                $skipReason = 'size_limit';
                $skipReasonText = "Превышен лимит размера: " . formatBytes($fileSize);
                $skipDetails['size_limit'] = $ignoreSettings['max_file_size'];
                $skipDetails['size_limit_formatted'] = formatBytes($ignoreSettings['max_file_size']);
                $stats['skipped_by_size']++;
            }
        }
        if ($isSkipped){
            // Добавляем в список пропущенных
            $skippedEntry = [
                'path' => $relativePath,
                'file_name' => $filename,
                'reason' => $skipReason,
                'reason_text' => $skipReasonText,
                'size' => $fileSize,
                'size_formatted' => formatBytes($fileSize),
                'modified' => $fileModified
            ];
            // Добавляем дополнительные детали если есть
            if (!empty($skipDetails)){
                $skippedEntry = array_merge($skippedEntry, $skipDetails);
            }
            $skippedFiles[] = $skippedEntry;
            $stats['total_skipped']++;
        } else {
            // Добавляем в список для проверки
            if (!isset($filesForCheck[$filename])){
                $filesForCheck[$filename] = [];
            }
            $filesForCheck[$filename][] = $relativePath;
            $stats['total_found']++;
            if ($stats['total_found'] % 100000 === 0){
                output("[СКАН] Найдено файлов для проверки: {$stats['total_found']}...");
            }
        }
        // Прогресс сканирования
        if ($stats['total_scanned'] % 100000 === 0){
            output("[СКАН] Просканировано: {$stats['total_scanned']} файлов...");
        }
    }
    // Вывод статистики сканирования
    output("");
    output("[СКАН] ======================");
    output("[СКАН] РЕЗУЛЬТАТЫ СКАНИРОВАНИЯ:");
    output("[СКАН] Всего просканировано: {$stats['total_scanned']}");
    output("[СКАН] Отобрано для проверки: {$stats['total_found']}");
    output("[СКАН] Уникальных имён: " . count($filesForCheck));
    output("[СКАН] Пропущено (игнорировано): {$stats['total_skipped']}");
    if ($stats['skipped_by_extension'] > 0){
        output("[СКАН]   - По расширению: {$stats['skipped_by_extension']}");
    }
    if ($stats['skipped_hidden'] > 0){
        output("[СКАН]   - Скрытых: {$stats['skipped_hidden']}");
    }
    if ($stats['skipped_system'] > 0){
        output("[СКАН]   - Системных: {$stats['skipped_system']}");
    }
    if ($stats['skipped_no_extension'] > 0){
        output("[СКАН]   - Без расширения: {$stats['skipped_no_extension']}");
    }
    if ($stats['skipped_by_size'] > 0){
        output("[СКАН]   - По размеру: {$stats['skipped_by_size']}");
    }
    if ($stats['skipped_by_filename'] > 0){
        output("[СКАН]   - По имени файла: {$stats['skipped_by_filename']}");
    }
    if (!empty($ignoreFileTypes)){
        output("[СКАН] Игнорируемые расширения: " . implode(', ', $ignoreFileTypes));
    }
    output("[СКАН] ======================");
    return [
        'files' => $filesForCheck,    // Файлы для шага 2
        'skipped' => $skippedFiles,    // Пропущенные файлы
        'stats' => $stats              // Статистика
    ];
}
/**
 * Получает список текстовых колонок таблицы
 */
function getTextColumns($tableName){
    global $DB;
    static $cache = [];
    if (isset($cache[$tableName])){
        return $cache[$tableName];
    }
    $columns = [];
    $sql = "SHOW COLUMNS FROM `{$tableName}`";
    $res = $DB->Query($sql);
    while ($row = $res->Fetch()){
        $field = $row['Field'];
        $type = strtolower($row['Type']);
        if (
            strpos($type, 'date') !== false ||
            strpos($type, 'time') !== false ||
            strpos($type, 'timestamp') !== false ||
            strpos($type, 'datetime') !== false ||
            strpos($type, 'year') !== false
        ) continue;
        if (
            strpos($type, 'int') !== false ||
            strpos($type, 'float') !== false ||
            strpos($type, 'double') !== false ||
            strpos($type, 'decimal') !== false ||
            strpos($type, 'numeric') !== false ||
            strpos($type, 'real') !== false ||
            strpos($type, 'bit') !== false ||
            strpos($type, 'bool') !== false
        ) continue;
        if (
            strpos($type, 'char') !== false ||
            strpos($type, 'text') !== false ||
            strpos($type, 'blob') !== false ||
            strpos($type, 'enum') !== false ||
            strpos($type, 'set') !== false ||
            strpos($type, 'json') !== false
        ){
            $columns[] = $field;
        }
    }
    $cache[$tableName] = $columns;
    return $columns;
}
/**
 * Ищет имя файла во всех таблицах БД
 */
function searchFileNameInDatabase($fileName, $allTables){
    global $DB;
    $minCellLength = 5;
    foreach ($allTables as $tableName){
        $columns = getTextColumns($tableName);
        if (empty($columns)) continue;
        $whereParts = [];
        $safeFileName = $DB->ForSql($fileName);
        foreach ($columns as $colName){
            $whereParts[] = "(
                `{$colName}` LIKE '%{$safeFileName}%'
                AND CHAR_LENGTH(`{$colName}`) >= {$minCellLength}
            )";
        }
        if (empty($whereParts)) continue;
        $whereClause = implode(' OR ', $whereParts);
        $sql = "SELECT 1 FROM `{$tableName}` WHERE {$whereClause} LIMIT 1";
        try {
            $res = $DB->Query($sql);
            if ($res && $res->Fetch()){
                return ['found' => true, 'table' => $tableName];
            }
        } catch (\Exception $e){
            output("[ОШИБКА] Таблица {$tableName}: " . $e->getMessage());
            continue;
        }
    }
    return ['found' => false, 'table' => null];
}
/**
 * Немедленно записывает информацию о файле-потеряхе в JSON и TXT файлы
 */
function appendPoiskToFiles($fileName, $paths, $outputDir){
    $poiskpoteryah_File = $outputDir . '/poisk_poteruashek_naydeno.json';
    $pathsFile = $outputDir . '/poisk_poteruashek_puti.txt';
    $normalizedPaths = [];
    foreach ($paths as $path){
        $normalizedPaths[] = str_replace('\\', '/', $path);
    }
    $poiskpoteryah_Data = [];
    if (file_exists($poiskpoteryah_File)){
        $poiskpoteryah_Data = json_decode(file_get_contents($poiskpoteryah_File), true);
        if (!$poiskpoteryah_Data) $poiskpoteryah_Data = [];
    }
    foreach ($normalizedPaths as $path){
        $poiskpoteryah_Data[] = [
            'file_name' => $fileName,
            'path' => $path,
            'found_at' => date('Y-m-d H:i:s')
        ];
    }
    file_put_contents(
        $poiskpoteryah_File,
        json_encode($poiskpoteryah_Data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    $pathsString = implode("\n", $normalizedPaths) . "\n";
    file_put_contents($pathsFile, $pathsString, FILE_APPEND | LOCK_EX);
}
/**
 * Обновляет прогресс проверки в отдельном файле
 */
function updateProgress($progressFile, $lastIndex, $checkedCount, $poiskpoteryah_Count){
    $progress = [
        'last_index' => $lastIndex,
        'checked_count' => $checkedCount,
        'poiskpoteryah_count' => $poiskpoteryah_Count,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    file_put_contents($progressFile, json_encode($progress, JSON_UNESCAPED_UNICODE));
}
/**
 * Перемещает файлы-потеряхи в новую директорию с сохранением структуры путей
 */
function proces_Poisk_Failov($poiskpoteryah_Dir, $outputDir){
    $poiskpoteryah_File = $outputDir . '/poisk_poteruashek_naydeno.json';
    if (!file_exists($poiskpoteryah_File)){
        return [
            'perenos' => 0, 'errors' => 0, 'not_found' => 0, 'total' => 0,
            'error_message' => 'Файл со списком потерях не найден: ' . $poiskpoteryah_File
        ];
    }
    $poiskpoteryah_Data = json_decode(file_get_contents($poiskpoteryah_File), true);
    if (!$poiskpoteryah_Data){
        return [
            'perenos' => 0, 'errors' => 0, 'not_found' => 0, 'total' => 0,
            'error_message' => 'Не удалось загрузить список потерях / нужно запустить шаг 2'
        ];
    }
    $totalFiles = count($poiskpoteryah_Data);
    $perenos = 0;
    $errors = 0;
    $notFound = 0;
    output("");
    output("Начинаем перемещение файлов...");
    output("Всего файлов для перемещения: {$totalFiles}");
    output("Целевая директория: {$poiskpoteryah_Dir}");
    output("");
    foreach ($poiskpoteryah_Data as $item){
        $originalPath = str_replace('\\', '/', $item['path']);
        $originalPath = str_replace('//', '/', $originalPath);
        if (strpos($originalPath, '/') !== 0){
            $originalPath = '/' . $originalPath;
        }
        $newPath = str_replace('/upload/', '/upload_not_founded_files/', $originalPath);
        $sourceFile = $_SERVER["DOCUMENT_ROOT"] . $originalPath;
        $destFile = $_SERVER["DOCUMENT_ROOT"] . $newPath;
        if (!file_exists($sourceFile)){
            output("[ПРОПУСК] Файл не найден: {$originalPath}");
            $notFound++;
            continue;
        }
        $destDir = dirname($destFile);
        if (!is_dir($destDir)){
            if (!mkdir($destDir, 0755, true)){
                output("[ОШИБКА] Не удалось создать директорию: {$destDir}");
                $errors++;
                continue;
            }
            output("[OK] Создана директория: " . str_replace($_SERVER["DOCUMENT_ROOT"], '', $destDir));//для логирования переноса
        }
        if (rename($sourceFile, $destFile)){
            $perenos++;
            if ($perenos % 100 === 0){
                output("[ПЕРЕМЕЩЕНО] {$perenos} из {$totalFiles} файлов...");
            }
        } else {
            output("[ОШИБКА] Не удалось переместить: {$originalPath}");
            $errors++;
        }
    }
    return [
        'perenos' => $perenos,
        'errors' => $errors,
        'not_found' => $notFound,
        'total' => $totalFiles
    ];
}
// ============================================
// ЗАПУСК
// ============================================
output("=== ПОИСК ФАЙЛОВ-ПОТЕРЯХ ===");
output("Шаг: {$step}");
output("Дата: " . date('Y-m-d H:i:s'));
output("");
if ($step === '1'){
    // ========== ШАГ 1: Сканируем /upload/ ==========
    output("ШАГ 1: Сканирование директории /upload/...");
    /*
    output("");
    // Выводим информацию о настройках фильтрации
    output("НАСТРОЙКИ ФИЛЬТРАЦИИ:");
    if (!empty($ignoreFileTypes)){
        output("- Игнорируемые расширения и файлы: " . implode(', ', $ignoreFileTypes));
    }
    if (!empty($ignoreSettings['ignore_no_extension'])){
        output("- Игнорируются файлы без расширения");
    }
    if (!empty($ignoreSettings['ignore_hidden'])){
        output("- Игнорируются скрытые файлы (начинающиеся с точки)");
    }
    if (!empty($ignoreSettings['max_file_size']) && $ignoreSettings['max_file_size'] > 0){
        output("- Максимальный размер файла: " . formatBytes($ignoreSettings['max_file_size']));
    }
    output("");
    */
    // Запускаем сканирование
    $scanResult = scanUploadDir($uploadDir, $ignoreFileTypes, $ignoreSettings);
    // Файл 1: Список файлов ДЛЯ ПРОВЕРКИ (для шага 2)
    $filesForCheckFile = $outputDir . '/spisok_failov_na_ftp.json';
    file_put_contents($filesForCheckFile, json_encode($scanResult['files'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    // Файл 2: Список ИГНОРИРУЕМЫХ файлов
    $skippedFile = $outputDir . '/skipped_files.json';
    $skippedData = [
        'scan_date' => date('Y-m-d H:i:s'),
        'upload_directory' => '/upload/',
        'description' => 'Список файлов, которые были проигнорированы при сканировании',
        'total_scanned' => $scanResult['stats']['total_scanned'],
        'total_ignored' => count($scanResult['skipped']),
        'total_for_check' => $scanResult['stats']['total_found'],
        'statistics' => [
            'by_extension' => $scanResult['stats']['skipped_by_extension'],
            'hidden_files' => $scanResult['stats']['skipped_hidden'],
            'system_files' => $scanResult['stats']['skipped_system'],
            'no_extension' => $scanResult['stats']['skipped_no_extension'],
            'by_size' => $scanResult['stats']['skipped_by_size'],
            'by_filename' => $scanResult['stats']['skipped_by_filename']
        ],
        'filter_settings' => [
            'ignored_extensions_and_files' => $ignoreFileTypes,
            'ignore_hidden' => $ignoreSettings['ignore_hidden'],
            'ignore_no_extension' => $ignoreSettings['ignore_no_extension'],
            'max_file_size' => $ignoreSettings['max_file_size'],
            'max_file_size_formatted' => $ignoreSettings['max_file_size'] > 0 ? formatBytes($ignoreSettings['max_file_size']) : 'без ограничений'
        ],
        'ignored_files' => $scanResult['skipped']
    ];
    file_put_contents($skippedFile, json_encode($skippedData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    output("");
    output("[ГОТОВО] Созданы файлы:");
    output("");
    output("1. Файлы для проверки (шаг 2):");
    output("   {$filesForCheckFile}");
    output("   Размер: " . formatBytes(filesize($filesForCheckFile)));
    output("   Файлов: " . $scanResult['stats']['total_found']);
    output("   Уникальных имён: " . count($scanResult['files']));
    output("");
    output("2. Игнорируемые файлы:");
    output("   {$skippedFile}");
    output("   Размер: " . formatBytes(filesize($skippedFile)));
    output("   Файлов: " . count($scanResult['skipped']));
    output("");
    if ($isCLI){
        output("Запустите шаг 2: php " . __FILE__ . " --step=2");
    } else {
        $scriptUrl = ($_SERVER['HTTPS'] ?? 'off' === 'on' ? 'https://' : 'http://') .
                     $_SERVER['HTTP_HOST'] .
                     parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        output('Запустите шаг 2: <a href="' . $scriptUrl . '?--step=2">' . $scriptUrl . '?--step=2</a>');
    }
} elseif ($step === '2'){
    // ========== ШАГ 2: Ищем файлы в БД с немедленной записью результатов ==========
    $jsonFile = $outputDir . '/spisok_failov_na_ftp.json';
    if (!file_exists($jsonFile)){
        output("[ОШИБКА] Не найден файл {$jsonFile}. Сначала выполните шаг 1.");
        if (!$isCLI){ echo '</pre></body></html>'; }
        exit;
    }
    output("ШАГ 2: Поиск файлов в БД...");
    output("Загрузка списка файлов...");
    $files = json_decode(file_get_contents($jsonFile), true);
    if (!$files){
        output("[ОШИБКА] Не удалось загрузить список файлов. / нужно запустить шаг 2");
        if (!$isCLI){ echo '</pre></body></html>'; }
        exit;
    }
    $totalUniqueFiles = count($files);
    output("Уникальных имён файлов для проверки: {$totalUniqueFiles}");
    output("Получение списка таблиц БД...");
    $allTables = getAllTables($excludeTables);
    output("Найдено таблиц для проверки: " . count($allTables));
    $progressFile = $outputDir . '/progress.json';
    $poiskpoteryah_File = $outputDir . '/poisk_poteruashek_naydeno.json';
    $pathsFile = $outputDir . '/poisk_poteruashek_puti.txt';
    $startIndex = 0;
    $poiskpoteryah_Count = 0;
    if (file_exists($progressFile)){
        $progress = json_decode(file_get_contents($progressFile), true);
        if (!empty($progress['completed'])){
            output("ВНИМАНИЕ: Предыдущий запуск уже завершён. Для повторного запуска удалите файл прогресса:");
            output("  {$progressFile}");
            if (!$isCLI){ echo '</pre></body></html>'; }
            exit;
        }
        $startIndex = $progress['last_index'] ?? 0;
        $poiskpoteryah_Count = $progress['poiskpoteryah_count'] ?? 0;
        output("Найден прогресс. Продолжаем с индекса: {$startIndex}");
        output("Уже проверено: {$startIndex}");
        output("Уже найдено потерях: {$poiskpoteryah_Count}");
    } else {
        // Создаём файлы, только если их ещё нет
        if (!file_exists($poiskpoteryah_File)){
            file_put_contents($poiskpoteryah_File, '[]');
        }
        if (!file_exists($pathsFile)){
            file_put_contents($pathsFile, '');
        }
    }
    $fileNames = array_keys($files);
    $totalToCheck = count($fileNames);
    output("");
    output("Начинаем проверку...");
    output("Результаты сразу записываются в файлы:");
    output("  JSON: {$poiskpoteryah_File}");
    output("  TXT:  {$pathsFile}");
    output("");
    $startTime = time();
    $checkedCount = 0;
    for ($i = $startIndex; $i < $totalToCheck; $i++){
        $fileName = $fileNames[$i];
        $checkedCount++;
        if ($i % 100 === 0 && $i > 0){//вывод тех инфы о состояния поиска
            // $elapsed = time() - $startTime;
            // $speed = $i / max($elapsed, 1);
            // $remaining = ($totalToCheck - $i) / max($speed, 0.001);
            // output(sprintf(
                // "[ПРОВЕРКА] %d / %d (%.1f%%), скорость: %.1f файлов/сек, осталось: ~%.0f мин, найдено потерях: %d",
                // $i,
                // $totalToCheck,
                // ($i / $totalToCheck) * 100,
                // $speed,
                // $remaining / 60,
                // $poiskpoteryah_Count
            // ));
        }
        $result = searchFileNameInDatabase($fileName, $allTables);
        if (!$result['found']){
            appendPoiskToFiles($fileName, $files[$fileName], $outputDir);
            $poiskpoteryah_Count++;
        }
        // Сохраняем прогресс и делаем паузу каждый батч
        if ($i % $batchSize === 0 && $i > 0){
            updateProgress($progressFile, $i + 1, $checkedCount, $poiskpoteryah_Count);
            if ($delayBetweenBatches > 0){
                output(".");
                usleep($delayBetweenBatches * 1000000);
            }
        }
        if ($delayBetweenQueries > 0){
            usleep($delayBetweenQueries * 1000000);
        }
    }
    $progress = [
        'last_index' => $totalToCheck,
        'checked_count' => $checkedCount,
        'poiskpoteryah_count' => $poiskpoteryah_Count,
        'updated_at' => date('Y-m-d H:i:s'),
        'completed' => true,
    ];
    file_put_contents($progressFile, json_encode($progress, JSON_UNESCAPED_UNICODE));
    $elapsed = time() - $startTime;
    output("");
    output("=== ЗАВЕРШЕНО ===");
    output("Всего проверено файлов: {$checkedCount}");
    output("Найдено потерях: {$poiskpoteryah_Count}");
    output("Затрачено времени: " . round($elapsed / 60, 1) . " мин");
    output("");
    output("Результаты сохранены в:");
    output("  Прогресс: {$progressFile}");
    output("  Потеряхи JSON: {$poiskpoteryah_File}");
    output("  Потеряхи TXT:  {$pathsFile}");
    output("");
    if ($isCLI){
        output("Для перемещения файлов запустите: php " . __FILE__ . " --step=3");
    } else {
        $scriptUrl = ($_SERVER['HTTPS'] ?? 'off' === 'on' ? 'https://' : 'http://') .
                     $_SERVER['HTTP_HOST'] .
                     parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        output('Для перемещения файлов запустите: <a href="' . $scriptUrl . '?--step=3">' . $scriptUrl . '?--step=3</a>');
    }
} elseif ($step === '3'){
    // ========== ШАГ 3: Перемещаем файлы-потеряхи ==========
    if (!is_dir($poiskpoteryah_Dir)){
        if (mkdir($poiskpoteryah_Dir, 0755, true)){
            output("[OK] Создана целевая директория: {$poiskpoteryah_Dir}");
        } else {
            output("[ОШИБКА] Не удалось создать целевую директорию: {$poiskpoteryah_Dir}");
            if (!$isCLI){ echo '</pre></body></html>'; }
            exit;
        }
    }
    output("ШАГ 3: Перемещение файлов-потерях...");
    $startTime = time();
    $result = proces_Poisk_Failov($poiskpoteryah_Dir, $outputDir);
    if (isset($result['error_message'])){
        output("[ОШИБКА] {$result['error_message']}");
        if (!$isCLI){ echo '</pre></body></html>'; }
        exit;
    }
    $moveReportFile = $outputDir . '/statistika_perenosa.json';
    $moveReport = [
        'date' => date('Y-m-d H:i:s'),
        'source_dir' => '/upload/',
        'target_dir' => '/upload_not_founded_files/',
        'statistics' => [
            'total_files' => $result['total'],
            'perenos_successfully' => $result['perenos'],
            'errors' => $result['errors'],
            'not_found' => $result['not_found']
        ]
    ];
    file_put_contents($moveReportFile, json_encode($moveReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    output("");
    output("=== ПЕРЕМЕЩЕНИЕ ЗАВЕРШЕНО ===");
    output("Всего файлов для перемещения: {$result['total']}");
    output("Успешно перемещено: {$result['perenos']}");
    output("Ошибок при перемещении: {$result['errors']}");
    output("Файлов не найдено: {$result['not_found']}");
    output("Затрачено времени: " . round((time() - $startTime) / 60, 1) . " мин");
    output("");
    output("Результаты:");
    output("  Отчет о перемещении: {$moveReportFile}");
    output("  Новая директория: {$poiskpoteryah_Dir}");
} else {
    output("Использование:");
    if ($isCLI){
        output("  php " . __FILE__ . " --step=1   — Сканирование /upload/");
        output("  php " . __FILE__ . " --step=2   — Поиск в БД");
        output("  php " . __FILE__ . " --step=3   — Перемещение потерях");
    } else {
        output("  {URL_скрипта}?--step=1   — Сканирование /upload/");
        output("  {URL_скрипта}?--step=2   — Поиск в БД");
        output("  {URL_скрипта}?--step=3   — Перемещение потерях");
    }
}
if (!$isCLI){
    echo '</pre></body></html>';
}