<?php
/**
 * Автоматическое извлечение шаблона карточки товара из PHP-файла реальной
 * страницы каталога — без атрибутов и без ручного создания content-product.php.
 *
 * Идея: тема (особенно кастомная Webflow-сборка) часто рисует карточку прямо
 * инлайном в своём шаблоне страницы (archive-product.php и т.п.), не вызывая
 * wc_get_template_part('content','product'). Плагин не может «угадать», какой
 * кусок разметки — заголовок, а какой — цена: вместо угадывания он находит
 * PHP-файл, которым WordPress рендерит именно эту страницу, и вырезает из него
 * тело цикла товаров внутри [pf-list] — код между the_post() и endwhile.
 * Этот кусок — обычный PHP+HTML вперемешку — кэшируется в файл и затем
 * подключается (include) по одному разу на каждый товар, с setup_postdata()
 * перед каждым — то есть the_title(), get_post_thumbnail_id() и любые функции
 * темы (get_price() и т.п.) отрабатывают так же, как в оригинале, включая
 * условные блоки (if/else) для товаров у которых чего-то не хватает.
 *
 * Если извлечь не получилось (нестандартная структура цикла, файл не найден
 * и т.д.) — вызывающий код (PF_Renderer) откатывается на wc_get_template_part().
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
	 * extract_loop_body()/формата кэш-файла — иначе старые (потенциально
	 * некорректные) закэшированные файлы продолжат использоваться до тех пор,
	 * пока не изменится mtime исходного файла темы.
	 *
	 * @var int
	 */
	const CACHE_VERSION = 2;

	/**
	 * Получить путь к закэшированному файлу с извлечённым шаблоном карточки
	 * для страницы по её URL, либо null если извлечь не удалось.
	 *
	 * @param string $page_url URL страницы каталога (та же, что передаёт JS).
	 * @return string|null
	 */
	public function get_cached_template_file( $page_url ) {
		$source_file = $this->resolve_template_file( $page_url );
		if ( ! $source_file ) {
			return null;
		}

		$mtime = filemtime( $source_file );
		if ( ! $mtime ) {
			return null;
		}

		$cache_dir = $this->get_cache_dir();
		if ( ! $cache_dir ) {
			return null;
		}

		$cache_file = $cache_dir . '/card-v' . self::CACHE_VERSION . '-' . md5( $source_file ) . '-' . $mtime . '.php';

		if ( file_exists( $cache_file ) ) {
			// Пустой файл — результат прошлой неудачной попытки извлечения
			// для этой же версии файла темы; повторно не пытаемся до его изменения.
			return filesize( $cache_file ) > 0 ? $cache_file : null;
		}

		$source  = file_get_contents( $source_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- локальный файл темы, не удалённый URL.
		$snippet = $source ? $this->extract_loop_body( $source ) : null;

		file_put_contents( $cache_file, (string) $snippet ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- кэш плагина, не пользовательский ввод.

		return $snippet ? $cache_file : null;
	}

	/**
	 * Найти PHP-файл, которым в теме рендерится страница по указанному URL:
	 * либо кастомный page template (Template name: ...), назначенный странице
	 * через атрибуты страницы, либо переопределение архива WooCommerce в теме.
	 *
	 * @param string $page_url URL страницы каталога.
	 * @return string|null
	 */
	private function resolve_template_file( $page_url ) {
		$post_id = url_to_postid( $page_url );

		if ( $post_id ) {
			$template_slug = get_page_template_slug( $post_id );
			if ( $template_slug ) {
				foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $theme_dir ) {
					$path = $theme_dir . '/' . ltrim( $template_slug, '/' );
					if ( file_exists( $path ) ) {
						return $path;
					}
				}
			}
		}

		// Страница — не отдельный "page" с кастомным шаблоном (например, это
		// настоящий архив WooCommerce) — пробуем стандартное переопределение темы.
		$located = locate_template( array( 'woocommerce/archive-product.php', 'archive-product.php' ) );

		return $located ? $located : null;
	}

	/**
	 * Вырезать тело цикла товаров внутри [pf-list] из содержимого файла шаблона:
	 * всё что находится между the_post() и endwhile, с учётом вложенности тегов
	 * при поиске границ самого элемента [pf-list].
	 *
	 * @param string $source Содержимое PHP-файла шаблона.
	 * @return string|null
	 */
	private function extract_loop_body( $source ) {
		if ( ! preg_match( '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bpf-list\b[^>]*>/i', $source, $open_match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$tag           = $open_match[1][0];
		$open_tag_end  = $open_match[0][1] + strlen( $open_match[0][0] );
		$close_pos     = $this->find_matching_close_tag( $source, $tag, $open_tag_end );

		if ( null === $close_pos ) {
			return null;
		}

		$list_inner = substr( $source, $open_tag_end, $close_pos - $open_tag_end );

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

		// Снипет подключается через include один раз НА КАЖДЫЙ товар. Именованное
		// объявление function/class внутри него привело бы к неперехватываемой
		// фатальной ошибке "Cannot redeclare" уже на втором товаре — такой шаблон
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
