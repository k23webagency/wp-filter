<?php
/**
 * Диагностика окружения, разметки страницы и REST API плагина.
 * Используется только страницей настроек (вкладка «Диагностика»).
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Diagnostics
 */
class PF_Diagnostics {

	/**
	 * Сканер таксономий (для реальной глубины дерева категорий).
	 *
	 * @var PF_Attributes
	 */
	private $attributes;

	/**
	 * Конструктор.
	 */
	public function __construct() {
		$this->attributes = new PF_Attributes();
	}

	/**
	 * Секция 1 — серверные проверки окружения, URL не требуется.
	 *
	 * @return array Список проверок [ { key, label, status, message }, ... ].
	 */
	public function check_environment() {
		global $wp_version;

		$checks = array();

		$checks[] = $this->make_check(
			'php_version',
			__( 'Версия PHP', 'pf-filter' ),
			version_compare( PHP_VERSION, '8.0', '>=' ),
			'error',
			sprintf( '%s (требуется 8.0+)', PHP_VERSION )
		);

		$checks[] = $this->make_check(
			'wp_version',
			__( 'Версия WordPress', 'pf-filter' ),
			version_compare( $wp_version, '6.0', '>=' ),
			'error',
			sprintf( '%s (требуется 6.0+)', $wp_version )
		);

		$checks[] = $this->make_check(
			'woocommerce_active',
			__( 'WooCommerce активен', 'pf-filter' ),
			class_exists( 'WooCommerce' ),
			'error',
			class_exists( 'WooCommerce' ) ? ( defined( 'WC_VERSION' ) ? 'v' . WC_VERSION : '' ) : __( 'WooCommerce не найден или не активирован.', 'pf-filter' )
		);

		$rest_ok      = false;
		$rest_message = '';
		$rest_response = wp_remote_get(
			rest_url( 'pf/v1/config' ),
			array(
				'headers' => array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ),
				'timeout' => 8,
				// Без cookie's текущего пользователя WordPress видит этот запрос как
				// анонимный, и nonce (привязанный к сессии) не проходит проверку
				// (rest_cookie_invalid_nonce), хотя сам REST API работает исправно.
				'cookies' => $_COOKIE, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- пробрасываются как есть, аналогично wp_remote_get() в core (класс WP_Site_Health).
			)
		);
		if ( is_wp_error( $rest_response ) ) {
			$rest_message = $rest_response->get_error_message();
		} else {
			$code    = wp_remote_retrieve_response_code( $rest_response );
			$rest_ok = 200 === $code;
			$rest_message = $rest_ok ? '200 OK' : ( 'HTTP ' . $code );
		}
		$checks[] = $this->make_check(
			'rest_api',
			__( 'REST API отвечает', 'pf-filter' ),
			$rest_ok,
			'error',
			$rest_message
		);

		$checks[] = $this->make_check(
			'writable',
			__( 'Права на запись в wp-content', 'pf-filter' ),
			is_writable( WP_CONTENT_DIR ),
			'warning',
			WP_CONTENT_DIR
		);

		$debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		$checks[] = $this->make_check(
			'debug_log',
			__( 'WP_DEBUG_LOG включён', 'pf-filter' ),
			$debug_log_enabled,
			'info',
			$debug_log_enabled ? '' : __( 'Логирование ошибок отключено — debug.log вести не будет.', 'pf-filter' )
		);

		$checks[] = $this->check_card_template_override();

