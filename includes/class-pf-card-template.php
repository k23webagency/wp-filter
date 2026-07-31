<?php
/**
 * Автоматическое извлечение шаблона карточки записи из PHP-файла реальной
 * страницы каталога — без атрибутов и без ручного создания content-{type}.php.
 *
 * Идея: тема (особенно кастомная Webflow-сборка) часто рисует карточку прямо
 * инлайном в своём шаблоне страницы (archive-product.php, archive-uslugi.php,
 * а то и прямо на главной странице сайта — см. ниже), не вызывая
 * wc_get_template_part()/get_template_part(). Плагин не может «угадать», какой
 * кусок разметки — заголовок, а какой — цена: вместо угадывания он находит
 * PHP-файл, которым WordPress рендерит именно эту страницу, и вырезает из
 * него тело цикла записей внутри [pf-list] — код между the_post() и endwhile.
 * Этот кусок — обычный PHP+HTML вперемешку — кэшируется в файл и затем
 * подключается (include) по одному разу на каждую запись, с setup_postdata()
 * перед каждой — то есть the_title(), get_post_thumbnail_id() и любые функции
 * темы (get_price() и т.п.) отрабатывают так же, как в оригинале, включая
 * условные блоки (if/else) для записей у которых чего-то не хватает.
 *
 * Блок фильтрации конкретного профиля (см. PF_Config/[pf-profile] в JS) не
 * обязан жить на архиве своего типа записи — он может быть встроен куда
 * угодно, например маленький виджет фильтра услуг ПЛЮС маленький виджет
 * фильтра новостей рядом, оба на главной странице сайта. Поэтому: (1) поиск
 * PHP-файла страницы обобщён на любой singular-контекст (страница/запись), а
 * не только на архивы; (2) если на найденной странице несколько [pf-list]
 * (по одному на каждый такой встроенный блок), извлекается ИМЕННО тот,
 * что находится внутри контейнера с атрибутом pf-profile="<id профиля>",
 * соответствующего профилю текущего запроса — а не первый попавшийся.
 * Если такого контейнера в файле нет (обычный случай — один блок фильтра на
 * странице, без явной pf-profile-обёртки) — используется первый [pf-list] во
 * всём файле, как и раньше.
 *
 * Если извлечь не получилось (нестандартная структура цикла, файл не найден
 * и т.д.) — вызывающий код (PF_Renderer) откатывается на render_default().
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Card_Template
 */
class PF_Card_Template {

	/**
	 * Версия логики извлечения/кэширования. Увеличивать при любом изменении
	 * extract_loop_body()/resolve_template_candidates()/формата кэш-файла —
	 * иначе старые (потенциально некорректные) закэшированные файлы
	 * продолжат использоваться до тех пор, пока не изменится mtime исходного
	 * файла темы.
	 *
	 * @var int
	 */
	const CACHE_VERSION = 4;

	/**
	 * Сканер атрибутов — нужен, чтобы узнать таксономию «категории»
	 * настроенного профиля (см. resolve_archive_candidates()).
	 *
	 * @var PF_Attributes
	 */
	private $attributes;

	/**
	 * Конструктор.
	 *
	 * @param PF_Attributes|null $attributes Опционально — для переиспользования
	 *                                       уже созданного экземпляра.
	 */
	public function __construct( ?PF_Attributes $attributes = null ) {
		$this->attributes = $attributes ?: new PF_Attributes();
	}

