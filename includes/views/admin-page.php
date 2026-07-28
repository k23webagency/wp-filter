<?php
/**
 * Разметка страницы настроек PF Filter.
 * Переменные приходят из PF_Admin::render_page(): $settings, $available_fields,
 * $real_depth, $configured_depth, $categories, $available_templates.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap pf-filter-admin">
	<h1><?php esc_html_e( 'PF Filter — настройки', 'pf-filter' ); ?></h1>

	<?php if ( $real_depth > $configured_depth ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: 1: реальная глубина дерева категорий, 2: настроенная глубина шаблона */
					esc_html__( 'ⓘ Реальная глубина дерева категорий — %1$d уровней, шаблон поддерживает %2$d. Категории глубже поддерживаемого уровня не будут показаны в фильтре. Расширьте шаблон category-tree или увеличьте «Глубину дерева» ниже.', 'pf-filter' ),
					(int) $real_depth,
					(int) $configured_depth
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper pf-tabs">
		<a href="#pf-tab-global" class="nav-tab nav-tab-active" data-tab="global"><?php esc_html_e( 'Общие настройки', 'pf-filter' ); ?></a>
		<a href="#pf-tab-groups" class="nav-tab" data-tab="groups"><?php esc_html_e( 'Группы фильтров', 'pf-filter' ); ?></a>
		<a href="#pf-tab-sort" class="nav-tab" data-tab="sort"><?php esc_html_e( 'Сортировка', 'pf-filter' ); ?></a>
		<a href="#pf-tab-categories" class="nav-tab" data-tab="categories"><?php esc_html_e( 'По категориям', 'pf-filter' ); ?></a>
		<a href="#pf-tab-diagnostics" class="nav-tab" data-tab="diagnostics"><?php esc_html_e( 'Диагностика', 'pf-filter' ); ?></a>
	</h2>

	<form method="post" action="options.php">
		<?php settings_fields( 'pf_filter_settings_group' ); ?>

		<div class="pf-tab-panel" id="pf-tab-global" data-tab="global">
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Логика между группами', 'pf-filter' ); ?></th>
					<td>
						<label><input type="radio" name="pf_filter_settings[logic]" value="and" <?php checked( $settings['logic'], 'and' ); ?> /> AND</label>
						&nbsp;&nbsp;
						<label><input type="radio" name="pf_filter_settings[logic]" value="or" <?php checked( $settings['logic'], 'or' ); ?> /> OR</label>
					</td>
				</tr>
				<tr>
					<th><label for="pf-search-threshold"><?php esc_html_e( 'Порог поиска', 'pf-filter' ); ?></label></th>
					<td>
						<input type="number" min="1" id="pf-search-threshold" name="pf_filter_settings[search_threshold]" value="<?php echo esc_attr( $settings['search_threshold'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Показывать поиск внутри группы если значений больше N.', 'pf-filter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Показывать счётчик товаров', 'pf-filter' ); ?></th>
					<td>
						<label><input type="checkbox" name="pf_filter_settings[show_counts]" value="1" <?php checked( ! empty( $settings['show_counts'] ) ); ?> /> <?php esc_html_e( 'включено', 'pf-filter' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><label for="pf-tree-depth"><?php esc_html_e( 'Глубина дерева категорий', 'pf-filter' ); ?></label></th>
					<td>
						<input type="number" min="1" id="pf-tree-depth" name="pf_filter_settings[tree_depth]" value="<?php echo esc_attr( $configured_depth ); ?>" />
						<p class="description"><?php esc_html_e( 'Реальная глубина на сайте: ', 'pf-filter' ); ?><?php echo esc_html( $real_depth ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="pf-scan-url"><?php esc_html_e( 'URL для сканирования шаблонов', 'pf-filter' ); ?></label></th>
					<td>
						<input type="url" id="pf-scan-url" class="regular-text" name="pf_filter_settings[scan_url]" value="<?php echo esc_attr( $settings['scan_url'] ); ?>" placeholder="https://site.ru/catalog/" />
						<p class="description"><?php esc_html_e( 'Страница с разметкой pf-template. Если не задано — используется стандартный список из 7 шаблонов.', 'pf-filter' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="pf-tab-panel" id="pf-tab-groups" data-tab="groups" style="display:none">
			<p><?php esc_html_e( 'Порядок строк = порядок групп в форме фильтра. Перетаскивайте за ☰.', 'pf-filter' ); ?></p>
			<table class="widefat pf-groups-table" data-name-prefix="pf_filter_settings[groups]">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Вкл.', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Поле', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Название', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Шаблон', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Логика в группе', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Range: шаг / ед.', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Дерево: глубина', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Цвета', 'pf-filter' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody class="pf-groups-body">
					<?php
					if ( empty( $settings['groups'] ) ) {
						$index = 0;
						foreach ( $available_fields as $f ) {
							$this->render_group_row(
								'pf_filter_settings[groups]',
								$index,
								array(
									'field'    => $f['field'],
									'label'    => $f['label'],
									'template' => 'price' === $f['field'] ? 'range' : ( 'product_cat' === $f['field'] ? 'category-tree' : 'checkbox' ),
									'logic'    => 'or',
									'enabled'  => true,
								),
								$available_fields,
								$available_templates
							);
							++$index;
						}
					} else {
						foreach ( $settings['groups'] as $index => $group ) {
							$this->render_group_row( 'pf_filter_settings[groups]', $index, $group, $available_fields, $available_templates );
						}
					}
					?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button pf-add-group"><?php esc_html_e( '+ Добавить группу', 'pf-filter' ); ?></button>
			</p>
			<template id="pf-group-row-template">
				<table><tbody>
				<?php
				$this->render_group_row(
					'pf_filter_settings[groups]',
					'__INDEX__',
					array(
						'field'    => $available_fields[0]['field'] ?? '',
						'label'    => $available_fields[0]['label'] ?? '',
						'template' => 'checkbox',
						'logic'    => 'or',
						'enabled'  => true,
					),
					$available_fields,
					$available_templates
				);
				?>
				</tbody></table>
			</template>
		</div>

		<div class="pf-tab-panel" id="pf-tab-sort" data-tab="sort" style="display:none">
			<table class="widefat pf-sort-table">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Значение WooCommerce', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Подпись', 'pf-filter' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody class="pf-sort-body">
					<?php foreach ( $settings['sort_options'] as $index => $option ) : ?>
						<tr class="pf-sort-row" draggable="true">
							<td class="pf-drag-handle">☰</td>
							<td>
								<select name="pf_filter_settings[sort_options][<?php echo esc_attr( $index ); ?>][value]">
									<?php foreach ( PF_Query::ORDERBY_WHITELIST as $value ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $option['value'], $value ); ?>><?php echo esc_html( $value ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<input type="text" name="pf_filter_settings[sort_options][<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $option['label'] ); ?>" />
							</td>
							<td><button type="button" class="button-link-delete pf-remove-row"><?php esc_html_e( 'Удалить', 'pf-filter' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button pf-add-sort"><?php esc_html_e( '+ Добавить опцию', 'pf-filter' ); ?></button></p>
			<template id="pf-sort-row-template">
				<table><tbody>
				<tr class="pf-sort-row" draggable="true">
					<td class="pf-drag-handle">☰</td>
					<td>
						<select name="pf_filter_settings[sort_options][__INDEX__][value]">
							<?php foreach ( PF_Query::ORDERBY_WHITELIST as $value ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $value ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="text" name="pf_filter_settings[sort_options][__INDEX__][label]" value="" /></td>
					<td><button type="button" class="button-link-delete pf-remove-row"><?php esc_html_e( 'Удалить', 'pf-filter' ); ?></button></td>
				</tr>
				</tbody></table>
			</template>
		</div>

		<div class="pf-tab-panel" id="pf-tab-categories" data-tab="categories" style="display:none">
			<p><?php esc_html_e( 'Переопределение набора групп для конкретной категории товаров. Если для категории нет переопределения — используется глобальный список.', 'pf-filter' ); ?></p>

			<select id="pf-category-select">
				<option value=""><?php esc_html_e( '— выбрать категорию —', 'pf-filter' ); ?></option>
				<?php foreach ( $categories as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php foreach ( $categories as $cat ) : ?>
				<?php $override_groups = $settings['category_overrides'][ $cat->slug ]['groups'] ?? array(); ?>
				<div class="pf-category-override" data-category="<?php echo esc_attr( $cat->slug ); ?>" style="display:none">
					<h3><?php echo esc_html( $cat->name ); ?></h3>
					<table class="widefat pf-groups-table" data-name-prefix="pf_filter_settings[category_overrides][<?php echo esc_attr( $cat->slug ); ?>][groups]">
						<thead>
							<tr>
								<th></th>
								<th><?php esc_html_e( 'Вкл.', 'pf-filter' ); ?></th>
								<th><?php esc_html_e( 'Поле', 'pf-filter' ); ?></th>
								<th><?php esc_html_e( 'Название', 'pf-filter' ); ?></th>
								<th><?php esc_html_e( 'Шаблон', 'pf-filter' ); ?></th>
								<th><?php esc_html_e( 'Логика в группе', 'pf-filter' ); ?></th>
								<th><?php esc_html_e( 'Range: шаг / ед.', 'pf-filter' ); ?></th>
								<th><?php esc_html_e( 'Дерево: глубина', 'pf-filter' ); ?></th>
								<th><?php esc_html_e( 'Цвета', 'pf-filter' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody class="pf-groups-body">
							<?php foreach ( $override_groups as $index => $group ) : ?>
								<?php
								$this->render_group_row(
									'pf_filter_settings[category_overrides][' . $cat->slug . '][groups]',
									$index,
									$group,
									$available_fields,
									$available_templates
								);
								?>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><button type="button" class="button pf-add-group"><?php esc_html_e( '+ Добавить группу', 'pf-filter' ); ?></button></p>
				</div>
			<?php endforeach; ?>
		</div>

		<?php submit_button(); ?>
	</form>

	<div class="pf-tab-panel" id="pf-tab-diagnostics" data-tab="diagnostics" style="display:none">
		<p><?php esc_html_e( 'Проверка окружения сервера, разметки страницы каталога и собственных REST-эндпоинтов плагина. Результаты не кешируются — каждый запуск делает свежие проверки.', 'pf-filter' ); ?></p>

		<p>
			<label for="pf-diag-url"><?php esc_html_e( 'URL страницы каталога для анализа разметки:', 'pf-filter' ); ?></label><br />
			<input type="url" id="pf-diag-url" class="regular-text" value="<?php echo esc_attr( $diagnostics_default_url ); ?>" />
			<button type="button" class="button button-primary" id="pf-diag-run"><?php esc_html_e( 'Запустить проверку', 'pf-filter' ); ?></button>
			<span class="spinner" id="pf-diag-spinner"></span>
		</p>

		<h2><?php esc_html_e( 'Окружение', 'pf-filter' ); ?></h2>
		<div id="pf-diag-environment" class="pf-diag-section"><p class="description"><?php esc_html_e( 'Нажмите «Запустить проверку», чтобы увидеть результаты.', 'pf-filter' ); ?></p></div>

		<h2><?php esc_html_e( 'Анализ разметки страницы', 'pf-filter' ); ?></h2>
		<div id="pf-diag-markup" class="pf-diag-section"></div>

		<h2><?php esc_html_e( 'REST API', 'pf-filter' ); ?></h2>
		<div id="pf-diag-restapi" class="pf-diag-section"></div>
	</div>
</div>
