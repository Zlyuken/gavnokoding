<?php
//Zlyuken: самопальный экспорт офферов в xml, можно прикрутить к биту - воткнуть футер и хедер и изменить дб настройки.
//вариант не универсальный, есть и лучше, делал чисто для себя.
$DBHost = 'localhost';
$DBLogin = 'bx_login';
$DBPassword = 'bx_pass';
$DBName = "bx_basename";
$db = new mysqli($DBHost, $DBLogin, $DBPassword, $DBName);
$db->query("SET NAMES 'utf-8'");
$get_list_offer = $db->query("SELECT * FROM `b_catalog_product`");
$xml = "<price>";
  while ($arr_a = $get_list_offer->fetch_assoc()){
    $ar_offer[] = $arr_a;
  }
 foreach ($ar_offer as $assort_id)
 {
    $offer_id = $assort_id['ID'];
    $offer_nalic = $assort_id['AVAILABLE'];
    if($offer_nalic == "Y"){
    $get_list_offer_cost = $db->query("SELECT * FROM `b_catalog_price` WHERE PRODUCT_ID = ".$offer_id."");
      while ($arr_b = $get_list_offer_cost->fetch_assoc()){
          $arr_offer_cost[] = $arr_b;
        }
   foreach ($arr_offer_cost as $offer_cost)
     {
       $offer_cost_price_id = $offer_cost['ID'];
       $offer_cost_price = $offer_cost['PRICE'];
     }
        $get_offer_detail = $db->query("SELECT * FROM `b_iblock_element` WHERE ID = ".$offer_id."");
        while ($arr_c = $get_offer_detail->fetch_assoc()){
        $arr_offer_det[] = $arr_c;
        }
        foreach ($arr_offer_det as $offer_det)
          {
             $offer_cost_id = $offer_det['ID'];
             $offer_cost_XML_ID = $offer_det['XML_ID'];
             $offer_cost_name = $offer_det['NAME'];
             $offer_cost_ACTIVE = $offer_det['ACTIVE'];
          }
          $xml .= '<name>'.htmlspecialchars($offer_cost_name).'</name>'; //обработка спец знаков
          $xml .= '<XML>'.$offer_cost_XML_ID.'</XML>';
          $xml .= '<id>'.$offer_cost_price_id.'</id>';
          $xml .= '<cost>'.$offer_cost_price.'</cost>';
    }
  }
$xml .= "</price>";
$sxe = new SimpleXMLElement($xml);
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;
$dom->loadXML($sxe->asXML());
header( 'Content-type: text/xml' );
header("Content-type: text/xml;charset=UTF-8");
$dom->loadXML('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml); //фикс вывода кодировки
echo $dom->saveXML(); //вывод процесса на экран
$dom->save('price.xml');
exit;
?>