		return $checks;
	}

	/**
	 * Плагин рендерит карточки товара через wc_get_template_part('content','product') —
	 * ту же функцию, что вызывает тема при обычной загрузке страницы. Но если
	 * тема сама эту функцию не использует (например, рисует карточку вручную
	 * прямо в своём шаблоне архива, как в Webflow-сборках), а woocommerce/content-product.php
	 * в теме не переопределён — при AJAX-обновлении подставится карточка ПО
	 * УМОЛЧАНИЮ из WooCommerce (с кнопкой «В корзину» и бейджем «Распродажа»),
	 * которая не будет совпадать с реальным оформлением темы.
	 *
	 * @return array
	 */
	private function check_card_template_override() {
		if ( ! function_exists( 'wc_locate_template' ) ) {
			return $this->make_check(
				'content_product_override',
				__( 'Переопределение шаблона карточки (content-product.php)', 'pf-filter' ),
				false,
				'warning',
				__( 'WooCommerce не активен — проверить нельзя.', 'pf-filter' )
			);
		}

		$template_path   = wc_locate_template( 'content-product.php' );
		$is_theme_override = $template_path && false !== strpos( wp_normalize_path( $template_path ), wp_normalize_path( get_stylesheet_directory() ) );

		return $this->make_check(
			'content_product_override',
			__( 'Переопределение шаблона карточки (content-product.php)', 'pf-filter' ),
			$is_theme_override,
			'warning',
			$is_theme_override
				? $template_path
				: __( 'Тема не переопределяет woocommerce/content-product.php. Если тема рендерит карточку на странице каталога вручную (не через wc_get_template_part), AJAX-обновления от плагина будут показывать карточку по умолчанию из WooCommerce — с кнопкой «В корзину» и бейджем «Распродажа», без стилей темы. Решение: добавить в тему файл woocommerce/content-product.php с той же разметкой, что и в её собственном цикле товаров.', 'pf-filter' )
		);
	}

	/**
	 * Собрать одну строку проверки.
	 *
	 * @param string $key            Ключ проверки.
	 * @param string $label          Заголовок.
	 * @param bool   $passed         Прошла ли проверка.
	 * @param string $fail_status    Статус при провале: error|warning|info.
	 * @param string $message        Пояснение (для успеха и провала).
	 * @return array
	 */
	private function make_check( $key, $label, $passed, $fail_status, $message = '' ) {
		return array(
			'key'     => $key,
			'label'   => $label,
			'status'  => $passed ? 'ok' : $fail_status,
			'message' => $message,
		);
	}

	/**
	 * Секция 2 — загрузить и разобрать HTML указанной страницы.
	 *
	 * @param string $url URL страницы для анализа.
	 * @return array {checks, found_templates, auto_hooks}
	 */
	public function analyze_markup( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'checks'          => array(
					$this->result( 'error', __( 'Загрузка страницы', 'pf-filter' ), $response->get_error_message() ),
				),
				'found_templates' => array(),
				'auto_hooks'      => array(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return array(
				'checks'          => array(
					$this->result( 'error', __( 'Загрузка страницы', 'pf-filter' ), 'HTTP ' . $code ),
				),
				'found_templates' => array(),
				'auto_hooks'      => array(),
			);
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return array(
				'checks'          => array(
					$this->result( 'error', __( 'Загрузка страницы', 'pf-filter' ), __( 'Пустой ответ.', 'pf-filter' ) ),
				),
				'found_templates' => array(),
				'auto_hooks'      => array(),
			);
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		$checks = array();

		$checks = array_merge( $checks, $this->check_required_attributes( $xpath ) );

		$found_templates = $this->find_template_values( $xpath );
		$checks          = array_merge( $checks, $this->check_group_templates( $xpath, $found_templates ) );
		$checks          = array_merge( $checks, $this->check_smart_hints( $xpath ) );
		$checks          = array_merge( $checks, $this->check_tree_depth( $xpath, $found_templates ) );

		return array(
			'checks'          => $checks,
			'found_templates' => $found_templates,
			'auto_hooks'      => $this->auto_hooks_info( $xpath ),
		);
	}

	/**
	 * Короткий помощник для формирования одного результата проверки без ключа.
	 *
	 * @param string $status  ok|error|warning|info.
	 * @param string $label   Заголовок.
	 * @param string $message Сообщение.
	 * @return array
	 */
	private function result( $status, $label, $message = '' ) {
		return array(
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
		);
	}

	/**
	 * 2.1 — обязательные атрибуты формы.
	 *
	 * @param DOMXPath $xpath XPath разобранного документа.
	 * @return array
	 */
	private function check_required_attributes( DOMXPath $xpath ) {
		$required = array(
			'pf-form'      => __( 'Форма фильтра не инициализируется. Плагин не работает.', 'pf-filter' ),
			'pf-output'    => __( 'Группы фильтров не будут вставлены в форму.', 'pf-filter' ),
			'pf-templates' => __( 'Шаблоны групп не найдены. Фильтр не строится.', 'pf-filter' ),
			'pf-list'      => __( 'Контейнер карточек не найден. Результаты не обновляются.', 'pf-filter' ),
		);

		$checks     = array();
		$node_count = array();

		foreach ( $required as $attribute => $fail_message ) {
			$nodes                    = $xpath->query( '//*[@' . $attribute . ']' );
			$found                    = $nodes && $nodes->length > 0;
			$node_count[ $attribute ] = $nodes ? $nodes->length : 0;
			$checks[]                 = $this->result(
				$found ? 'ok' : 'error',
				'[' . $attribute . ']',
				$found ? sprintf( 'найдено: %d', $nodes->length ) : $fail_message
			);
		}

		$checks[] = $this->check_target_attribute( $xpath, $node_count['pf-form'], $node_count['pf-list'] );

		return $checks;
	}

	/**
	 * pf-target нигде не обязателен (см. resolveListElement() в pf-filter.js):
	 * - при ровно одной [pf-form] и одном [pf-list] на странице они связываются
	 *   автоматически, атрибут не нужен;
	 * - при нескольких формах и/или списках без pf-target связь неоднозначна —
	 *   предупреждение, а не ошибка (JS всё равно попробует связать первую форму
	 *   с первым списком);
	 * - если pf-target указан явно, проверяем что селектор что-то находит.
	 *
	 * @param DOMXPath $xpath       XPath документа.
	 * @param int      $form_count  Количество найденных [pf-form].
	 * @param int      $list_count  Количество найденных [pf-list].
	 * @return array
	 */
	private function check_target_attribute( DOMXPath $xpath, $form_count, $list_count ) {
		$target_nodes = $xpath->query( '//*[@pf-target]' );

		if ( $target_nodes && $target_nodes->length > 0 ) {
			$selector = $target_nodes->item( 0 )->getAttribute( 'pf-target' );
			if ( $selector ) {
				$exists = $this->selector_exists( $xpath, $selector );
				return $this->result(
					$exists ? 'ok' : 'warning',
					'pf-target → ' . $selector,
					$exists
						? __( 'элемент найден на странице', 'pf-filter' )
						/* translators: %s: CSS-селектор */
						: sprintf( __( 'Элемент `%s` не найден на странице', 'pf-filter' ), $selector )
				);
			}
		}

		if ( 1 === $form_count && 1 === $list_count ) {
			return $this->result(
				'ok',
				'[pf-target]',
				__( 'не указан, но на странице ровно одна форма и один список — связаны автоматически', 'pf-filter' )
			);
		}

		return $this->result(
			'warning',
			'[pf-target]',
			sprintf(
				/* translators: 1: количество форм, 2: количество списков */
				__( 'не указан, а на странице %1$d форм(ы) и %2$d списк(ов) — связь неоднозначна. Добавьте pf-target="#selector". JS попробует связать первую форму с первым списком.', 'pf-filter' ),
				$form_count,
				$list_count
			)
		);
	}

	/**
	 * Минимальный резолвер CSS-селектора (#id, .class, [attr], [attr="value"], тег) в XPath.
	 * Составные/комбинированные селекторы не поддерживаются — вернёт false (считается не найденным).
	 *
	 * @param DOMXPath $xpath    XPath документа.
	 * @param string   $selector CSS-селектор из pf-target.
	 * @return bool
	 */
	private function selector_exists( DOMXPath $xpath, $selector ) {
		$selector = trim( $selector );

		if ( '' === $selector ) {
			return false;
		}

		if ( '#' === $selector[0] ) {
			$id    = substr( $selector, 1 );
			$nodes = $xpath->query( '//*[@id="' . $id . '"]' );
			return $nodes && $nodes->length > 0;
		}

		if ( '.' === $selector[0] ) {
			$class = substr( $selector, 1 );
			$nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]' );
			return $nodes && $nodes->length > 0;
		}

		if ( '[' === $selector[0] && ']' === substr( $selector, -1 ) ) {
			$inner = substr( $selector, 1, -1 );
			if ( false !== strpos( $inner, '=' ) ) {
				list( $attr, $value ) = explode( '=', $inner, 2 );
				$value = trim( $value, '"\'' );
				$nodes = $xpath->query( '//*[@' . $attr . '="' . $value . '"]' );
			} else {
				$nodes = $xpath->query( '//*[@' . $inner . ']' );
			}
			return $nodes && $nodes->length > 0;
		}

		if ( (bool) preg_match( '/^[a-zA-Z][a-zA-Z0-9]*$/', $selector ) ) {
			$nodes = $xpath->query( '//' . $selector );
			return $nodes && $nodes->length > 0;
		}

		// Составной селектор — не умеем разобрать, не можем подтвердить наличие.
		return false;
	}

	/**
	 * Найти все уникальные значения pf-template в разметке.
	 *
	 * @param DOMXPath $xpath XPath документа.
	 * @return array
	 */
	private function find_template_values( DOMXPath $xpath ) {
		$nodes  = $xpath->query( '//*[@pf-template]' );
		$values = array();
		foreach ( $nodes as $node ) {
			$values[] = $node->getAttribute( 'pf-template' );
		}
		return array_values( array_unique( $values ) );
	}

	/**
	 * 2.2 — для каждого найденного шаблона проверить обязательные зацепки,
	 * и предупредить если группа из настроек ссылается на отсутствующий шаблон.
	 *
	 * @param DOMXPath $xpath           XPath документа.
	 * @param array    $found_templates Значения pf-template найденные в разметке.
	 * @return array
	 */
	private function check_group_templates( DOMXPath $xpath, array $found_templates ) {
		$checks = array();

		// [pf-filter-row] / [pf-filter-value] — общая зацепка только для этих
		// шаблонов. У range и category-tree — свои обязательные зацепки
		// (pf-filter-range-slider и pf-filter-list-1 соответственно), проверять
		// их по общему правилу неверно — это всегда давало бы ложную ошибку.
		$row_value_templates = array( 'checkbox', 'radio', 'tags', 'dropdown-checkbox', 'dropdown-radio' );

		foreach ( $found_templates as $template ) {
			$template_nodes = $xpath->query( '//*[@pf-template="' . $template . '"]' );
			if ( ! $template_nodes || 0 === $template_nodes->length ) {
				continue;
			}
			$node = $template_nodes->item( 0 );

			if ( 'range' === $template ) {
				$has_slider = $xpath->query( './/*[@pf-filter-range-slider]', $node )->length > 0;
				$checks[]   = $this->result(
					$has_slider ? 'ok' : 'error',
					'[pf-template="range"] → [pf-filter-range-slider]',
					$has_slider ? __( 'найден', 'pf-filter' ) : __( 'Шаблон `range` не будет работать', 'pf-filter' )
				);
				continue;
			}

			if ( 'category-tree' === $template ) {
				$has_list = $xpath->query( './/*[@pf-filter-list-1]', $node )->length > 0;
				$checks[] = $this->result(
					$has_list ? 'ok' : 'error',
					'[pf-template="category-tree"] → [pf-filter-list-1]',
					$has_list ? __( 'найден', 'pf-filter' ) : __( 'Дерево категорий не будет построено', 'pf-filter' )
				);
				continue;
			}

			if ( ! in_array( $template, $row_value_templates, true ) ) {
				continue; // Незнакомый/кастомный тип шаблона — общую зацепку не проверяем.
			}

			$has_row = $xpath->query( './/*[@pf-filter-row]', $node )->length > 0;
			$checks[] = $this->result(
				$has_row ? 'ok' : 'error',
				sprintf( '[pf-template="%s"] → [pf-filter-row]', $template ),
				$has_row
					/* translators: %s: значение pf-template */
					? __( 'найден', 'pf-filter' )
					: sprintf( __( 'Шаблон `%s` не будет наполнен данными', 'pf-filter' ), $template )
			);

			$has_value = $xpath->query( './/*[@pf-filter-value]', $node )->length > 0;
			$checks[] = $this->result(
				$has_value ? 'ok' : 'warning',
				sprintf( '[pf-template="%s"] → [pf-filter-value]', $template ),
				$has_value ? __( 'найден', 'pf-filter' ) : __( 'Значения будут без текста', 'pf-filter' )
			);
		}

		$configured_groups = PF_Config::get( 'groups', array() );
		foreach ( $configured_groups as $group ) {
			if ( empty( $group['enabled'] ) || empty( $group['template'] ) ) {
				continue;
			}
			if ( ! in_array( $group['template'], $found_templates, true ) ) {
				$checks[] = $this->result(
					'warning',
					sprintf( '%s → [pf-template="%s"]', $group['label'] ?: $group['field'], $group['template'] ),
					sprintf(
						/* translators: 1: название группы, 2: значение pf-template */
						__( 'Для группы «%1$s» назначен шаблон `%2$s`, но он не найден на странице', 'pf-filter' ),
						$group['label'] ?: $group['field'],
						$group['template']
					)
				);
			}
		}

		return $checks;
	}

	/**
	 * 2.3 — типичные ошибки разметки.
	 *
	 * @param DOMXPath $xpath XPath документа.
	 * @return array
	 */
	private function check_smart_hints( DOMXPath $xpath ) {
		$checks = array();

		$chip_remove_nodes = $xpath->query( '//*[@pf-chip-remove]' );
		foreach ( $chip_remove_nodes as $node ) {
			$has_ancestor = $xpath->query( 'ancestor::*[@pf-active-chip]', $node )->length > 0;
			if ( ! $has_ancestor ) {
				$checks[] = $this->result(
					'warning',
					'[pf-chip-remove]',
					__( 'Найден pf-chip-remove без родителя с pf-active-chip — возможно неверная структура чипа', 'pf-filter' )
				);
				break;
			}
		}

		$sort_option_found = $xpath->query( '//*[@pf-sort-option]' )->length > 0;
		$sort_found        = $xpath->query( '//*[@pf-sort]' )->length > 0;
		if ( $sort_option_found && ! $sort_found ) {
			$checks[] = $this->result( 'warning', '[pf-sort-option]', __( 'Найден pf-sort-option, но нет pf-sort на странице', 'pf-filter' ) );
		}

		$page_item_found  = $xpath->query( '//*[@pf-page-item]' )->length > 0;
		$pagination_found = $xpath->query( '//*[@pf-pagination]' )->length > 0;
		if ( $page_item_found && ! $pagination_found ) {
			$checks[] = $this->result( 'warning', '[pf-page-item]', __( 'Найден pf-page-item, но нет pf-pagination', 'pf-filter' ) );
		}

		$form_count = $xpath->query( '//*[@pf-form]' )->length;
		if ( $form_count > 1 ) {
			$checks[] = $this->result(
				'info',
				'[pf-form]',
				sprintf(
					/* translators: %d: количество найденных форм */
					__( 'Найдено несколько форм фильтра на странице: %d', 'pf-filter' ),
					$form_count
				)
			);
		}

		return $checks;
	}

	/**
	 * 2.4 — сравнить реальную глубину дерева категорий с глубиной поддерживаемой шаблоном.
	 *
	 * @param DOMXPath $xpath           XPath документа.
	 * @param array    $found_templates Значения pf-template найденные в разметке.
	 * @return array
	 */
	private function check_tree_depth( DOMXPath $xpath, array $found_templates ) {
		if ( ! in_array( 'category-tree', $found_templates, true ) ) {
			return array();
		}

		$real_depth = $this->attributes->get_real_category_depth();

		$max_level = 0;
		$found_levels = array();
		foreach ( $xpath->query( '//*' ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			foreach ( $node->attributes as $attribute ) {
				if ( preg_match( '/^pf-filter-list-(\d+)$/', $attribute->nodeName, $matches ) ) {
					$level                 = (int) $matches[1];
					$found_levels[ $level ] = true;
					$max_level              = max( $max_level, $level );
				}
			}
		}

		if ( 0 === $max_level ) {
			return array(
				$this->result( 'warning', __( 'Глубина дерева категорий', 'pf-filter' ), __( 'Шаблон category-tree найден, но pf-filter-list-N не обнаружен.', 'pf-filter' ) ),
			);
		}

		if ( $real_depth <= $max_level ) {
			return array(
				$this->result(
					'ok',
					__( 'Глубина дерева категорий', 'pf-filter' ),
					sprintf( 'данные: %d, шаблон поддерживает: %d', $real_depth, $max_level )
				),
			);
		}

		ksort( $found_levels );
		$found_attrs = array();
		foreach ( array_keys( $found_levels ) as $level ) {
			$found_attrs[] = 'pf-filter-list-' . $level;
		}

		return array(
			$this->result(
				'warning',
				__( 'Глубина дерева категорий', 'pf-filter' ),
				sprintf(
					/* translators: 1: реальная глубина, 2: глубина шаблона, 3: список найденных атрибутов, 4: глубина шаблона (повтор) */
					__( 'Дерево категорий имеет %1$d уровней вложенности. Шаблон поддерживает %2$d уровня (найдены %3$s). Категории глубже %4$d-го уровня не будут показаны. Расширьте шаблон category-tree или оставьте как есть.', 'pf-filter' ),
					$real_depth,
					$max_level,
					implode( ', ', $found_attrs ),
					$max_level
				)
			),
		);
	}

	/**
	 * 2.5 — информационный блок про автоматические зацепки без атрибутов.
	 *
	 * @param DOMXPath $xpath XPath документа.
	 * @return array
	 */
	private function auto_hooks_info( DOMXPath $xpath ) {
		$text_inputs   = $xpath->query( '//*[@pf-template]//input[@type="text"]' )->length;
		$dropdowns     = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " w-dropdown-toggle ")]' )->length;
		$dropdownLists = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " w-dropdown-list ")]' )->length;
		$rows          = $xpath->query( '//*[@pf-filter-row]' )->length;

		return array(
			$this->result(
				'info',
				'input[type=text]',
				sprintf( __( 'поиск по значениям группы — найдено внутри шаблонов: %d', 'pf-filter' ), $text_inputs )
			),
			$this->result(
				'info',
				'w-dropdown-toggle / w-dropdown-list',
				sprintf( __( 'управление дропдаунами (Webflow) — найдено: %1$d / %2$d', 'pf-filter' ), $dropdowns, $dropdownLists )
			),
			$this->result(
				'info',
				'pf-filter-row → parentElement',
				sprintf( __( 'контейнер для клонов строк — найдено строк-шаблонов: %d', 'pf-filter' ), $rows )
			),
		);
	}

	/**
	 * Секция 3 — реальные запросы к собственным REST-эндпоинтам.
	 *
	 * @return array {config: {...}, products: {...}}
	 */
	public function test_rest_api() {
		return array(
			'config'   => $this->test_config_endpoint(),
			'products' => $this->test_products_endpoint(),
		);
	}

	/**
	 * GET /wp-json/pf/v1/config
	 *
	 * @return array
	 */
	private function test_config_endpoint() {
		$start = microtime( true );

		$response = wp_remote_get(
			rest_url( 'pf/v1/config' ),
			array(
				'headers' => array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ),
				'timeout' => 15,
				'cookies' => $_COOKIE, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- пробрасываются как есть, аналогично wp_remote_get() в core.
			)
		);

		$time_ms = round( ( microtime( true ) - $start ) * 1000, 1 );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'time_ms' => $time_ms,
				'error'   => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array(
				'status'  => $code,
				'time_ms' => $time_ms,
				'error'   => wp_remote_retrieve_body( $response ),
			);
		}

		$groups = array();
		foreach ( (array) ( $body['groups'] ?? array() ) as $group ) {
			$groups[] = array(
				'field'        => $group['field'] ?? '',
				'template'     => $group['template'] ?? '',
				'values_count' => isset( $group['values'] ) ? count( $group['values'] ) : 0,
			);
		}

		return array(
			'status'       => $code,
			'time_ms'      => $time_ms,
			'groups_count' => count( $groups ),
			'groups'       => $groups,
		);
	}

	/**
	 * POST /wp-json/pf/v1/products с пустыми фильтрами.
	 *
	 * @return array
	 */
	private function test_products_endpoint() {
		$start = microtime( true );

		$response = wp_remote_post(
			rest_url( 'pf/v1/products' ),
			array(
				'headers' => array(
					'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'filters'        => array(),
						'logic'          => 'and',
						'orderby'        => 'menu_order',
						'order'          => 'ASC',
						'paged'          => 1,
						'posts_per_page' => 12,
					)
				),
				'timeout' => 15,
				'cookies' => $_COOKIE, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- пробрасываются как есть, аналогично wp_remote_get() в core.
			)
		);

		$time_ms = round( ( microtime( true ) - $start ) * 1000, 1 );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'         => 0,
				'time_ms'        => $time_ms,
				'error'          => $response->get_error_message(),
				'debug_log_tail' => $this->get_debug_log_tail(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return array(
				'status'         => $code,
				'time_ms'        => $time_ms,
				'error'          => wp_remote_retrieve_body( $response ),
				'debug_log_tail' => $this->get_debug_log_tail(),
			);
		}

		return array(
			'status'      => $code,
			'time_ms'     => $time_ms,
			'count'       => $body['count'] ?? 0,
			'html_length' => isset( $body['html'] ) ? strlen( $body['html'] ) : 0,
		);
	}

	/**
	 * Последние N строк wp-content/debug.log.
	 *
	 * @param int $lines Количество строк с конца файла.
	 * @return string
	 */
	private function get_debug_log_tail( int $lines = 5 ): string {
		$log_path = WP_CONTENT_DIR . '/debug.log';
		if ( ! file_exists( $log_path ) ) {
			return 'debug.log не найден. Включите WP_DEBUG_LOG в wp-config.php';
		}
		$all_lines = file( $log_path );
		if ( ! $all_lines ) {
			return '';
		}
		return implode( '', array_slice( $all_lines, -$lines ) );
	}
}