	/**
	 * Получить путь к закэшированному файлу с извлечённым шаблоном карточки
	 * для страницы по её URL, либо null если извлечь не удалось.
	 *
	 * Перебирает кандидатов из resolve_template_candidates() по порядку
	 * приоритета и использует ПЕРВОГО, у которого реально получилось
	 * извлечь тело цикла — а не просто первый существующий файл: у
	 * произвольной страницы разные кандидаты вполне могут оказаться разными
	 * файлами, и не каждый из них обязательно содержит инлайновую разметку
	 * [pf-list] (тем более — [pf-list] именно ЭТОГО профиля, см. $profile_id).
	 *
	 * @param string $page_url   URL страницы каталога (та же, что передаёт JS).
	 * @param string $profile_id ID профиля текущего запроса (см. PF_Config) —
	 *                           используется, чтобы на странице с НЕСКОЛЬКИМИ
	 *                           встроенными блоками фильтра найти [pf-list]
	 *                           именно этого блока, а не случайно первый
	 *                           попавшийся. Пустая строка — как раньше, первый
	 *                           [pf-list] в файле.
	 * @return string|null
	 */
	public function get_cached_template_file( $page_url, $profile_id = '' ) {
		$candidates = $this->resolve_template_candidates( $page_url );
		if ( empty( $candidates ) ) {
			return null;
		}

		$cache_dir = $this->get_cache_dir();
		if ( ! $cache_dir ) {
			return null;
		}

		foreach ( $candidates as $source_file ) {
			$mtime = filemtime( $source_file );
			if ( ! $mtime ) {
				continue;
			}

			// Один и тот же файл темы может давать РАЗНЫЙ извлечённый снипет
			// в зависимости от профиля (несколько [pf-list] на одной
			// странице) — профиль обязательно часть ключа кэша.
			$cache_key  = md5( $source_file . '|' . $profile_id );
			$cache_file = $cache_dir . '/card-v' . self::CACHE_VERSION . '-' . $cache_key . '-' . $mtime . '.php';

			if ( file_exists( $cache_file ) ) {
				if ( filesize( $cache_file ) > 0 ) {
					return $cache_file;
				}
				// Пустой файл — прошлая неудачная попытка извлечения ИМЕННО
				// из этого файла (и этого профиля) для этой же версии —
				// пробуем следующего кандидата, а не сдаёмся сразу.
				continue;
			}

			$source  = file_get_contents( $source_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- локальный файл темы, не удалённый URL.
			$snippet = $source ? $this->extract_loop_body( $source, $profile_id ) : null;

			file_put_contents( $cache_file, (string) $snippet ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- кэш плагина, не пользовательский ввод.

			if ( $snippet ) {
				return $cache_file;
			}
		}

		return null;
	}

	/**
	 * Список PHP-файлов темы — кандидатов на «настоящий шаблон» страницы по
	 * указанному URL, от самого точного к самому общему. Блок фильтрации
	 * может быть встроен на ЛЮБУЮ страницу (не только архив своего типа
	 * записи) — сначала пробуем распознать страницу как конкретную
	 * запись/страницу (singular), и только если это не удалось — как архив.
	 *
	 * @param string $page_url URL страницы каталога.
	 * @return string[] Абсолютные пути к существующим файлам, без дублей.
	 */
	private function resolve_template_candidates( $page_url ) {
		$post_id = url_to_postid( $page_url );

		return $post_id ? $this->resolve_singular_candidates( $post_id ) : $this->resolve_archive_candidates();
	}

	/**
	 * Кандидаты для URL, который резолвится в конкретную запись/страницу
	 * (url_to_postid() нашёл её) — например, главная страница сайта со
	 * встроенным виджетом фильтра, произвольная страница услуг и т.п.
	 *
	 * Порядок: кастомный page template (Template Name: ..., назначенный
	 * через атрибуты страницы) — самый явный сигнал, пробуется первым; для
	 * "page" дальше идёт обычная WordPress-иерархия (front-page.php — если
	 * это назначенная статическая главная, page-{slug}.php, page-{id}.php,
	 * page.php); для остальных типов записи — single-{post_type}-{slug}.php,
	 * single-{post_type}.php, single.php; в конце — общие singular.php,
	 * index.php.
	 *
	 * @param int $post_id ID записи/страницы.
	 * @return string[] Абсолютные пути к существующим файлам, без дублей.
	 */
	private function resolve_singular_candidates( $post_id ) {
		$found = array();

		$template_slug = get_page_template_slug( $post_id );
		if ( $template_slug ) {
			foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $theme_dir ) {
				$path = $this->resolve_within_dir( $theme_dir, $template_slug );
				if ( $path && ! in_array( $path, $found, true ) ) {
					$found[] = $path;
				}
			}
		}

		$post      = get_post( $post_id );
		$hierarchy = array();

		if ( $post && 'page' === $post->post_type ) {
			if ( (int) get_option( 'page_on_front' ) === $post_id ) {
				$hierarchy[] = 'front-page.php';
			}
			if ( $post->post_name ) {
				$hierarchy[] = 'page-' . $post->post_name . '.php';
			}
			$hierarchy[] = 'page-' . $post_id . '.php';
			$hierarchy[] = 'page.php';
		} elseif ( $post ) {
			if ( $post->post_name ) {
				$hierarchy[] = 'single-' . $post->post_type . '-' . $post->post_name . '.php';
			}
			$hierarchy[] = 'single-' . $post->post_type . '.php';
			$hierarchy[] = 'single.php';
		}

		$hierarchy[] = 'singular.php';
		$hierarchy[] = 'index.php';

		foreach ( $hierarchy as $relative ) {
			$located = locate_template( $relative );
			if ( $located && ! in_array( $located, $found, true ) ) {
				$found[] = $located;
			}
		}

		return $found;
	}

	/**
	 * Абсолютный путь к $relative внутри $base_dir, либо null — если файла
	 * нет, или (главное) если после разрешения `../`/симлинков результат
	 * оказался ВНЕ $base_dir. get_page_template_slug() по умолчанию всегда
	 * возвращает значение, которое сам WordPress уже сверил со списком
	 * реальных файлов темы (см. get_page_templates()) при сохранении — но
	 * это гарантия чужого кода (админки/REST-контроллера страниц), а не
	 * этого плагина; дешёвая проверка здесь не полагается на неё слепо.
	 *
	 * @param string $base_dir Директория темы (get_stylesheet_directory()/get_template_directory()).
	 * @param string $relative Слаг шаблона (_wp_page_template) — ожидается относительный путь внутри темы.
	 * @return string|null
	 */
	private function resolve_within_dir( $base_dir, $relative ) {
		$path = $base_dir . '/' . ltrim( $relative, '/' );

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$real_base = realpath( $base_dir );
		$real_path = realpath( $path );

		if ( ! $real_base || ! $real_path || 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Кандидаты для URL, который НЕ резолвится в конкретную запись —
	 * настоящий архив (каталога, категории, блога). Иерархия под
	 * настроенный тип записи текущего профиля (см. PF_Config::get_post_type()):
	 * для product сперва woocommerce/archive-product.php/archive-product.php
	 * (WooCommerce сводит к ним ВСЕ архивы товаров — магазин/категория/тег —
	 * через собственный template_include, самый частый случай), затем
	 * archive-{post_type}.php, таксономия-«категория» этого типа записи
	 * (если настроена, см. PF_Attributes::get_configured_category_tree_taxonomy()),
	 * для post ещё и home.php, и в конце общие archive.php/index.php.
	 *
	 * @return string[] Абсолютные пути к существующим файлам, без дублей.
	 */
	private function resolve_archive_candidates() {
		$post_type = PF_Config::get_post_type();
		$hierarchy = array();

		if ( 'product' === $post_type ) {
			$hierarchy[] = 'woocommerce/archive-product.php';
			$hierarchy[] = 'archive-product.php';
		}

		$hierarchy[] = 'archive-' . $post_type . '.php';

		$category_taxonomy = $this->attributes->get_configured_category_tree_taxonomy();
		if ( $category_taxonomy ) {
			$hierarchy[] = 'taxonomy-' . $category_taxonomy . '.php';
		}

		if ( 'post' === $post_type ) {
			$hierarchy[] = 'home.php';
		}

		$hierarchy[] = 'archive.php';
		$hierarchy[] = 'index.php';

		$found = array();
		foreach ( $hierarchy as $relative ) {
			$located = locate_template( $relative );
			if ( $located && ! in_array( $located, $found, true ) ) {
				$found[] = $located;
			}
		}

		return $found;
	}

	/**
	 * Найти границы контейнера с атрибутом pf-profile="$profile_id" в
	 * содержимом файла — используется, чтобы на странице с несколькими
	 * встроенными блоками фильтра (разные профили) искать [pf-list] именно
	 * внутри "своего" блока, а не первый попавшийся в файле.
	 *
	 * @param string $source     Содержимое PHP-файла шаблона.
	 * @param string $profile_id ID профиля.
	 * @return string|null Содержимое между открывающим и закрывающим тегом
	 *                      контейнера, либо null если такой контейнер не
	 *                      найден (тогда вызывающий код ищет по всему файлу).
	 */
	private function find_profile_scope( $source, $profile_id ) {
		$pattern = '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bpf-profile\s*=\s*(["\'])' . preg_quote( $profile_id, '/' ) . '\2[^>]*>/i';

		if ( ! preg_match( $pattern, $source, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$tag          = $match[1][0];
		$open_tag_end = $match[0][1] + strlen( $match[0][0] );
		$close_pos    = $this->find_matching_close_tag( $source, $tag, $open_tag_end );

		if ( null === $close_pos ) {
			return null;
		}

		return substr( $source, $open_tag_end, $close_pos - $open_tag_end );
	}

	/**
	 * Вырезать тело цикла записей внутри [pf-list] из содержимого файла
	 * шаблона: всё что находится между the_post() и endwhile, с учётом
	 * вложенности тегов при поиске границ самого элемента [pf-list].
	 *
	 * @param string $source     Содержимое PHP-файла шаблона.
	 * @param string $profile_id ID профиля — если задан и в файле есть
	 *                           контейнер pf-profile="$profile_id" (см.
	 *                           find_profile_scope()), [pf-list] ищется
	 *                           только внутри него.
	 * @return string|null
	 */
	private function extract_loop_body( $source, $profile_id = '' ) {
		$search_in = $source;

		if ( '' !== $profile_id ) {
			$scoped = $this->find_profile_scope( $source, $profile_id );
			if ( null !== $scoped ) {
				$search_in = $scoped;
			}
		}

		if ( ! preg_match( '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bpf-list\b[^>]*>/i', $search_in, $open_match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$tag           = $open_match[1][0];
		$open_tag_end  = $open_match[0][1] + strlen( $open_match[0][0] );
		$close_pos     = $this->find_matching_close_tag( $search_in, $tag, $open_tag_end );

		if ( null === $close_pos ) {
			return null;
		}

		$list_inner = substr( $search_in, $open_tag_end, $close_pos - $open_tag_end );

		if ( ! preg_match( '/the_post\s*\(\s*\)\s*;/i', $list_inner, $post_match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$body_start = $post_match[0][1] + strlen( $post_match[0][0] );

		$tail = substr( $list_inner, $body_start );
		if ( ! preg_match( '/<\?php\s*endwhile\s*;/i', $tail, $end_match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$body_end = $body_start + $end_match[0][1];

		$snippet = substr( $list_inner, $body_start, $body_end - $body_start );

		if ( '' === trim( $snippet ) ) {
			return null;
		}

		// Снипет подключается через include один раз НА КАЖДУЮ запись. Именованное
		// объявление function/class внутри него привело бы к неперехватываемой
		// фатальной ошибке "Cannot redeclare" уже на второй записи — такой шаблон
		// использовать нельзя. Анонимные функции (closures) не редекларируются —
		// они безопасны, поэтому проверяем именно именованные объявления.
		if ( preg_match( '/\bfunction\s+[a-zA-Z_]\w*\s*\(/i', $snippet ) || preg_match( '/\bclass\s+[a-zA-Z_]\w*/i', $snippet ) ) {
			return null;
		}

		// В исходном файле этот кусок начинается ВНУТРИ уже открытого PHP-блока
		// (сразу после the_post()) — своего открывающего тега у него нет. Как
		// отдельный include-файл он должен сам открывать PHP-режим, иначе всё
		// до первого настоящего открывающего тега внутри снипета (например,
		// $rotation++ из примера темы) выведется как обычный текст, а не выполнится.
		return '<?php ' . $snippet;
	}

	/**
	 * Найти позицию закрывающего тега, соответствующего открывающему (с учётом
	 * вложенных тегов того же имени), начиная поиск с $start.
	 *
	 * @param string $source Полный текст, в котором ищем.
	 * @param string $tag    Имя тега (например, "div").
	 * @param int    $start  Позиция сразу после открывающего тега.
	 * @return int|null Позиция начала соответствующего закрывающего тега, либо null.
	 */
	private function find_matching_close_tag( $source, $tag, $start ) {
		$open_re  = '/<' . preg_quote( $tag, '/' ) . '\b/i';
		$close_re = '/<\/' . preg_quote( $tag, '/' ) . '\s*>/i';

		$depth = 1;
		$pos   = $start;
		$len   = strlen( $source );

		while ( $pos < $len ) {
			$has_open  = preg_match( $open_re, $source, $om, PREG_OFFSET_CAPTURE, $pos );
			$has_close = preg_match( $close_re, $source, $cm, PREG_OFFSET_CAPTURE, $pos );

			if ( ! $has_close ) {
				return null; // разметка не сбалансирована — дальше некуда.
			}

			if ( $has_open && $om[0][1] < $cm[0][1] ) {
				$depth++;
				$pos = $om[0][1] + 1;
			} else {
				--$depth;
				$pos = $cm[0][1] + strlen( $cm[0][0] );
				if ( 0 === $depth ) {
					return $cm[0][1];
				}
			}
		}

		return null;
	}

	/**
	 * Директория кэша извлечённых шаблонов (внутри wp-content/uploads, закрыта
	 * от прямого веб-доступа).
	 *
	 * @return string|null
	 */
	private function get_cache_dir() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return null;
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . 'pf-filter-cache';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return is_dir( $dir ) && wp_is_writable( $dir ) ? $dir : null;
	}
}
