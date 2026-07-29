<?php
/**
 * Страница настроек плагина в админке WordPress.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Admin
 */
class PF_Admin {

	/**
	 * Slug страницы настроек.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'pf-filter-settings';

	/**
	 * Сканер таксономий/атрибутов.
	 *
	 * @var PF_Attributes
	 */
	private $attributes;

	/**
	 * Диагностика окружения/разметки/REST API.
	 *
	 * @var PF_Diagnostics
	 */
	private $diagnostics;

	/**
	 * Конструктор.
	 */
	public function __construct() {
		$this->attributes  = new PF_Attributes();
		$this->diagnostics = new PF_Diagnostics();
	}

	/**
	 * Регистрация хуков админки.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_pf_run_diagnostics', array( $this, 'ajax_run_diagnostics' ) );
	}

	/**
	 * Регистрация пункта меню Настройки → PF Filter.
	 */
	public function register_page() {
		add_options_page(
			__( 'PF Filter', 'pf-filter' ),
			__( 'PF Filter', 'pf-filter' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Регистрация настройки через Settings API.
	 */
	public function register_setting() {
		register_setting(
			'pf_filter_settings_group',
			PF_Config::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => PF_Config::get_defaults(),
			)
		);
	}

	/**
	 * Подключить JS/CSS только на странице настроек плагина.
	 *
	 * @param string $hook Текущий admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'pf-admin', PF_FILTER_URL . 'assets/css/pf-admin.css', array(), PF_FILTER_VERSION );
		wp_enqueue_script( 'pf-admin', PF_FILTER_URL . 'assets/js/pf-admin.js', array(), PF_FILTER_VERSION, true );
		wp_enqueue_script( 'pf-diagnostics', PF_FILTER_URL . 'assets/js/pf-diagnostics.js', array(), PF_FILTER_VERSION, true );

		wp_localize_script(
			'pf-admin',
			'pfAdminConfig',
			array(
				'fieldCompatibleTemplates' => $this->get_field_compatible_templates_map(),
				'acfFieldsByField'         => $this->get_acf_fields_map(),
			)
		);

		wp_localize_script(
			'pf-diagnostics',
			'pfDiagnosticsConfig',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'pf_diagnostics' ),
				'defaultUrl' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
			)
		);
	}

	/**
	 * AJAX-обработчик кнопки «Запустить проверку» на вкладке «Диагностика».
	 * Результаты никогда не кешируются — каждый вызов делает свежие проверки.
	 */
	public function ajax_run_diagnostics() {
		check_ajax_referer( 'pf_diagnostics', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'pf-filter' ) ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		wp_send_json_success(
			array(
				'environment' => $this->diagnostics->check_environment(),
				'markup'      => $url ? $this->diagnostics->analyze_markup( $url ) : null,
				'rest_api'    => $this->diagnostics->test_rest_api(),
			)
		);
	}

	/**
	 * Санировать сохраняемые настройки перед записью в wp_options.
	 *
	 * @param array $input Сырые данные из $_POST (уже собранные Settings API).
	 * @return array
	 */
	public function sanitize( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return PF_Config::get_settings();
		}

		$input = is_array( $input ) ? $input : array();
		$clean = PF_Config::get_defaults();

		$clean['post_type']          = array_key_exists( $input['post_type'] ?? '', $this->get_public_post_types() )
			? $input['post_type']
			: $clean['post_type'];
		$clean['logic']              = in_array( $input['logic'] ?? '', array( 'and', 'or' ), true ) ? $input['logic'] : 'and';
		$clean['search_threshold']   = isset( $input['search_threshold'] ) ? absint( $input['search_threshold'] ) : 7;
		$clean['show_counts']        = ! empty( $input['show_counts'] );
		$clean['tree_depth']         = isset( $input['tree_depth'] ) ? max( 1, absint( $input['tree_depth'] ) ) : 4;
		$clean['sync_url']           = ! empty( $input['sync_url'] );
		$clean['pagination_strategy'] = in_array( $input['pagination_strategy'] ?? '', PF_Config::PAGINATION_STRATEGIES, true )
			? $input['pagination_strategy']
			: 'pages';
		$clean['posts_per_page']    = isset( $input['posts_per_page'] ) ? max( 1, absint( $input['posts_per_page'] ) ) : 12;

		$clean['groups']       = $this->sanitize_groups( $input['groups'] ?? array() );
		$clean['sort_options'] = $this->sanitize_sort_options( $input['sort_options'] ?? array() );

		return $clean;
	}

	/**
	 * Санировать список групп фильтров.
	 *
	 * @param array $groups Сырые данные групп.
	 * @return array
	 */
	private function sanitize_groups( $groups ) {
		if ( ! is_array( $groups ) ) {
			return array();
		}

		$clean = array();

		foreach ( $groups as $group ) {
			if ( empty( $group['field'] ) ) {
				continue;
			}

			$row = array(
				'field'      => sanitize_text_field( $group['field'] ),
				'label'      => sanitize_text_field( $group['label'] ?? '' ),
				'template'   => sanitize_key( $group['template'] ?? 'checkbox' ),
				'logic'      => in_array( $group['logic'] ?? 'or', array( 'and', 'or' ), true ) ? $group['logic'] : 'or',
				'enabled'    => ! empty( $group['enabled'] ),
				'search'     => ! empty( $group['search'] ),
				'value_sort' => in_array( $group['value_sort'] ?? '', PF_Attributes::VALUE_SORT_OPTIONS, true ) ? $group['value_sort'] : 'name_asc',
			);

			if ( isset( $group['step'] ) && '' !== $group['step'] ) {
				$row['step'] = floatval( $group['step'] );
			}
			if ( isset( $group['unit'] ) ) {
				$row['unit'] = sanitize_text_field( $group['unit'] );
			}
			if ( isset( $group['tree_depth'] ) && '' !== $group['tree_depth'] ) {
				$row['tree_depth'] = absint( $group['tree_depth'] );
			}

			if ( isset( $group['color_meta_key'] ) && '' !== trim( (string) $group['color_meta_key'] ) ) {
				$row['color_meta_key'] = sanitize_key( $group['color_meta_key'] );
			}

			$clean[] = $row;
		}

		return $clean;
	}

	/**
	 * Санировать список опций сортировки.
	 *
	 * @param array $options Сырые данные опций.
	 * @return array
	 */
	private function sanitize_sort_options( $options ) {
		if ( ! is_array( $options ) ) {
			return array();
		}

		$whitelist = PF_Query::ORDERBY_WHITELIST;
		$clean     = array();

		foreach ( $options as $option ) {
			if ( empty( $option['value'] ) || ! in_array( $option['value'], $whitelist, true ) ) {
				continue;
			}
			$clean[] = array(
				'value' => $option['value'],
				'label' => sanitize_text_field( $option['label'] ?? $option['value'] ),
			);
		}

		return $clean;
	}

	/**
	 * Список шаблонов групп по умолчанию (используется если сканирование разметки
	 * не задано или не нашло ни одного pf-template в разметке).
	 *
	 * @var array
	 */
	const DEFAULT_TEMPLATES = array( 'checkbox', 'radio', 'tags', 'dropdown-checkbox', 'dropdown-radio', 'range', 'category-tree' );

	/**
	 * Русская подпись шаблона для выпадашки "Шаблон" в таблице групп — реальное
	 * значение (используется как pf-template в разметке темы и хранится в
	 * настройках) не меняется, подписывается только текст option.
	 *
	 * @param string $slug Значение pf-template (checkbox/radio/tags/...).
	 * @return string
	 */
	private function template_label( $slug ) {
		$labels = array(
			'checkbox'          => __( 'Чекбоксы', 'pf-filter' ),
			'radio'             => __( 'Радиокнопки', 'pf-filter' ),
			'tags'              => __( 'Теги/чипы', 'pf-filter' ),
			'dropdown-checkbox' => __( 'Дропдаун (чекбоксы)', 'pf-filter' ),
			'dropdown-radio'    => __( 'Дропдаун (радиокнопки)', 'pf-filter' ),
			'range'             => __( 'Диапазон (слайдер)', 'pf-filter' ),
			'category-tree'     => __( 'Дерево категорий', 'pf-filter' ),
		);

		return $labels[ $slug ] ?? $slug;
	}

	/**
	 * Публичные типы записи сайта (в т.ч. кастомные) — источник для выпадашки
	 * «Тип записи» в общих настройках. Ключ — слаг типа записи, значение —
	 * его подпись (labels->name).
	 *
	 * @return array slug => label
	 */
	private function get_public_post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$out[ $post_type->name ] = $post_type->labels->name ?? $post_type->name;
		}
		return $out;
	}

	/**
	 * Отрендерить страницу настроек.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings         = PF_Config::get_settings();
		$post_types       = $this->get_public_post_types();
		$available_fields = array_merge(
			$this->attributes->get_available_taxonomy_fields(),
			array(
				array(
					'field' => 'price',
					'label' => __( 'Цена', 'pf-filter' ),
				),
			)
		);
		$configured_depth = (int) $settings['tree_depth'];
		// Страница магазина используется как образец разметки для двух вспомогательных
		// проверок ниже — списка доступных pf-template и доступности стратегий
		// пагинации. Раньше для этого нужно было вручную вписывать URL в отдельное
		// поле настроек; теперь берём тот же URL, что уже используется как дефолт
		// для ручной диагностики разметки.
		$diagnostics_default_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$available_templates     = $this->get_available_templates( $diagnostics_default_url );
		// Один скан образца разметки на всю страницу настроек — переиспользуется
		// и для доступности стратегий пагинации, и для точечных предупреждений
		// "для этой настройки в вёрстке нет нужного элемента" (см. ниже
		// PF_Diagnostics::has_attribute()/has_text_input() в самом виде).
		$scan_dom              = $this->diagnostics->fetch_dom( $diagnostics_default_url );
		$scan_xpath            = $scan_dom ? new DOMXPath( $scan_dom ) : null;
		$available_pagination  = $scan_xpath ? PF_Diagnostics::detect_pagination_availability( $scan_xpath ) : null;

		require PF_FILTER_PATH . 'includes/views/admin-page.php';
	}

	/**
	 * Отрендерить одну строку группы фильтра (используется и для существующих
	 * групп, и как HTML-шаблон для JS при добавлении новой строки).
	 *
	 * @param string $name_prefix         Префикс имени поля, напр. "pf_filter_settings[groups]".
	 * @param string $index               Индекс строки (число либо "__INDEX__" для JS-шаблона).
	 * @param array  $group               Данные группы (field/label/template/logic/enabled/...).
	 * @param array         $available_fields    Список доступных полей [ [field,label], ... ].
	 * @param array         $available_templates Список доступных значений pf-template.
	 * @param DOMXPath|null $scan_xpath          XPath образца разметки (см. PF_Admin::render_page()) для точечных
	 *                                           предупреждений "нет нужного элемента"; null — сканирование недоступно.
	 */
	public function render_group_row( $name_prefix, $index, array $group, array $available_fields, array $available_templates, $scan_xpath = null ) {
		$n = $name_prefix . '[' . $index . ']';
		$field    = $group['field'] ?? '';
		$label    = $group['label'] ?? '';
		$template = $group['template'] ?? 'checkbox';
		$logic    = $group['logic'] ?? 'or';
		$enabled  = ! empty( $group['enabled'] );
		// По умолчанию (для новых групп и уже существующих без явной настройки)
		// поиск включён — как и было раньше, до появления этого переключателя.
		$search   = ! isset( $group['search'] ) || ! empty( $group['search'] );
		$step     = $group['step'] ?? '';
		$unit     = $group['unit'] ?? '';
		$tree_d   = $group['tree_depth'] ?? '';
		$value_sort = $group['value_sort'] ?? 'name_asc';
		$color_meta_key = $group['color_meta_key'] ?? '';
		// Поля ACF термина ЭТОГО поля группы (если оно вообще таксономия) —
		// список для выпадашки «поле с цветом». У кастомных атрибутов и цены
		// термов не существует, для них всегда пусто.
		$acf_fields = taxonomy_exists( $field ) ? $this->attributes->get_acf_term_fields( $field ) : array();
		// null — сканирование недоступно (тогда ничего не предупреждаем, как и
		// в остальных местах, завязанных на скан образца разметки).
		$search_available = $scan_xpath ? PF_Diagnostics::has_text_input( $scan_xpath, $template ) : null;
		$colors_available = $scan_xpath ? PF_Diagnostics::has_attribute( $scan_xpath, 'pf-filter-swatch', $template ) : null;
		// Реальная глубина дерева этой таксономии vs эффективно настроенная
		// (своя tree_depth группы, иначе глобальная настройка) — только для
		// иерархических таксономий с шаблоном category-tree.
		$tree_depth_warning = null;
		if ( 'category-tree' === $template && taxonomy_exists( $field ) && is_taxonomy_hierarchical( $field ) ) {
			$effective_depth = '' !== $tree_d ? (int) $tree_d : (int) PF_Config::get( 'tree_depth', 4 );
			$real_depth      = $this->attributes->get_real_category_depth( $field );
			if ( $real_depth > $effective_depth ) {
				$tree_depth_warning = sprintf(
					/* translators: 1: реальная глубина дерева, 2: настроенная глубина */
					__( 'реальная глубина — %1$d, показывается %2$d', 'pf-filter' ),
					$real_depth,
					$effective_depth
				);
			}
		}
		?>
		<tr class="pf-group-row" draggable="true" data-index="<?php echo esc_attr( $index ); ?>">
			<td class="pf-drag-handle" title="<?php esc_attr_e( 'Перетащить для изменения порядка', 'pf-filter' ); ?>">☰</td>
			<td>
				<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $enabled ); ?> />
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[field]" class="pf-field-select">
					<?php foreach ( $available_fields as $f ) : ?>
						<option value="<?php echo esc_attr( $f['field'] ); ?>" <?php selected( $field, $f['field'] ); ?>><?php echo esc_html( $f['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Название', 'pf-filter' ); ?>" />
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[template]" class="pf-template-select">
					<?php foreach ( $available_templates as $tpl ) : ?>
						<option value="<?php echo esc_attr( $tpl ); ?>" <?php selected( $template, $tpl ); ?>><?php echo esc_html( $this->template_label( $tpl ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[logic]">
					<option value="or" <?php selected( $logic, 'or' ); ?>><?php esc_html_e( 'OR', 'pf-filter' ); ?></option>
					<option value="and" <?php selected( $logic, 'and' ); ?>><?php esc_html_e( 'AND', 'pf-filter' ); ?></option>
				</select>
			</td>
			<td>
				<label title="<?php esc_attr_e( 'Если выключено — поле поиска в этой группе не показывается никогда, независимо от порога', 'pf-filter' ); ?>">
					<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[search]" value="1" <?php checked( $search ); ?> />
					<?php esc_html_e( 'Поиск', 'pf-filter' ); ?>
				</label>
				<?php if ( $search && false === $search_available ) : ?>
					<br /><span style="color:#b32d2e;font-size:11px;" title="<?php esc_attr_e( 'В шаблоне pf-template этой группы не найден input[type=text] — поле поиска показывать будет негде.', 'pf-filter' ); ?>">⚠ <?php esc_html_e( 'нет поля в шаблоне', 'pf-filter' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="pf-extra-range">
				<input type="number" step="any" name="<?php echo esc_attr( $n ); ?>[step]" value="<?php echo esc_attr( $step ); ?>" placeholder="<?php esc_attr_e( 'Шаг', 'pf-filter' ); ?>" style="width:70px" />
				<input type="text" name="<?php echo esc_attr( $n ); ?>[unit]" value="<?php echo esc_attr( $unit ); ?>" placeholder="<?php esc_attr_e( 'Ед.', 'pf-filter' ); ?>" style="width:50px" />
			</td>
			<td class="pf-extra-tree">
				<input type="number" min="1" name="<?php echo esc_attr( $n ); ?>[tree_depth]" value="<?php echo esc_attr( $tree_d ); ?>" placeholder="<?php esc_attr_e( 'Глубина', 'pf-filter' ); ?>" style="width:70px" />
				<?php if ( $tree_depth_warning ) : ?>
					<br /><span style="color:#b32d2e;font-size:11px;" title="<?php esc_attr_e( 'Значения глубже настроенного уровня не будут показаны в этой группе.', 'pf-filter' ); ?>">⚠ <?php echo esc_html( $tree_depth_warning ); ?></span>
				<?php endif; ?>
			</td>
			<td class="pf-extra-colors">
				<select name="<?php echo esc_attr( $n ); ?>[color_meta_key]" class="pf-color-meta-select" title="<?php esc_attr_e( 'Поле ACF/term meta термина, где хранится его цвет.', 'pf-filter' ); ?>">
					<option value=""><?php esc_html_e( '— нет —', 'pf-filter' ); ?></option>
					<?php foreach ( $acf_fields as $af ) : ?>
						<option value="<?php echo esc_attr( $af['name'] ); ?>" <?php selected( $color_meta_key, $af['name'] ); ?>><?php echo esc_html( $af['label'] . ' (' . $af['name'] . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $color_meta_key && false === $colors_available ) : ?>
					<p style="color:#b32d2e;font-size:11px;margin:4px 0 0;" title="<?php esc_attr_e( 'В шаблоне pf-template этой группы не найден [pf-filter-swatch] — цвета показывать будет негде.', 'pf-filter' ); ?>">⚠ <?php esc_html_e( 'нет pf-filter-swatch в шаблоне', 'pf-filter' ); ?></p>
				<?php endif; ?>
			</td>
			<td class="pf-extra-value-sort">
				<select name="<?php echo esc_attr( $n ); ?>[value_sort]">
					<option value="name_asc" <?php selected( $value_sort, 'name_asc' ); ?>><?php esc_html_e( 'По алфавиту (А→Я)', 'pf-filter' ); ?></option>
					<option value="name_desc" <?php selected( $value_sort, 'name_desc' ); ?>><?php esc_html_e( 'По алфавиту (Я→А)', 'pf-filter' ); ?></option>
					<option value="count_desc" <?php selected( $value_sort, 'count_desc' ); ?>><?php esc_html_e( 'По кол-ву (сначала больше)', 'pf-filter' ); ?></option>
					<option value="count_asc" <?php selected( $value_sort, 'count_asc' ); ?>><?php esc_html_e( 'По кол-ву (сначала меньше)', 'pf-filter' ); ?></option>
				</select>
			</td>
			<td>
				<button type="button" class="button-link-delete pf-remove-row"><?php esc_html_e( 'Удалить', 'pf-filter' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Получить список доступных значений pf-template — либо просканировав разметку
	 * по указанному URL, либо (по умолчанию) статичный список всех типов шаблонов.
	 *
	 * @param string $scan_url URL страницы для сканирования (может быть пустым).
	 * @return array
	 */
	private function get_available_templates( $scan_url ) {
		if ( empty( $scan_url ) ) {
			return self::DEFAULT_TEMPLATES;
		}

		$response = wp_remote_get( $scan_url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			return self::DEFAULT_TEMPLATES;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return self::DEFAULT_TEMPLATES;
		}

		preg_match_all( '/pf-template=["\']([a-z-]+)["\']/i', $body, $matches );
		$found = array_unique( array_map( 'strtolower', $matches[1] ?? array() ) );

		return empty( $found ) ? self::DEFAULT_TEMPLATES : array_values( $found );
	}

	/**
	 * Карта field => совместимые шаблоны, для JS страницы настроек — чтобы
	 * при выборе поля список шаблонов сужался до применимых (например, range
	 * только для полей с исключительно числовыми значениями).
	 *
	 * @return array
	 */
	private function get_field_compatible_templates_map() {
		$fields = array_merge(
			$this->attributes->get_available_taxonomy_fields(),
			array(
				array(
					'field' => 'price',
					'label' => __( 'Цена', 'pf-filter' ),
				),
			)
		);

		$map = array();
		foreach ( $fields as $f ) {
			$map[ $f['field'] ] = $this->attributes->get_compatible_templates( $f['field'] );
		}

		return $map;
	}

	/**
	 * Карта field => поля ACF термина этой таксономии, для JS страницы
	 * настроек — выпадашка «поле с цветом» у группы сужается под выбранное
	 * поле. Только для реальных таксономий — у кастомных/локальных атрибутов
	 * термов не существует.
	 *
	 * @return array
	 */
	private function get_acf_fields_map() {
		$map = array();
		foreach ( $this->attributes->get_available_taxonomy_fields() as $f ) {
			if ( taxonomy_exists( $f['field'] ) ) {
				$map[ $f['field'] ] = $this->attributes->get_acf_term_fields( $f['field'] );
			}
		}

		return $map;
	}
}
