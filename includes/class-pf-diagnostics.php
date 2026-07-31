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

		// WooCommerce больше не обязателен — плагин умеет фильтровать любой тип
		// записи (см. ROADMAP.md, этап 1). Его отсутствие — ошибка только если
		// плагин настроен именно на product; иначе это ожидаемая конфигурация.
		$post_type_needs_wc = 'product' === PF_Config::get_post_type();
		$checks[]           = $this->make_check(
			'woocommerce_active',
			__( 'WooCommerce активен', 'pf-filter' ),
			class_exists( 'WooCommerce' ),
			$post_type_needs_wc ? 'error' : 'warning',
			class_exists( 'WooCommerce' )
				? ( defined( 'WC_VERSION' ) ? 'v' . WC_VERSION : '' )
				: ( $post_type_needs_wc
					? __( 'WooCommerce не найден или не активирован, а тип записи в настройках — product.', 'pf-filter' )
					: __( 'WooCommerce не активен — плагин настроен на другой тип записи, это ожидаемо.', 'pf-filter' ) )
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
			__( 'WP_DEBUG_LOG', 'pf-filter' ),
			$debug_log_enabled,
			'info',
			$debug_log_enabled
				? __( 'Включён — ошибки пишутся в debug.log.', 'pf-filter' )
				: __( 'Отключён — debug.log вести не будет.', 'pf-filter' )
		);

		return $checks;
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

		$checks[] = $this->check_card_template_extraction( $url );
		$checks   = array_merge( $checks, $this->check_required_attributes( $xpath ) );

		$found_templates = $this->find_template_values( $xpath );
		$checks          = array_merge( $checks, $this->check_group_templates( $xpath, $found_templates ) );
		$checks          = array_merge( $checks, $this->check_smart_hints( $xpath ) );
		$checks          = array_merge( $checks, $this->check_tree_depth( $xpath, $found_templates ) );
		$checks          = array_merge( $checks, $this->check_pagination_markup( $xpath ) );

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
	 * Проверить, удалось ли автоматически извлечь шаблон карточки из PHP-файла
	 * этой страницы (PF_Card_Template) — тот самый механизм, который рендерит
	 * карточки при AJAX-фильтрации. Если не удалось — плагин молча откатится
	 * на PF_Renderer::render_default() (для product при активном WooCommerce —
	 * wc_get_template_part('content','product'), иначе обычная конвенция темы
	 * content-{post_type}.php), что может не совпадать с оформлением темы
	 * (см. предупреждение про content-product.php выше).
	 *
	 * @param string $url URL страницы, которую анализируем.
	 * @return array
	 */
	private function check_card_template_extraction( $url ) {
		$card_template = new PF_Card_Template( $this->attributes );
		$cache_file    = $card_template->get_cached_template_file( $url, PF_Config::get_active_profile_id() );

		return $this->result(
			$cache_file ? 'ok' : 'warning',
			__( 'Автоматическое извлечение шаблона карточки', 'pf-filter' ),
			$cache_file
				? __( 'Найден и используется PHP-шаблон страницы — карточки при AJAX-фильтрации рендерятся тем же кодом, что и при обычной загрузке.', 'pf-filter' )
				: __( 'Не удалось найти/разобрать PHP-шаблон этой страницы. Плагин использует запасной рендер по конвенции темы — карточки при AJAX-фильтрации могут отличаться от исходной страницы.', 'pf-filter' )
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
	 * Проверить, для какой стратегии пагинации на ЭТОЙ конкретной странице
	 * реально есть нужная разметка. Активная стратегия определяется
	 * ИСКЛЮЧИТЕЛЬНО настройкой админки (pagination_strategy) — в разметке
	 * могут одновременно лежать готовые элементы сразу под несколько
	 * стратегий, поэтому по их наличию нельзя судить, какая из них должна
	 * быть активна. Если для активной стратегии нужных элементов нет —
	 * предупреждение (пагинация не будет работать); для остальных стратегий —
	 * информационная строка, доступны ли они на этой странице в принципе.
	 *
	 * @param DOMXPath $xpath XPath документа.
	 * @return array
	 */
	private function check_pagination_markup( DOMXPath $xpath ) {
		$available = self::detect_pagination_availability( $xpath );

		$labels = array(
			'pages'     => __( 'Страницы', 'pf-filter' ),
			'load-more' => __( 'Кнопка «Загрузить ещё»', 'pf-filter' ),
			'both'      => __( 'Кнопка + страницы вместе', 'pf-filter' ),
			'infinite'  => __( 'Автозагрузка по скроллу', 'pf-filter' ),
		);

		$active_strategy = PF_Config::get( 'pagination_strategy', 'pages' );

		$checks = array();

		$is_active_available = ! empty( $available[ $active_strategy ] );
		$checks[] = $this->result(
			$is_active_available ? 'ok' : 'warning',
			__( 'Активная стратегия пагинации (настройка админки)', 'pf-filter' ),
			$is_active_available
				? sprintf(
					/* translators: %s: название стратегии */
					__( '«%s» — нужная разметка на странице найдена.', 'pf-filter' ),
					$labels[ $active_strategy ]
				)
				: sprintf(
					/* translators: %s: название стратегии */
					__( '«%s» выбрана в админке, но на странице нет нужной для неё разметки — пагинация работать не будет.', 'pf-filter' ),
					$labels[ $active_strategy ]
				)
		);

		foreach ( $available as $strategy => $ok ) {
			if ( $strategy === $active_strategy ) {
				continue;
			}
			$checks[] = $this->result(
				$ok ? 'ok' : 'info',
				sprintf( __( 'Стратегия «%s»', 'pf-filter' ), $labels[ $strategy ] ),
				$ok
					? __( 'разметка на странице есть — доступна, если выбрать её как активную.', 'pf-filter' )
					: __( 'разметка не найдена — недоступна на этой странице.', 'pf-filter' )
			);
		}

		return $checks;
	}

	/**
	 * По какой разметке на странице для каких стратегий пагинации есть все
	 * необходимые элементы. Вынесено в статический метод — переиспользуется
	 * и диагностикой (check_pagination_markup), и страницей настроек
	 * (PF_Admin, через get_pagination_availability) для того, чтобы не
	 * предлагать в списке стратегию, для которой в вёрстке нет разметки.
	 *
	 * @param DOMXPath $xpath XPath документа.
	 * @return array {pages, load-more, infinite, both} => bool.
	 */
	public static function detect_pagination_availability( DOMXPath $xpath ) {
		$available = array(
			'pages'     => $xpath->query( '//*[@pf-pagination-pages]' )->length > 0 && $xpath->query( '//*[@pf-page-item]' )->length > 0,
			'load-more' => $xpath->query( '//*[@pf-load-more]' )->length > 0,
			'infinite'  => $xpath->query( '//*[@pf-infinite-trigger]' )->length > 0,
		);
		$available['both'] = $available['pages'] && $available['load-more'];

		return $available;
	}

	/**
	 * То же самое, что detect_pagination_availability(), но по URL — сама
	 * загружает и парсит страницу. Используется страницей настроек, у
	 * которой (в отличие от analyze_markup()) нет уже готового DOMXPath.
	 *
	 * @param string $url URL страницы для сканирования.
	 * @return array|null {pages, load-more, infinite, both} => bool, либо null если страницу не удалось загрузить.
	 */
	public function get_pagination_availability( $url ) {
		$dom = $this->fetch_dom( $url );
		return $dom ? self::detect_pagination_availability( new DOMXPath( $dom ) ) : null;
	}

	/**
	 * Загрузить и распарсить страницу по URL в DOMDocument. Общий шаг для
	 * всех проверок "чего не хватает в вёрстке для настройки X" — страница
	 * скачивается и парсится один раз, а не заново на каждую проверку.
	 *
	 * @param string $url URL страницы для сканирования.
	 * @return DOMDocument|null null, если URL пуст или страницу не удалось загрузить.
	 */
	public function fetch_dom( $url ) {
		if ( empty( $url ) ) {
			return null;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return null;
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		return $dom;
	}

	/**
	 * Есть ли на странице элемент с данным pf-* атрибутом — опционально
	 * только внутри конкретного [pf-template="..."]. Общая проверка "хватает
	 * ли вёрстки для настройки X" — используется страницей настроек, чтобы
	 * предупреждать о настройках, для которых в реальной вёрстке нет нужных
	 * элементов.
	 *
	 * @param DOMXPath    $xpath     XPath уже распарсенной страницы (см. fetch_dom).
	 * @param string      $attribute Имя pf-* атрибута без квадратных скобок (например 'pf-filter-count').
	 * @param string|null $template  Если задано — искать только внутри [pf-template="$template"].
	 * @return bool
	 */
	public static function has_attribute( DOMXPath $xpath, $attribute, $template = null ) {
		$query = $template
			? '//*[@pf-template="' . $template . '"]//*[@' . $attribute . ']'
			: '//*[@' . $attribute . ']';
		return $xpath->query( $query )->length > 0;
	}

	/**
	 * Есть ли внутри конкретного [pf-template="..."] текстовое поле — так
	 * устроен поиск внутри группы (обычный input[type=text] без своего pf-*
	 * атрибута, поэтому has_attribute() тут не подходит).
	 *
	 * @param DOMXPath $xpath    XPath уже распарсенной страницы (см. fetch_dom).
	 * @param string   $template Значение pf-template (например 'checkbox').
	 * @return bool
	 */
	public static function has_text_input( DOMXPath $xpath, $template ) {
		return $xpath->query( '//*[@pf-template="' . $template . '"]//input[@type="text"]' )->length > 0;
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

		$taxonomy = $this->attributes->get_configured_category_tree_taxonomy();
		if ( '' === $taxonomy ) {
			return array(
				$this->result( 'warning', __( 'Глубина дерева категорий', 'pf-filter' ), __( 'Не удалось определить иерархическую таксономию для проверки — настройте группу с шаблоном category-tree.', 'pf-filter' ) ),
			);
		}

		$real_depth = $this->attributes->get_real_category_depth( $taxonomy );

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
		$text_inputs = $xpath->query( '//*[@pf-template]//input[@type="text"]' )->length;
		$rows        = $xpath->query( '//*[@pf-filter-row]' )->length;

		return array(
			$this->result(
				'info',
				'input[type=text]',
				sprintf( __( 'поиск по значениям группы — найдено внутри шаблонов: %d', 'pf-filter' ), $text_inputs )
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
	 * @param string $profile_id ID профиля, диагностика которого сейчас открыта в
	 *                           админке — передаётся в запросы явно, иначе REST API
	 *                           резолвит его сам (первый профиль по умолчанию), что
	 *                           даст неверные результаты не для первого профиля.
	 * @return array {config: {...}, products: {...}}
	 */
	public function test_rest_api( $profile_id = '' ) {
		return array(
			'config'   => $this->test_config_endpoint( $profile_id ),
			'products' => $this->test_products_endpoint( $profile_id ),
		);
	}

	/**
	 * GET /wp-json/pf/v1/config
	 *
	 * @param string $profile_id ID профиля (см. test_rest_api()).
	 * @return array
	 */
	private function test_config_endpoint( $profile_id = '' ) {
		$start = microtime( true );

		$response = wp_remote_get(
			add_query_arg( 'profile', $profile_id, rest_url( 'pf/v1/config' ) ),
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
	 * @param string $profile_id ID профиля (см. test_rest_api()).
	 * @return array
	 */
	private function test_products_endpoint( $profile_id = '' ) {
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
						'profile'        => $profile_id,
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
