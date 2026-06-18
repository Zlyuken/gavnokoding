<?php
define("NO_KEEP_STATISTIC", true);   // Отключает сбор статистики
define("NOT_CHECK_PERMISSIONS", true); // Отключает проверку прав // чтобы не нужно было авторизовываться
@set_time_limit(0);                   // Снимает ограничение на время выполнения
@ignore_user_abort(true);             // Позволяет скрипту работать после отключения SSH
if($_SERVER["DOCUMENT_ROOT"]){$_SERVER["DOCUMENT_ROOT"] = $_SERVER["DOCUMENT_ROOT"];}else{$_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';}//переопределения для корня при запуске через ssh
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");//подключаем ядро
$start = date("Y-m-d H:i:s");
echo 'старт - '.$start."\r\n";
$connection = \Bitrix\Main\Application::getConnection();
$sqlHelper = $connection->getSqlHelper();

          define('BASE_PATH', '/home/bitrix/www/upload'); //проверяемая директория
          define('DUMP_PATH', '/home/bitrix/www/upload_dump');//куда перемещаем если нет в базе

if (!is_dir(DUMP_PATH)){mkdir(DUMP_PATH, 0755, true);}// Создаем DUMP_PATH если не существует
/* основной запрос с исключениями: не ищем по определённым таблицам + по таблицам в которых менее 1000 строк */
  $tablesResult = $connection->query("SELECT t.TABLE_NAME, t.TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES t INNER JOIN (
    SELECT TABLE_NAME, COUNT(*) as column_count
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'sitemanager'
    GROUP BY TABLE_NAME
    HAVING COUNT(*) > 5
  ) c ON t.TABLE_NAME = c.TABLE_NAME
  WHERE t.TABLE_SCHEMA = 'sitemanager'
  AND t.TABLE_ROWS > 1000
  AND t.TABLE_NAME NOT IN (
    'b_crm_act', 'b_disk_object_path', 'b_crm_event', 'b_crm_event_relations', 'b_crm_timeline_bind', 'b_crm_timeline', 'b_crm_act_bind', 'b_crm_act_elem', 'b_file_duplicate', 'b_im_log', 'b_crm_act_comm', 'b_tasks_log',
    'b_tasks_member', 'b_tasks_scorer_event', 'b_crm_entity_channel', 'b_crm_entity_countable_act', 'b_crm_act_channel_stat', 'b_crm_act_stat', 'b_im_message_viewed', 'b_crm_act_mail_body_bind', 'b_crm_act_mail_meta',
    'b_forum_message', 'b_uts_forum_message', 'b_uts_sonet_comment', 'b_uts_crm_deal', 'b_uts_crm_lead', 'b_uts_crm_timeline', 'b_uts_tasks_task', 'b_uts_crm_activity', 'b_voximplant_call_crm_entity')
  AND t.TABLE_NAME NOT LIKE '%search%'
  ORDER BY t.TABLE_ROWS DESC;");
  $directory = BASE_PATH;
  // вывод всех файлов из папок и подпапок
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);//создаём массив из папок с файлами
  $filename = '';
  // $test_cnt = 0;
  $transp = 0;
  foreach ($iterator as $file){
    // ++$test_cnt;
    // if($test_cnt < '100'){//если нужно потестить какую-то категорию - ограничиваем число итераций
    if ($file->isDir()){} else {
    $filename = $file->getFilename();//имя файла для поиска в базе
    // $file полный путь к файлу
        $t_name = '';
        $found = false; // Добавляем флаг для отслеживания нахождения файла
        foreach($tablesResult as $name){
              if($found) break; // Прерываем цикл, если файл уже найден
        $t_name = $name["TABLE_NAME"];
        $list = '';
        $t_name_rows = '';
        /* ищем по определённым типам полей + ограничение на мин знаков поля - от 5*/
        $t_name_rows = $connection->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'sitemanager' AND TABLE_NAME = '$t_name'
        AND DATA_TYPE IN ('char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext')
        AND COALESCE(CHARACTER_MAXIMUM_LENGTH, 0) >= 5;");
          $t_rows = '';
          foreach($t_name_rows as $t_rows){
          if($t_rows['COLUMN_NAME'] != 'ID' && $t_rows['COLUMN_NAME'] != 'TIMESTAMP_X'  && $t_rows['COLUMN_NAME'] != 'MODULE_ID')
            {
              if($t_rows['COLUMN_NAME'] ==='b_file'){echo 'b_file<br>';}
              $list .= "".$t_rows['COLUMN_NAME']." = '".$filename."' OR ";
            }
          }
          if($list){
          $zapr = '';
          $zapr = substr($list, 0, -4);
          $get_file = '';
          $get_file = $connection->query("SELECT * FROM `$t_name` WHERE $zapr;");
            foreach($get_file as $file){
              $found = true; // Устанавливаем флаг, что файл найден
              break 2; // Выходим из обоих циклов
            }
          }
        }
        if (!$found){// Если файл не найден - перемещаем к хуям
            $sourcePath = $file->getPathname();
            $relativePath = str_replace(BASE_PATH, '', $sourcePath);
            $relativePath = ltrim($relativePath, '/');
            $targetPath = DUMP_PATH . '/' . $relativePath;
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)){mkdir($targetDir, 0755, true);}
            rename($sourcePath, $targetPath);
            ++$transp;
        }
    }
    // }
    // else{
      // break;
    // }
  }
