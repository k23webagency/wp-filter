<?php
/**
 * Разметка страницы настроек PF Filter.
 * Переменные приходят из PF_Admin::render_page(): $settings, $post_types,
 * $available_fields, $configured_depth, $available_templates,
 * $profiles, $active_profile_id.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap pf-filter-admin">
	<h1><?php esc_html_e( 'PF Filter — настройки', 'pf-filter' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Настройки сохранены.', 'pf-filter' ); ?></p></div>
	<?php elseif ( isset( $_GET['created'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Профиль создан.', 'pf-filter' ); ?></p></div>
	<?php elseif ( isset( $_GET['duplicated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Профиль продублирован.', 'pf-filter' ); ?></p></div>
	<?php elseif ( isset( $_GET['deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Профиль удалён.', 'pf-filter' ); ?></p></div>
	<?php elseif ( isset( $_GET['error'] ) && 'last_profile' === $_GET['error'] ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'Нельзя удалить последний оставшийся профиль.', 'pf-filter' ); ?></p></div>
	<?php elseif ( isset( $_GET['error'] ) && 'id_taken' === $_GET['error'] ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'Остальные настройки сохранены, но ID профиля не изменён — запрошенное значение уже занято другим профилем.', 'pf-filter' ); ?></p></div>
	<?php elseif ( isset( $_GET['error'] ) ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'Профиль не найден.', 'pf-filter' ); ?></p></div>
	<?php endif; ?>

	<div class="pf-profile-bar" style="margin:12px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
		<form method="get" style="display:inline-block;">
			<input type="hidden" name="page" value="<?php echo esc_attr( PF_Admin::PAGE_SLUG ); ?>" />
			<label for="pf-profile-switch"><strong><?php esc_html_e( 'Профиль:', 'pf-filter' ); ?></strong></label>
			<select id="pf-profile-switch" name="profile" onchange="this.form.submit()">
				<?php foreach ( $profiles as $pid => $p ) : ?>
					<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $active_profile_id, $pid ); ?>><?php echo esc_html( $p['name'] ?? $pid ); ?> — <?php echo esc_html( $pid ); ?> (<?php echo esc_html( $post_types[ $p['post_type'] ] ?? $p['post_type'] ); ?>)</option>
				<?php endforeach; ?>
			</select>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<?php wp_nonce_field( 'pf_duplicate_profile' ); ?>
			<input type="hidden" name="action" value="pf_duplicate_profile" />
			<input type="hidden" name="profile" value="<?php echo esc_attr( $active_profile_id ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Дублировать профиль', 'pf-filter' ); ?></button>
		</form>

		<?php if ( count( $profiles ) > 1 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Удалить профиль без возможности восстановления?', 'pf-filter' ) ); ?>');">
				<?php wp_nonce_field( 'pf_delete_profile' ); ?>
				<input type="hidden" name="action" value="pf_delete_profile" />
				<input type="hidden" name="profile" value="<?php echo esc_attr( $active_profile_id ); ?>" />
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Удалить профиль', 'pf-filter' ); ?></button>
			</form>
		<?php endif; ?>

		<details style="display:inline-block;">
			<summary class="button" style="cursor:pointer;"><?php esc_html_e( '+ Новый профиль', 'pf-filter' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;display:flex;gap:6px;align-items:center;">
				<?php wp_nonce_field( 'pf_create_profile' ); ?>
				<input type="hidden" name="action" value="pf_create_profile" />
				<input type="text" name="name" placeholder="<?php esc_attr_e( 'Имя профиля', 'pf-filter' ); ?>" required />
				<select name="post_type">
					<?php foreach ( $post_types as $slug => $pt_label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $pt_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Создать', 'pf-filter' ); ?></button>
			</form>
		</details>
	</div>

	<h2 class="nav-tab-wrapper pf-tabs">
		<a href="#pf-tab-global" class="nav-tab nav-tab-active" data-tab="global"><?php esc_html_e( 'Общие настройки', 'pf-filter' ); ?></a>
		<a href="#pf-tab-groups" class="nav-tab" data-tab="groups"><?php esc_html_e( 'Группы фильтров', 'pf-filter' ); ?></a>
		<a href="#pf-tab-sort" class="nav-tab" data-tab="sort"><?php esc_html_e( 'Сортировка', 'pf-filter' ); ?></a>
		<a href="#pf-tab-diagnostics" class="nav-tab" data-tab="diagnostics"><?php esc_html_e( 'Диагностика', 'pf-filter' ); ?></a>
	</h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'pf_save_profile' ); ?>
		<input type="hidden" name="action" value="pf_save_profile" />
		<input type="hidden" name="profile" value="<?php echo esc_attr( $active_profile_id ); ?>" />

		<div class="pf-tab-panel" id="pf-tab-global" data-tab="global">
			<table class="form-table">
				<tr>
					<th><label for="pf-profile-name"><?php esc_html_e( 'Название профиля', 'pf-filter' ); ?></label></th>
					<td>
						<input type="text" id="pf-profile-name" name="pf_filter_settings[name]" value="<?php echo esc_attr( $settings['name'] ?? '' ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th><label for="pf-profile-id"><?php esc_html_e( 'ID профиля', 'pf-filter' ); ?></label></th>
					<td>
						<input type="text" id="pf-profile-id" name="profile_id" value="<?php echo esc_attr( $active_profile_id ); ?>" class="regular-text code" />
						<p class="description"><?php esc_html_e( 'Это значение атрибута pf-profile в разметке темы. При создании профиля заполняется автоматически из названия, но его можно изменить здесь — например, на короткий понятный слаг. Допустимы латиница, цифры, дефис (остальное будет автоматически приведено к такому виду). Если уже опубликованная разметка использует pf-profile со старым значением — её нужно будет поправить вручную на новое.', 'pf-filter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="pf-post-type"><?php esc_html_e( 'Тип записи', 'pf-filter' ); ?></label></th>
					<td>
						<select id="pf-post-type" name="pf_filter_settings[post_type]">
							<?php foreach ( $post_types as $slug => $post_type_label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['post_type'], $slug ); ?>><?php echo esc_html( $post_type_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Какой тип записи фильтрует плагин. При смене типа записи группы фильтров, настроенные под старый тип, нужно будет пересобрать вручную — поле группы, ссылающееся на таксономию другого типа записи, работать не будет.', 'pf-filter' ); ?></p>
					</td>
				</tr>
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
						<?php if ( ! empty( $settings['show_counts'] ) && $scan_xpath && ! PF_Diagnostics::has_attribute( $scan_xpath, 'pf-filter-count' ) ) : ?>
							<p class="description" style="color:#b32d2e">⚠ <?php esc_html_e( 'На странице магазина не найден ни один [pf-filter-count] — счётчики показывать негде.', 'pf-filter' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Отражать фильтры в URL', 'pf-filter' ); ?></th>
					<td>
						<label><input type="checkbox" name="pf_filter_settings[sync_url]" value="1" <?php checked( ! empty( $settings['sync_url'] ) ); ?> /> <?php esc_html_e( 'включено', 'pf-filter' ); ?></label>
						<p class="description"><?php esc_html_e( 'Если включено — применённые фильтры записываются в URL страницы (можно поделиться ссылкой, работает кнопка "назад" браузера). Если выключено — URL всегда остаётся чистым, состояние фильтров между перезагрузками страницы не сохраняется.', 'pf-filter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="pf-tree-depth"><?php esc_html_e( 'Глубина дерева категорий (по умолчанию)', 'pf-filter' ); ?></label></th>
					<td>
						<input type="number" min="1" id="pf-tree-depth" name="pf_filter_settings[tree_depth]" value="<?php echo esc_attr( $configured_depth ); ?>" />
						<p class="description"><?php esc_html_e( 'Используется для групп с шаблоном category-tree, у которых глубина не задана явно в самой группе. Реальная глубина по каждой такой группе сравнивается с эффективной на вкладке «Группы фильтров».', 'pf-filter' ); ?></p>
					</td>
				</tr>
				<?php
				$pagination_labels = array(
					'pages'     => __( 'Страницы', 'pf-filter' ),
					'load-more' => __( 'Кнопка «Загрузить ещё»', 'pf-filter' ),
					'both'      => __( 'Кнопка + страницы вместе', 'pf-filter' ),
					'infinite'  => __( 'Автозагрузка по скроллу', 'pf-filter' ),
				);
				$configured_strategy = $settings['pagination_strategy'];
				$effective_strategy  = $configured_strategy;
				if ( is_array( $available_pagination ) && empty( $available_pagination[ $configured_strategy ] ) ) {
					$fallback = array_search( true, $available_pagination, true );
					if ( false !== $fallback ) {
						$effective_strategy = $fallback;
					}
				}
				?>
				<tr>
					<th><?php esc_html_e( 'Стратегия пагинации', 'pf-filter' ); ?></th>
					<td>
						<?php foreach ( $pagination_labels as $value => $label ) : ?>
							<?php $is_unavailable = is_array( $available_pagination ) && empty( $available_pagination[ $value ] ); ?>
							<label<?php echo $is_unavailable ? ' style="opacity:.5"' : ''; ?>>
								<input type="radio" name="pf_filter_settings[pagination_strategy]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $effective_strategy, $value ); ?> <?php disabled( $is_unavailable ); ?> />
								<?php echo esc_html( $label ); ?>
								<?php if ( $is_unavailable ) : ?>
									<?php esc_html_e( '(нет нужных элементов в вёрстке)', 'pf-filter' ); ?>
								<?php endif; ?>
							</label><br />
						<?php endforeach; ?>
						<?php if ( null === $available_pagination ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL страницы магазина */
									esc_html__( 'Не удалось загрузить страницу магазина (%s) для проверки разметки — показаны все стратегии без ограничений.', 'pf-filter' ),
									esc_html( $diagnostics_default_url )
								);
								?>
							</p>
						<?php elseif ( $effective_strategy !== $configured_strategy ) : ?>
							<p class="description" style="color:#b32d2e">
								<?php
								printf(
									/* translators: 1: сохранённая стратегия, 2: стратегия по факту разметки */
									esc_html__( 'Сохранённая стратегия «%1$s» недоступна на странице магазина — по умолчанию выбрана доступная «%2$s». Сохраните настройки, чтобы применить.', 'pf-filter' ),
									esc_html( $pagination_labels[ $configured_strategy ] ?? $configured_strategy ),
									esc_html( $pagination_labels[ $effective_strategy ] )
								);
								?>
							</p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Единый режим для всего сайта — какие элементы принадлежат другим стратегиям, плагин скрывает сам, даже если они есть в вёрстке.', 'pf-filter' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="pf-posts-per-page"><?php esc_html_e( 'Товаров на странице', 'pf-filter' ); ?></label></th>
					<td>
						<input type="number" min="1" id="pf-posts-per-page" name="pf_filter_settings[posts_per_page]" value="<?php echo esc_attr( $settings['posts_per_page'] ); ?>" style="width:80px" />
					</td>
				</tr>
			</table>
		</div>

		<div class="pf-tab-panel" id="pf-tab-groups" data-tab="groups" style="display:none">
			<p><?php esc_html_e( 'Порядок строк = порядок групп в форме фильтра. Перетаскивайте за ☰.', 'pf-filter' ); ?></p>
			<p class="description"><?php esc_html_e( 'Список групп общий для всего каталога. В конкретной категории показываются только те из включённых групп, для которых у товаров этой категории есть хотя бы одно значение — остальные скрываются автоматически.', 'pf-filter' ); ?></p>
			<table class="widefat pf-groups-table" data-name-prefix="pf_filter_settings[groups]">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Вкл.', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Поле', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Название', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Шаблон', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Логика в группе', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Поиск', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Range: шаг / ед.', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Дерево: глубина', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Цвета', 'pf-filter' ); ?></th>
						<th><?php esc_html_e( 'Сортировка значений', 'pf-filter' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody class="pf-groups-body">
					<?php
					if ( empty( $settings['groups'] ) ) {
						$index = 0;
						foreach ( $available_fields as $f ) {
							// Первый вариант из get_compatible_templates() — уже правильный
							// дефолт для типа этого поля (дерево для иерархической
							// таксономии, чекбоксы для плоской/custom_, диапазон для
							// числового meta/ACF-поля, включая 'price').
							$compatible = $this->attributes->get_compatible_templates( $f['field'] );
							$this->render_group_row(
								'pf_filter_settings[groups]',
								$index,
								array(
									'field'    => $f['field'],
									'label'    => $f['label'],
									'template' => $compatible[0] ?? 'checkbox',
									'logic'    => 'or',
									'enabled'  => true,
								),
								$available_fields,
								$available_templates,
								$scan_xpath
							);
							++$index;
						}
					} else {
						foreach ( $settings['groups'] as $index => $group ) {
							$this->render_group_row( 'pf_filter_settings[groups]', $index, $group, $available_fields, $available_templates, $scan_xpath );
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
					$available_templates,
					$scan_xpath
				);
				?>
				</tbody></table>
			</template>
		</div>

		<div class="pf-tab-panel" id="pf-tab-sort" data-tab="sort" style="display:none">
			<?php if ( $scan_xpath && ! PF_Diagnostics::has_attribute( $scan_xpath, 'pf-sort' ) ) : ?>
				<p class="description" style="color:#b32d2e">⚠ <?php esc_html_e( 'На странице магазина не найден [pf-sort] — блок сортировки в разметке отсутствует, эти настройки ни на что не повлияют.', 'pf-filter' ); ?></p>
			<?php endif; ?>
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
