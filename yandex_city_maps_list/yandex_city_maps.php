<script src="https://api-maps.yandex.ru/2.1/?apikey={_ваш_ключ_в_якартах_}&lang=ru_RU" type="text/javascript"></script>
<script src="./jquery-3.6.0.min.js" type="text/javascript"></script>
<link rel="stylesheet" href="./main.css" />
<pre>
Была задача:
1 - вывести список магазов
2 - группировка по городам
3 - создать карту со списком городов
4 - при клике на город - увеличивать местоположение города с выводом всех точек в данном городе
5 - выводить под картой все магазы
6 - при смене города на карте - оставлять под картой все магазины/адреса из данного города
7 - добавить кнопку показать всё (карта общим планом + все магазы под картой)

UPD - карта делалась на базе https://yandex.ru/dev/jsapi-v2-1/doc/ru/v2-1/examples/cases/list_box_layout?tabs=defaultTabsGroup-nradftee_list_box_layout.js
UPD2 - sql(pdo)+php+js+jquery (так как сайт старый писалось под php 7)


где реализовано: https://ensten.ru/shops
</pre>
				<div class="cities-top">
					<div class="cities">
            <ul id="my-listbox" role="menu" style="display: inline-block;"><!--тут за счёт я.карт будет формироваться список ссылок для оперирования на карте//--></ul>
          </div>
    <script type="text/javascript">
    ymaps.ready(init);
    function init () {
    var myMap = new ymaps.Map('map', {
            center: [61.52269,77.60742],//общий вид РФ
            zoom: 3,
            controls: []
        }),
        ListBoxLayout = ymaps.templateLayoutFactory.createClass(
            "<div id='my-listbox'></div>", {//указывается куда быдет втыкаться ссылки на карте
            build: function() {
                ListBoxLayout.superclass.build.call(this);
                this.childContainerElement = $('#my-listbox').get(0);//id привязки списка
                this.events.fire('childcontainerchange', {
                    newChildContainerElement: this.childContainerElement,
                    oldChildContainerElement: null
                });
            },
            getChildContainerElement: function () {return this.childContainerElement;},
            clear: function () {
                this.events.fire('childcontainerchange', {
                    newChildContainerElement: null,
                    oldChildContainerElement: this.childContainerElement
                });
                this.childContainerElement = null;
                ListBoxLayout.superclass.clear.call(this);
            }
        }),
        ListBoxItemLayout = ymaps.templateLayoutFactory.createClass(
            "<a class='link' id='{{data.id}}'>{{data.content}}</a>"
        ),
        listBoxItems = [// формирование списка городов
          new ymaps.control.ListBoxItem({data: {content: 'Все города', id: 'all', center: [61.52269,77.60742],zoom: 3}}),//доп ссылка со всем списком
          <?foreach ($cities as $city){
          if($city['city'] or $city['coords']){?>
  new ymaps.control.ListBoxItem({data: {content: '<?=$city['city'];?>', id: '<?=$city['code'];?>', center: [<?=$city['coords'];?>],zoom: <?
  if($city['code'] == 'mos'){echo'10';}else{echo'11';}//смена высоты для МСК
  ?>}}),
          <?}}?>
],
        listBox = new ymaps.control.ListBox({
                items: listBoxItems,
                options: {
                    layout: ListBoxLayout,
                    itemLayout: ListBoxItemLayout
                }
            });
        listBox.events.add('click', function (e) {
            var item = e.get('target');
            if (item != listBox) {
                myMap.setCenter(
                    item.data.get('center'),
                    item.data.get('zoom')
                );
            }
        });
     $('#my-listbox').on('click', function (event) {//событие для добавление класса ссылки + открытие/сокрытие блоков с магазами
            $('#my-listbox .link').removeClass('active');
            $target = $(event.target);
            $target.addClass('active');
            id = event.target.id;//получаем id кликнутой ссылки для сравнения
            active_city = '#shops_list #'+id;
            if(active_city !== '#shops_list #all'){
            $('#shops_list .city-block').removeClass('d_block').addClass('d_none');
            $(active_city).removeClass('d_none').addClass('d_block');
            }
            if(active_city == '#shops_list #all'){//убираем сокрытие элементов списка под картой
              $('#shops_list .city-block').removeClass('d_none');
            }
        });
    myMap.controls.add(listBox, {float: 'left'});
<?
$shop_list = \R::getAll("получаем список магазов");
$shop_count = 0;
foreach($shop_list as $shop_data){
if($shop_data["lat"] and $shop_data["lon"]){//если нет координат - не добавляем на карту
++$shop_count;
$id_city = '';
$shop_city = '';
$id_city = $shop_data["city"];
$shop_city = \R::getAll("выбираем город по id = '$id_city'");?>
      myPlacemark<?=$shop_count?> = new ymaps.Placemark([<?=$shop_data["lat"];?>,<?=$shop_data["lon"];?>], {balloonContentHeader: "<?=$shop_city[0]['city'];?>",balloonContentBody: "<?=$shop_data["name"];?>",balloonContentFooter: "<?=$shop_data["address"];?>",});
      myMap.geoObjects.add(myPlacemark<?=$shop_count?>);
      
<?}}?>
}
</script>
          <div id="map"></div><!-- сама карта//-->
				</div>
				<?php foreach ($cities as $city) : ?>
					<?php if (!empty($keys[$city['city']])) : ?>
						<section id="<?= $city['code']; ?>" class="city-block"><!-- разбивка списка по городам //-->
							<div class="shop-list">
								<?php foreach ($keys[$city['city']] as $shop) : ?>
									<div class="shop">
										<h3 class="shop-name"><?= $shop['name']; ?></h3>
										<?php if (!empty($shop['address'])) : ?>
											<address class="shop-address">описание точки на карте</address>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>
				<?php endforeach; ?>