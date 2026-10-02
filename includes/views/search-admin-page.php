<?php
/**
 * Разметка страницы настроек модуля поиска (Настройки → PF Search).
 * Переменные приходят из PF_Search_Admin::render_page(): $enabled,
 * $environment_ok, $profiles, $profile_id, $profile, $post_types,
 * $acf_by_type, $state, $summary, $has_wc, $template_info, $analytics,
 * $top_queries, $zero_queries, $template_roots.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- только флаги уведомлений после редиректа.
$pfs_notices = array(
	'updated'    => __( 'Профиль сохранён.', 'pf-filter' ),
	'created'    => __( 'Профиль создан.', 'pf-filter' ),
	'duplicated' => __( 'Профиль продублирован.', 'pf-filter' ),
	'deleted'    => __( 'Профиль удалён.', 'pf-filter' ),
	'enabled'    => __( 'Модуль поиска включён, индексация запущена.', 'pf-filter' ),
	'disabled'   => __( 'Модуль поиска выключен. Индекс сохранён в базе, при включении он обновится.', 'pf-filter' ),
	'reindex'    => __( 'Запущена переиндексация. Поиск работает и во время неё.', 'pf-filter' ),
	'analytics_on'      => __( 'Аналитика запросов включена.', 'pf-filter' ),
	'analytics_off'     => __( 'Аналитика запросов выключена. Собранная статистика сохранена.', 'pf-filter' ),
	'analytics_cleared' => __( 'Статистика запросов очищена.', 'pf-filter' ),
);
$pfs_errors  = array(
	'not_found'    => __( 'Профиль не найден.', 'pf-filter' ),
	'last_profile' => __( 'Нельзя удалить последний оставшийся профиль.', 'pf-filter' ),
	'id_taken'     => __( 'Остальные настройки сохранены, но ID профиля не изменён — это значение уже занято другим профилем.', 'pf-filter' ),
);
$pfs_error   = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
// phpcs:enable

$pfs_types_in_profile = PF_Search_Config::get_profile_types( $profile );
?>
<div class="wrap pf-filter-admin pfs-admin">
	<h1><?php esc_html_e( 'PF Search — модуль поиска', 'pf-filter' ); ?></h1>

	<?php foreach ( $pfs_notices as $pfs_flag => $pfs_text ) : ?>
		<?php if ( isset( $_GET[ $pfs_flag ] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $pfs_text ); ?></p></div>
		<?php endif; ?>
	<?php endforeach; ?>
	<?php if ( $pfs_error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $pfs_errors[ $pfs_error ] ?? $pfs_errors['not_found'] ); ?></p></div>
	<?php endif; ?>

	<div class="pfs-card">
		<h2><?php esc_html_e( 'Модуль', 'pf-filter' ); ?></h2>
		<?php if ( ! $environment_ok ) : ?>
			<p class="pfs-error"><?php esc_html_e( 'На сервере нет PHP-расширения mbstring — модуль поиска не может работать. Попросите хостинг включить mbstring. Фильтр и сайт работают как обычно.', 'pf-filter' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pfs_toggle_module' ); ?>
				<input type="hidden" name="action" value="pfs_toggle_module" />
				<?php if ( $enabled ) : ?>
					<p><strong class="pfs-ok"><?php esc_html_e( 'Включён.', 'pf-filter' ); ?></strong> <?php esc_html_e( 'Атрибуты pfs-* в вёрстке темы обслуживаются, индекс обновляется автоматически при сохранении записей.', 'pf-filter' ); ?></p>
					<button type="submit" class="button"><?php esc_html_e( 'Выключить модуль', 'pf-filter' ); ?></button>
				<?php else : ?>
					<p><?php esc_html_e( 'Выключен. Пока модуль выключен, плагин не строит индекс и не обрабатывает атрибуты pfs-* — фильтр работает как обычно.', 'pf-filter' ); ?></p>
					<input type="hidden" name="enable" value="1" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Включить модуль поиска', 'pf-filter' ); ?></button>
				<?php endif; ?>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( $enabled && $profile_id ) : ?>

		<div class="pfs-card" id="pfs-index-card">
			<h2><?php esc_html_e( 'Индекс', 'pf-filter' ); ?></h2>
			<p class="pfs-index-summary">
				<?php
				printf(
					/* translators: 1: записей, 2: слов в словаре */
					esc_html__( 'В индексе записей: %1$s, слов в словаре: %2$s.', 'pf-filter' ),
					'<strong data-pfs-summary="docs">' . esc_html( number_format_i18n( $summary['docs'] ) ) . '</strong>',
					'<strong data-pfs-summary="terms">' . esc_html( number_format_i18n( $summary['terms'] ) ) . '</strong>'
				);
				?>
				<?php foreach ( $summary['by_type'] as $pfs_type => $pfs_count ) : ?>
					<span class="pfs-muted">· <?php echo esc_html( ( $post_types[ $pfs_type ] ?? $pfs_type ) . ': ' . number_format_i18n( $pfs_count ) ); ?></span>
				<?php endforeach; ?>
			</p>
			<div class="pfs-progress" <?php echo 'running' === $state['status'] ? '' : 'hidden'; ?>>
				<div class="pfs-progress-bar"><span style="width:<?php echo esc_attr( $state['total'] ? (int) round( 100 * $state['done'] / $state['total'] ) : 0 ); ?>%"></span></div>
				<p class="pfs-progress-text"></p>
			</div>
			<?php if ( 'running' !== $state['status'] && $state['finished'] ) : ?>
				<p class="pfs-muted">
					<?php
					printf(
						/* translators: %s: дата и время */
						esc_html__( 'Последняя полная индексация: %s.', 'pf-filter' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['finished'] ) )
					);
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pfs_reindex' ); ?>
				<input type="hidden" name="action" value="pfs_reindex" />
				<input type="hidden" name="profile" value="<?php echo esc_attr( $profile_id ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Переиндексировать всё', 'pf-filter' ); ?></button>
				<span class="description"><?php esc_html_e( 'Обычно не нужно: индекс обновляется сам. Пригодится после массового импорта в обход WordPress.', 'pf-filter' ); ?></span>
			</form>
		</div>

		<div class="pf-profile-bar" style="margin:16px 0 12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
			<form method="get" style="display:inline-block;">
				<input type="hidden" name="page" value="<?php echo esc_attr( PF_Search_Admin::PAGE_SLUG ); ?>" />
				<label for="pfs-profile-switch"><strong><?php esc_html_e( 'Профиль поиска:', 'pf-filter' ); ?></strong></label>
				<select id="pfs-profile-switch" name="profile" onchange="this.form.submit()">
					<?php foreach ( $profiles as $pfs_pid => $pfs_p ) : ?>
						<option value="<?php echo esc_attr( $pfs_pid ); ?>" <?php selected( $profile_id, $pfs_pid ); ?>><?php echo esc_html( $pfs_p['name'] . ' — ' . $pfs_pid ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( 'pfs_duplicate_profile' ); ?>
				<input type="hidden" name="action" value="pfs_duplicate_profile" />
				<input type="hidden" name="profile" value="<?php echo esc_attr( $profile_id ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Дублировать профиль', 'pf-filter' ); ?></button>
			</form>

			<?php if ( count( $profiles ) > 1 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Удалить профиль поиска без возможности восстановления?', 'pf-filter' ) ); ?>');">
					<?php wp_nonce_field( 'pfs_delete_profile' ); ?>
					<input type="hidden" name="action" value="pfs_delete_profile" />
					<input type="hidden" name="profile" value="<?php echo esc_attr( $profile_id ); ?>" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Удалить профиль', 'pf-filter' ); ?></button>
				</form>
			<?php endif; ?>

			<details style="display:inline-block;">
				<summary class="button" style="cursor:pointer;"><?php esc_html_e( '+ Новый профиль', 'pf-filter' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;display:flex;gap:6px;align-items:center;">
					<?php wp_nonce_field( 'pfs_create_profile' ); ?>
					<input type="hidden" name="action" value="pfs_create_profile" />
					<input type="text" name="name" placeholder="<?php esc_attr_e( 'Имя профиля', 'pf-filter' ); ?>" required />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Создать', 'pf-filter' ); ?></button>
				</form>
			</details>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pfs-card">
			<?php wp_nonce_field( 'pfs_save_profile' ); ?>
			<input type="hidden" name="action" value="pfs_save_profile" />
			<input type="hidden" name="profile" value="<?php echo esc_attr( $profile_id ); ?>" />

			<h2><?php esc_html_e( 'Настройки профиля', 'pf-filter' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="pfs-name"><?php esc_html_e( 'Название профиля', 'pf-filter' ); ?></label></th>
					<td><input type="text" id="pfs-name" name="pfs[name]" value="<?php echo esc_attr( $profile['name'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="pfs-id"><?php esc_html_e( 'ID профиля', 'pf-filter' ); ?></label></th>
					<td>
						<input type="text" id="pfs-id" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>" class="regular-text code" />
						<p class="description"><?php esc_html_e( 'Значение атрибута pf-search в разметке темы: pf-search pf-search-profile="это-значение". Латиница, цифры, дефис. Если уже опубликованная разметка использует старое значение — её нужно будет поправить вручную.', 'pf-filter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Где искать', 'pf-filter' ); ?></th>
					<td>
						<label><input type="radio" name="pfs[type_mode]" value="fixed" <?php checked( $profile['type_mode'], 'fixed' ); ?> data-pfs-type-mode /> <?php esc_html_e( 'Задано здесь — один тип записей', 'pf-filter' ); ?></label><br />
						<label><input type="radio" name="pfs[type_mode]" value="visitor" <?php checked( $profile['type_mode'], 'visitor' ); ?> data-pfs-type-mode /> <?php esc_html_e( 'Выбирает посетитель — кнопками рядом с полем поиска', 'pf-filter' ); ?></label>

						<div class="pfs-mode-panel" data-pfs-mode="fixed">
							<select name="pfs[post_type]">
								<?php foreach ( $post_types as $pfs_slug => $pfs_label ) : ?>
									<option value="<?php echo esc_attr( $pfs_slug ); ?>" <?php selected( $profile['post_type'], $pfs_slug ); ?>><?php echo esc_html( $pfs_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Кнопка выбора типа [pf-search="type"] в этом режиме не нужна — если она есть в вёрстке, плагин её скроет.', 'pf-filter' ); ?></p>
						</div>

						<div class="pfs-mode-panel" data-pfs-mode="visitor">
							<table class="widefat striped pfs-types-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Доступен', 'pf-filter' ); ?></th>
										<th><?php esc_html_e( 'Тип записей', 'pf-filter' ); ?></th>
										<th><?php esc_html_e( 'Подпись кнопки', 'pf-filter' ); ?></th>
										<th><?php esc_html_e( 'Порядок', 'pf-filter' ); ?></th>
										<th><?php esc_html_e( 'По умолчанию', 'pf-filter' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $post_types as $pfs_slug => $pfs_label ) : ?>
										<?php
										$pfs_pos = array_search( $pfs_slug, $profile['allowed_types'], true );
										?>
										<tr>
											<td><input type="checkbox" name="pfs[types][<?php echo esc_attr( $pfs_slug ); ?>][enabled]" value="1" <?php checked( false !== $pfs_pos ); ?> data-pfs-allowed-type="<?php echo esc_attr( $pfs_slug ); ?>" /></td>
											<td><?php echo esc_html( $pfs_label ); ?> <code><?php echo esc_html( $pfs_slug ); ?></code></td>
											<td><input type="text" name="pfs[types][<?php echo esc_attr( $pfs_slug ); ?>][label]" value="<?php echo esc_attr( $profile['type_labels'][ $pfs_slug ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $pfs_label ); ?>" /></td>
											<td><input type="number" min="0" style="width:70px" name="pfs[types][<?php echo esc_attr( $pfs_slug ); ?>][order]" value="<?php echo esc_attr( false !== $pfs_pos ? $pfs_pos + 1 : 0 ); ?>" /></td>
											<td><input type="radio" name="pfs[default_type]" value="<?php echo esc_attr( $pfs_slug ); ?>" <?php checked( $profile['default_type'], $pfs_slug ); ?> /></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description"><?php esc_html_e( 'В вёрстке размечается только одна кнопка [pf-search="type"] — плагин подставит в неё первый тип по порядку и создаст по её образцу кнопки для остальных. Одновременно выбран один тип. Пустая подпись — название типа записей из WordPress.', 'pf-filter' ); ?></p>
						</div>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Где искать внутри записи', 'pf-filter' ); ?></th>
					<td>
						<?php
						$pfs_field_labels = array(
							'title'   => __( 'Заголовок', 'pf-filter' ),
							'content' => __( 'Текст записи', 'pf-filter' ),
							'excerpt' => __( 'Отрывок (краткое описание)', 'pf-filter' ),
							'sku'     => __( 'Артикул (товары WooCommerce, включая артикулы вариаций)', 'pf-filter' ),
							'terms'   => __( 'Рубрики, метки, атрибуты и другие таксономии', 'pf-filter' ),
						);
						?>
						<?php foreach ( $pfs_field_labels as $pfs_key => $pfs_label ) : ?>
							<?php
							if ( 'sku' === $pfs_key && ! $has_wc ) {
								continue;
							}
							?>
							<label><input type="checkbox" name="pfs[fields][<?php echo esc_attr( $pfs_key ); ?>]" value="1" <?php checked( ! empty( $profile['fields'][ $pfs_key ] ) ); ?> /> <?php echo esc_html( $pfs_label ); ?></label><br />
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'ACF-поля', 'pf-filter' ); ?></th>
					<td>
						<?php if ( ! $acf_by_type ) : ?>
							<p class="description"><?php esc_html_e( 'ACF не активен или у типов записей нет полей ACF.', 'pf-filter' ); ?></p>
						<?php else : ?>
							<?php foreach ( $acf_by_type as $pfs_type => $pfs_fields ) : ?>
								<fieldset class="pfs-acf-type" data-pfs-acf-type="<?php echo esc_attr( $pfs_type ); ?>">
									<legend><strong><?php echo esc_html( $post_types[ $pfs_type ] ?? $pfs_type ); ?></strong></legend>
									<?php foreach ( $pfs_fields as $pfs_field ) : ?>
										<label><input type="checkbox" name="pfs[acf_fields][<?php echo esc_attr( $pfs_type ); ?>][]" value="<?php echo esc_attr( $pfs_field['name'] ); ?>" <?php checked( in_array( $pfs_field['name'], (array) ( $profile['acf_fields'][ $pfs_type ] ?? array() ), true ) ); ?> /> <?php echo esc_html( $pfs_field['label'] ); ?> <code><?php echo esc_html( $pfs_field['name'] ); ?></code></label><br />
									<?php endforeach; ?>
								</fieldset>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Отметьте поля с текстом, по которым имеет смысл искать (бренд, модель, характеристики). Картинки, файлы и связи не индексируются, даже если отмечены. Показаны поля только тех типов записей, по которым ищет профиль.', 'pf-filter' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Карточки выпадающего окна', 'pf-filter' ); ?></th>
					<td>
						<?php if ( $template_info ) : ?>
							<p>
								<span class="pfs-ok">✓</span>
								<?php
								printf(
									/* translators: 1: ID профиля, 2: файл темы */
									esc_html__( 'Блок [pf-search pf-search-profile="%1$s"] с циклом карточек найден в файле темы %2$s.', 'pf-filter' ),
									esc_html( $profile_id ),
									'<code>' . esc_html( $template_info['file'] ) . '</code>'
								);
								?>
							</p>
							<?php if ( $template_info['groups'] ) : ?>
								<table class="widefat striped pfs-types-table">
									<thead><tr><th><?php esc_html_e( 'Тип записей', 'pf-filter' ); ?></th><th><?php esc_html_e( 'Вариант карточек (pf-search-group)', 'pf-filter' ); ?></th></tr></thead>
									<tbody>
										<?php foreach ( $post_types as $pfs_slug => $pfs_label ) : ?>
											<tr data-pfs-for-type="<?php echo esc_attr( $pfs_slug ); ?>">
												<td><?php echo esc_html( $pfs_label ); ?></td>
												<td>
													<select name="pfs[group_variants][<?php echo esc_attr( $pfs_slug ); ?>]">
														<option value=""><?php esc_html_e( '— первый по порядку —', 'pf-filter' ); ?></option>
														<?php foreach ( $template_info['groups'] as $pfs_group ) : ?>
															<option value="<?php echo esc_attr( $pfs_group ); ?>" <?php selected( $profile['group_variants'][ $pfs_slug ] ?? '', $pfs_group ); ?>><?php echo esc_html( $pfs_group ); ?></option>
														<?php endforeach; ?>
													</select>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<p class="description"><?php esc_html_e( 'В вёрстке окна несколько вариантов карточек [pf-search-group] — выберите, каким рисовать каждый тип записей.', 'pf-filter' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: ID профиля */
									esc_html__( 'В PHP-файлах темы не найден блок [pf-search pf-search-profile="%s"] с циклом записей внутри [pf-search="results"]. Без него выпадающее окно не работает; поиск по Enter и страница результатов работают. Если окно в вёрстке не нужно — всё в порядке.', 'pf-filter' ),
									esc_html( $profile_id )
								);
								?>
							</p>
							<?php if ( $template_roots ) : ?>
								<p><strong><?php esc_html_e( 'Что найдено в теме:', 'pf-filter' ); ?></strong></p>
								<ul class="pfs-roots">
									<?php foreach ( $template_roots as $pfs_root ) : ?>
										<li>
											<code><?php echo esc_html( '' === $pfs_root['value'] ? 'pf-search' : 'pf-search pf-search-profile="' . $pfs_root['value'] . '"' ); ?></code>
											— <code><?php echo esc_html( $pfs_root['file'] ); ?></code>:
											<?php if ( '' !== $pfs_root['value'] && $pfs_root['value'] !== $profile_id ) : ?>
												<span class="pfs-warning">
													<?php
													printf(
														/* translators: 1: ID в вёрстке, 2: ID профиля */
														esc_html__( 'ID «%1$s» не совпадает с ID этого профиля «%2$s». Поменяйте поле «ID профиля» ниже на «%1$s» или значение pf-search в вёрстке.', 'pf-filter' ),
														esc_html( $pfs_root['value'] ),
														esc_html( $profile_id )
													);
													?>
												</span>
											<?php elseif ( ! $pfs_root['has_results'] ) : ?>
												<span class="pfs-warning"><?php esc_html_e( 'внутри нет [pf-search="results"].', 'pf-filter' ); ?></span>
											<?php elseif ( ! $pfs_root['has_loop'] ) : ?>
												<span class="pfs-warning"><?php esc_html_e( 'в [pf-search="results"] нет цикла записей — поставьте на него метку wp_query (например \'post_type\' => \'product\', \'posts_per_page\' => 1) и переэкспортируйте тему.', 'pf-filter' ); ?></span>
											<?php else : ?>
												<span class="pfs-muted"><?php esc_html_e( 'блок с циклом, но без ID — относится к первому профилю поиска.', 'pf-filter' ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'В файлах темы нет ни одного [pf-search="results"].', 'pf-filter' ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="pfs-limit"><?php esc_html_e( 'Результатов в выпадающем окне', 'pf-filter' ); ?></label></th>
					<td>
						<input type="number" min="1" max="<?php echo esc_attr( PF_Search_Admin::MAX_DROPDOWN_LIMIT ); ?>" id="pfs-limit" name="pfs[dropdown_limit]" value="<?php echo esc_attr( $profile['dropdown_limit'] ); ?>" style="width:80px" data-pfs-limit />
						<p class="description pfs-warning" data-pfs-limit-warning <?php echo (int) $profile['dropdown_limit'] > PF_Search_Config::DROPDOWN_LIMIT_WARN ? '' : 'hidden'; ?>>
							<?php
							printf(
								/* translators: %d: рекомендуемый максимум */
								esc_html__( 'Больше %d результатов в окне под полем повышают нагрузку на сервер: каждая карточка рендерится PHP-шаблоном темы на каждый запрос при наборе текста. Полная выдача всё равно доступна на странице результатов.', 'pf-filter' ),
								(int) PF_Search_Config::DROPDOWN_LIMIT_WARN
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="pfs-results-page"><?php esc_html_e( 'Страница результатов', 'pf-filter' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'pfs[results_page]',
								'id'                => 'pfs-results-page',
								'selected'          => (int) $profile['results_page'],
								'show_option_none'  => esc_html__( '— не выбрана —', 'pf-filter' ),
								'option_none_value' => '0',
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Куда ведут Enter и «Показать все результаты», если поле поиска стоит вне блока фильтра (например, в шапке). Если поле внутри блока фильтра [pf-profile], результаты показываются в его списке и эта настройка не используется.', 'pf-filter' ); ?></p>
					</td>
				</tr>
			</table>

			<details class="pfs-advanced">
				<summary><?php esc_html_e( 'Дополнительно: веса полей', 'pf-filter' ); ?></summary>
				<p class="description"><?php esc_html_e( 'Насколько совпадение в поле важнее при сортировке по релевантности. Значения по умолчанию подходят большинству сайтов.', 'pf-filter' ); ?></p>
				<table class="form-table">
					<?php
					$pfs_weight_labels = array(
						'title'   => __( 'Заголовок', 'pf-filter' ),
						'sku'     => __( 'Артикул', 'pf-filter' ),
						'terms'   => __( 'Таксономии', 'pf-filter' ),
						'excerpt' => __( 'Отрывок', 'pf-filter' ),
						'acf'     => __( 'ACF-поля', 'pf-filter' ),
						'content' => __( 'Текст записи', 'pf-filter' ),
					);
					$pfs_default_weights = PF_Search_Config::get_default_weights();
					?>
					<?php foreach ( $pfs_weight_labels as $pfs_key => $pfs_label ) : ?>
						<tr>
							<th><?php echo esc_html( $pfs_label ); ?></th>
							<td>
								<input type="number" step="0.1" min="0" max="20" name="pfs[weights][<?php echo esc_attr( $pfs_key ); ?>]" value="<?php echo esc_attr( $profile['weights'][ $pfs_key ] ); ?>" style="width:80px" />
								<span class="pfs-muted"><?php echo esc_html( sprintf( /* translators: %s: число */ __( 'по умолчанию %s', 'pf-filter' ), $pfs_default_weights[ $pfs_key ] ) ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</details>

			<?php submit_button( __( 'Сохранить профиль', 'pf-filter' ) ); ?>
		</form>

		<div class="pfs-card">
			<h2><?php esc_html_e( 'Аналитика запросов', 'pf-filter' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<?php wp_nonce_field( 'pfs_toggle_analytics' ); ?>
				<input type="hidden" name="action" value="pfs_toggle_analytics" />
				<?php if ( $analytics ) : ?>
					<p><strong class="pfs-ok"><?php esc_html_e( 'Включена.', 'pf-filter' ); ?></strong> <?php esc_html_e( 'Учитываются только итоговые запросы: полная выдача (Enter, страница результатов, выдача в блоке фильтра) и живой поиск, на котором посетитель остановился или кликнул по карточке. Хранится только «запрос → сколько раз искали, сколько нашлось».', 'pf-filter' ); ?></p>
					<button type="submit" class="button"><?php esc_html_e( 'Выключить аналитику', 'pf-filter' ); ?></button>
				<?php else : ?>
					<p><?php esc_html_e( 'Выключена. Если включить — будет видно, что ищут на сайте и какие запросы ничего не находят (подсказка, каких товаров, слов в описаниях или ACF-полей не хватает). Небольшая дополнительная запись в базу на каждый итоговый запрос.', 'pf-filter' ); ?></p>
					<input type="hidden" name="enable" value="1" />
					<button type="submit" class="button"><?php esc_html_e( 'Включить аналитику', 'pf-filter' ); ?></button>
				<?php endif; ?>
			</form>
			<?php if ( $analytics ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block" onsubmit="return confirm('<?php echo esc_js( __( 'Очистить всю статистику запросов?', 'pf-filter' ) ); ?>');">
					<?php wp_nonce_field( 'pfs_clear_analytics' ); ?>
					<input type="hidden" name="action" value="pfs_clear_analytics" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Очистить статистику', 'pf-filter' ); ?></button>
				</form>
				<?php
				$pfs_tables = array(
					__( 'Запросы без результатов', 'pf-filter' ) => $zero_queries,
					__( 'Частые запросы', 'pf-filter' )          => $top_queries,
				);
				?>
				<?php foreach ( $pfs_tables as $pfs_title => $pfs_rows ) : ?>
					<h3><?php echo esc_html( $pfs_title ); ?></h3>
					<?php if ( ! $pfs_rows ) : ?>
						<p class="pfs-muted"><?php esc_html_e( 'Пока пусто.', 'pf-filter' ); ?></p>
					<?php else : ?>
						<table class="widefat striped" style="max-width:900px">
							<thead><tr><th><?php esc_html_e( 'Запрос', 'pf-filter' ); ?></th><th><?php esc_html_e( 'Раз искали', 'pf-filter' ); ?></th><th><?php esc_html_e( 'Найдено (последний раз)', 'pf-filter' ); ?></th><th><?php esc_html_e( 'Тип / профиль', 'pf-filter' ); ?></th><th><?php esc_html_e( 'Последний раз', 'pf-filter' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $pfs_rows as $pfs_row ) : ?>
									<tr>
										<td><?php echo esc_html( $pfs_row->query ); ?></td>
										<td><?php echo esc_html( number_format_i18n( (int) $pfs_row->hits ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( (int) $pfs_row->last_total ) ); ?></td>
										<td><?php echo esc_html( ( $post_types[ $pfs_row->post_type ] ?? $pfs_row->post_type ) . ' / ' . $pfs_row->profile ); ?></td>
										<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pfs_row->last_at . ' UTC' ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="pfs-card">
			<h2><?php esc_html_e( 'Проверка поиска', 'pf-filter' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ищет по сохранённым настройкам этого профиля — так же, как будет искать на сайте. Показывает, в каких полях нашлось совпадение, и оценку релевантности.', 'pf-filter' ); ?></p>
			<form class="pfs-test-form" data-pfs-test data-profile="<?php echo esc_attr( $profile_id ); ?>">
				<input type="search" class="regular-text" name="q" placeholder="<?php esc_attr_e( 'Поисковый запрос', 'pf-filter' ); ?>" />
				<?php if ( count( $pfs_types_in_profile ) > 1 ) : ?>
					<select name="type">
						<?php foreach ( $pfs_types_in_profile as $pfs_type ) : ?>
							<option value="<?php echo esc_attr( $pfs_type ); ?>" <?php selected( PF_Search_Config::resolve_type( $profile ), $pfs_type ); ?>><?php echo esc_html( PF_Search_Config::get_type_label( $profile, $pfs_type ) ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Найти', 'pf-filter' ); ?></button>
			</form>
			<div class="pfs-test-results" data-pfs-test-results></div>
		</div>

	<?php endif; ?>
</div>