$end = date("Y-m-d H:i:s");
echo "\r\n"."перемещено - ".$transp."\r\n";
echo 'конец - '.$end;




/* --- если нужно потестировать и проверить конкретный файл //весь код выше закоментировать а нижний раскоментировать


define("NO_KEEP_STATISTIC", true);   // Отключает сбор статистики
define("NOT_CHECK_PERMISSIONS", true); // Отключает проверку прав (если не нужна)
@set_time_limit(0);                   // Снимает ограничение на время выполнения
@ignore_user_abort(true);             // Позволяет скрипту работать после отключения SSH
if($_SERVER["DOCUMENT_ROOT"]){$_SERVER["DOCUMENT_ROOT"] = $_SERVER["DOCUMENT_ROOT"];}else{$_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/www';}
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
$start = date("Y-m-d H:i:s");
echo "\r\n"."старт - ".$start."\r\n";
$connection = \Bitrix\Main\Application::getConnection();
$sqlHelper = $connection->getSqlHelper();
define('BASE_PATH', '/home/bitrix/www/upload/ai'); //сканируемая директория
define('DUMP_PATH', '/home/bitrix/www/upload/ai_dump');//куда перемещаем если нет в базе
if (!is_dir(DUMP_PATH)){mkdir(DUMP_PATH, 0755, true);}// Создаем DUMP_PATH если не существует
$tablesResult = $connection->query("
SELECT t.TABLE_NAME, t.TABLE_ROWS
FROM INFORMATION_SCHEMA.TABLES t
INNER JOIN (
    SELECT TABLE_NAME, COUNT(*) as column_count
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'sitemanager'
    GROUP BY TABLE_NAME
    HAVING COUNT(*) > 5
) c ON t.TABLE_NAME = c.TABLE_NAME
WHERE t.TABLE_SCHEMA = 'sitemanager'
AND t.TABLE_ROWS > 1000
AND t.TABLE_NAME NOT IN (
    'b_crm_act', 'b_disk_object_path', 'b_crm_event', 'b_crm_event_relations', 'b_crm_timeline_bind', 'b_crm_timeline', 'b_crm_act_bind', 'b_crm_act_elem', 'b_file_duplicate', 'b_im_log', 'b_crm_act_comm', 'b_tasks_log',
    'b_tasks_member', 'b_tasks_scorer_event', 'b_crm_entity_channel', 'b_crm_entity_countable_act', 'b_crm_act_channel_stat', 'b_crm_act_stat', 'b_im_message_viewed', 'b_crm_act_mail_body_bind', 'b_crm_act_mail_meta',
    'b_forum_message', 'b_uts_forum_message', 'b_uts_sonet_comment', 'b_uts_crm_deal', 'b_uts_crm_lead', 'b_uts_crm_timeline', 'b_uts_tasks_task', 'b_uts_crm_activity', 'b_voximplant_call_crm_entity')
  AND t.TABLE_NAME NOT LIKE '%search%'
  ORDER BY t.TABLE_ROWS DESC;
");
  
  
  $filename = '7jy05zpauxc8y2kjtworz8yme3m5cps4.jpg'; // имя файла - который нужно проверить
  
  
        foreach($tablesResult as $name){
              if($found) break; // Прерываем цикл, если файл уже найден
        $t_name = $name["TABLE_NAME"];
        $list = '';
        $t_name_rows = '';
        $t_name_rows = $connection->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'sitemanager'
        AND TABLE_NAME = '$t_name'
        AND DATA_TYPE IN ('char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext')
        AND COALESCE(CHARACTER_MAXIMUM_LENGTH, 0) >= 5;
        ");
          $t_rows = '';
          foreach($t_name_rows as $t_rows){
          if($t_rows['COLUMN_NAME'] != 'ID' && $t_rows['COLUMN_NAME'] != 'TIMESTAMP_X'  && $t_rows['COLUMN_NAME'] != 'MODULE_ID')
            {
              if($t_rows['COLUMN_NAME'] ==='b_file'){echo 'b_file<br>';}
              $list .= "".$t_rows['COLUMN_NAME']." = '".$filename."' OR ";
            }
          }
          if($list){
          $zapr = '';
          $zapr = substr($list, 0, -4);
          $get_file = '';
          $get_file = $connection->query("SELECT * FROM `$t_name` WHERE $zapr;");
            foreach($get_file as $file){
              $found = true; // Устанавливаем флаг, что файл найден
              echo "файл $filename найден в $t_name \r\n";
              break 2; // Выходим из обоих циклов
            }
          }
        }
$end = date("Y-m-d H:i:s");
echo 'конец - '.$end."\r\n";
*/
?>