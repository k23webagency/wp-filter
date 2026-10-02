<?php
/**
 * REST-эндпоинт выдачи модуля поиска: GET /wp-json/pf/v1/search.
 *
 * Публичный и только на чтение (как встроенный /wp/v2/search): отдаёт
 * только опубликованные записи, которые и так видны на сайте. Nonce не
 * требуется намеренно — поле поиска обычно стоит в шапке каждой страницы,
 * а nonce в закэшированной странице со временем протухает.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_REST
 */
class PF_Search_REST {

	/**
	 * Максимум результатов на страницу выдачи.
	 */
	const MAX_PER_PAGE = 50;

	/**
	 * Время жизни серверного кэша ответа, секунд (только при постоянном
	 * объектном кэше — см. search()).
	 */
	const CACHE_TTL = 300;

	/**
	 * Регистрация маршрута.
	 */
	public static function register_routes() {
		register_rest_route(
			PF_REST_API::NAMESPACE_,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'profile'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'type'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'render'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			PF_REST_API::NAMESPACE_,
			'/search/log',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'log' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /pf/v1/search/log — beacon живого поиска, на котором посетитель
	 * остановился (см. pfs-search.js). Публичный, как и сам поиск; при
	 * выключенной аналитике — ничего не делает. Размер агрегата ограничен
	 * PF_Search_Analytics::prune().
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	public static function log( WP_REST_Request $request ) {
		if ( PF_Search_Analytics::is_enabled() ) {
			$resolved = PF_Search_Config::resolve_profile( sanitize_key( (string) $request->get_param( 'profile' ) ) );
			if ( $resolved ) {
				$type = PF_Search_Config::resolve_type( $resolved['profile'], sanitize_key( (string) $request->get_param( 'type' ) ) );
				PF_Search_Analytics::log( sanitize_text_field( (string) $request->get_param( 'q' ) ), $resolved['id'], $type, absint( $request->get_param( 'total' ) ) );
			}
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * GET /pf/v1/search
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	public static function search( WP_REST_Request $request ) {
		$resolved = PF_Search_Config::resolve_profile( (string) $request->get_param( 'profile' ) );
		if ( ! $resolved ) {
			return new WP_REST_Response( self::empty_response( '' ), 200 );
		}

		$profile  = $resolved['profile'];
		$type     = PF_Search_Config::resolve_type( $profile, (string) $request->get_param( 'type' ) );
		$query    = (string) $request->get_param( 'q' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( self::MAX_PER_PAGE, $per_page ) : max( 1, (int) $profile['dropdown_limit'] );
		$render   = (bool) $request->get_param( 'render' );

		// Серверный кэш — только при постоянном объектном кэше (Redis,
		// Memcached): без него wp_cache живёт в рамках одного запроса, а
		// transient'ы писали бы в wp_options строку на каждый уникальный
		// запрос посетителя — это дороже самого поиска по индексу.
		$cache_key = '';
		if ( wp_using_ext_object_cache() ) {
			$state     = PF_Search_Index::get_state();
			$cache_key = md5( wp_json_encode( array( $resolved['id'], $type, $query, $page, $per_page, $render, $state['gen'], wp_cache_get_last_changed( 'posts' ) ) ) );
			$cached    = wp_cache_get( $cache_key, 'pf_search' );
			if ( false !== $cached ) {
				return new WP_REST_Response( $cached, 200 );
			}
		}

		$result = PF_Search_Engine::search(
			$profile,
			$query,
			$type,
			array(
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		$items = array();
		foreach ( $result['ids'] as $id ) {
			$items[] = array(
				'id'    => $id,
				'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'   => get_permalink( $id ),
			);
		}

		$data = array(
			'items'    => $items,
			'total'    => $result['total'],
			'page'     => $page,
			'pages'    => (int) ceil( $result['total'] / $per_page ),
			'profile'  => $resolved['id'],
			'type'     => $type,
			'mode'     => $result['mode'],
			'suggest'  => $result['suggest'],
			'took_ms'  => $result['took_ms'],
		);

		if ( $render ) {
			$data += self::render_cards( $resolved['id'], $profile, $type, $result['ids'], null !== $result['suggest'] ? $result['suggest'] : $query );
			$data['suggest'] = $result['suggest'];
		}

		if ( $cache_key ) {
			wp_cache_set( $cache_key, $data, 'pf_search', self::CACHE_TTL );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Карточки выпадающего окна: цикл темы из [pf-search="results"] (см.
	 * PF_Search_Template), по одному include на запись — тем же кодом, что
	 * карточки [pf-list] фильтра (PF_Renderer).
	 *
	 * @param string $profile_id ID профиля.
	 * @param array  $profile    Профиль.
	 * @param string $type       Тип записей.
	 * @param array  $ids        Найденные ID в порядке выдачи.
	 * @param string $query      Запрос (для слов подсветки).
	 * @return array html, group, template (найден ли шаблон), highlight, suggest.
	 */
	private static function render_cards( $profile_id, array $profile, $type, array $ids, $query ) {
		$group    = (string) ( $profile['group_variants'][ $type ] ?? '' );
		$template = ( new PF_Search_Template() )->get_cached_template( $profile_id, $group );
		$html     = '';

		if ( $template && $ids ) {
			$cards = new WP_Query(
				array(
					'post_type'           => $type,
					'post_status'         => 'publish',
					'post__in'            => $ids,
					'orderby'             => 'post__in',
					'posts_per_page'      => count( $ids ),
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);
			try {
				$html = ( new PF_Renderer() )->render_with_extracted_template( $cards, $template['file'] );
			} catch ( \Throwable $e ) {
				$html     = '';
				$template = null;
			}
		}

		return array(
			'html'      => $html,
			'group'     => $template ? $template['group'] : '',
			'template'  => (bool) $template,
			'highlight' => PF_Search_Engine::highlight_terms( $query ),
			'suggest'   => null,
		);
	}

	/**
	 * Пустой ответ.
	 *
	 * @param string $profile_id ID профиля.
	 * @return array
	 */
	private static function empty_response( $profile_id ) {
		return array(
			'items'   => array(),
			'total'   => 0,
			'page'    => 1,
			'pages'   => 0,
			'profile' => $profile_id,
			'type'    => '',
			'mode'    => 'empty',
			'took_ms' => 0,
		);
	}
}
