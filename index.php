<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("получение списка из базы + добавление + удаление");

// $DBHost = 'localhost';
// $DBLogin = 'city_test_login';
// $DBPassword = 'city_test_pass';
// $DBName = 'city_test_db';

$db = new mysqli($DBHost, $DBLogin, $DBPassword, $DBName);
$db->query("SET NAMES 'utf8'");
?>
<style>
.cont_city{margin-left:20px;}
.cont_city > div :nth-child(1){width:50px; display:inline-block;}
.cont_city > div :nth-child(2){width:150px; display:inline-block;}
.cont_city > div :nth-child(3){width:100px; display:inline-block;}
.cont_city > div button{border:0px; padding:3px;}
.cont_city select,
.cont_city option{width:200px!important; height:20px!important;}
</style>

<?
//проверка ссылки на параметр для удаления
 	if (isset($_GET['del'])) {
    $id = $_GET['del'];
    $query = "DELETE FROM copy_b_sale_location_city_lang WHERE id='".$id."'";
    mysqli_query($db, $query) or die( mysqli_error($db) );
    if ($query) {
    echo '<p>удалено.</p>';
  } else {
    echo '<p>ошибка удаления: ' . mysqli_error($db) . '</p>';
 }
}

//вывод максимальных значений
$get_list_city_count = $db->query("SELECT * FROM `copy_b_sale_location_city_lang` WHERE CITY_ID=(SELECT MAX(CITY_ID) FROM copy_b_sale_location_city_lang)");
  while ($arr = $get_list_city_count->fetch_assoc()){
  $arr_get_list_city_count[] = $arr;
}
$new_id = $arr_get_list_city_count['0']['ID'] + 1;
$new_CITY_ID = $arr_get_list_city_count['0']['CITY_ID'] + 1;

//добавление новой строки
if (isset($_POST["NAME"])) {
  $sql = mysqli_query($db, "INSERT INTO `copy_b_sale_location_city_lang` (`ID`, `CITY_ID`, `LID`, `NAME`,`SHORT_NAME`) VALUES ('{$_POST['ID']}','{$_POST['CITY_ID']}','{$_POST['LID']}', '{$_POST['NAME']}', '{$_POST['SHORT_NAME']}')");
  //проверка и вывод результата
  if ($sql) {
    echo '<p>добавлено.</p>';
  } else {
    echo '<p>ошибка добавления: ' . mysqli_error($db) . '</p>';
 }
}

//вывод всех городов
  $get_list_city = $db->query("SELECT * FROM `copy_b_sale_location_city_lang` z WHERE ID > 0");
  while ($arr = $get_list_city->fetch_assoc()){
  $ar_get_list_city[] = $arr;
 }

?>

<div class="cont_city">
<form action="" method="post">
<div>
New ID: <input type="text" name="ID" value="<?=$new_id?>" ><br>
New ID City: <input type="text" name="CITY_ID" value="<?=$new_CITY_ID?>" ><br>
<select name="LID">
<option value='ru'>ru</option>
<option value='en'>en</option>
</select><br>
Название: <input type="text" name="NAME"><br><br>
<input type="submit" value="Добавить">
</div>
</form>
<br><br><br>

<?
  foreach ($ar_get_list_city as $assort_id){
    $city_id = $assort_id['ID'];
    $city_name = $assort_id['NAME'];
  ?>
    <div>
      <span><?=$city_id;?></span>
      <span><?=$city_name;?></span>
      <a href="?del=<?= $city_id;?>">delete</a>
    </div>
  <?
  }
?>
</div>

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>