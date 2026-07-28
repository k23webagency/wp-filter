=== PF Filter ===
Contributors: pf-filter
Tags: woocommerce, filter, ajax, catalog, facet
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
WC requires at least: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Движок AJAX-фильтрации каталога WooCommerce, работающий через HTML-атрибуты pf-* в разметке темы.

== Description ==

PF Filter не диктует внешний вид карточек товаров, сетки или фильтров — вся разметка остаётся полностью на стороне темы/верстальщика.
Плагин находит разметку по атрибутам `pf-*`, строит фасетный фильтр (чекбоксы, радио, теги, дропдауны, диапазон цены, дерево категорий),
запускает AJAX-запросы к REST API и обновляет список товаров, счётчики, пагинацию и активные фильтры без перезагрузки страницы.

Первая загрузка страницы рендерится стандартным WordPress/WooCommerce loop — плагин не вмешивается в первичный рендер.

Полное описание атрибутов, REST API и структуры конфигурации — см. техническую спецификацию проекта.

== Installation ==

1. Загрузите папку `pf-filter` в `/wp-content/plugins/`.
2. Активируйте плагин через меню «Плагины» в WordPress.
3. Убедитесь что WooCommerce активен.
4. Настройте группы фильтров и сортировку на странице Настройки → PF Filter.
5. Разметьте страницу каталога атрибутами `pf-form`, `pf-target`, `pf-list`, `pf-output`, `pf-templates` и т.д.

== Changelog ==

= 1.0.0 =
* Первая версия: REST API `/config` и `/products`, JS-движок фильтрации, страница настроек в админке.
