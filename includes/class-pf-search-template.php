<?php
/**
 * Шаблон карточки выпадающего окна поиска: цикл записей темы внутри
 * [pfs-results] — тот же принцип, что PF_Card_Template для [pf-list]
 * фильтра (вырезать тело цикла между the_post() и endwhile, кэшировать
 * в файл, подключать include на каждую запись).
 *
 * Отличие — где искать файл. Блок поиска обычно стоит в шапке
 * (header.php, шаблон шапки), а не в шаблоне страницы, поэтому файл
 * находится сканированием PHP-файлов темы (дочерней и родительской) на
 * атрибут pfs-results. Список таких файлов кэшируется ненадолго; сам
 * извлечённый снипет — по mtime исходного файла, как у фильтра.
 *
 * Какой блок в файле: корень [pfs="<id профиля>"]; корень с пустым
 * значением pfs относится к первому профилю поиска. Внутри корня — либо
 * [pfs-group="<вариант>"] (если вариант задан для типа записей в профиле
 * и такая группа есть), либо первый [pfs-results].
 *
 * Любая неудача — null (вызывающий код отдаёт пустой html с template:false,
 * а фронт отключает окно с console.warn). Никогда не фатальная ошибка.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Template
 */
class PF_Search_Template {

	/**
	 * Версия логики извлечения/формата кэша — увеличивать при любом
	 * изменении, иначе останутся старые закэшированные снипеты.
	 */
	const CACHE_VERSION = 1;

	/**
	 * Transient со списком файлов темы, где есть pfs-results.
	 */
	const FILES_TRANSIENT = 'pf_search_template_files';

	/**
	 * Сколько живёт список файлов (новый файл с блоком поиска подхватится
	 * не позже чем через это время; изменения уже найденных файлов —
	 * сразу, по mtime).
	 */
	const FILES_TTL = 600;

	/**
	 * Потолок сканирования темы.
	 */
	const MAX_FILES = 3000;

	/**
	 * Открывающий тег корня блока поиска: атрибут pfs (не pfs-*), значение
	 * необязательно. Группы: 1 — тег, 3 — значение.
	 */
	const ROOT_PATTERN = '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(?<![\w-])pfs(?:\s*=\s*(["\'])([^"\']*)\2)?(?=[\s>\/])[^>]*>/i';

	/**
	 * Каталоги, которые не сканируются.
	 */
	const SKIP_DIRS = array( 'node_modules', 'vendor', '.git', '.svn', 'assets', 'images', 'img', 'fonts', 'css', 'js' );

	/**
	 * Инструменты разбора (общие с фильтром).
	 *
	 * @var PF_Card_Template
	 */
	private $parser;

	/**
	 * Конструктор.
	 */
	public function __construct() {
		$this->parser = new PF_Card_Template();
	}

	/**
	 * Закэшированный снипет карточки для профиля и варианта.
	 *
	 * @param string $profile_id ID профиля поиска.
	 * @param string $group      Имя варианта pfs-group (пусто — первый [pfs-results]).
	 * @return array{file:string,group:string}|null file — путь к снипету для include,
	 *               group — вариант, который реально использован ('' — без группы/первая).
	 */
	public function get_cached_template( $profile_id, $group = '' ) {
		$cache_dir = $this->parser->get_cache_dir();
		if ( ! $cache_dir ) {
			return null;
		}

		$is_first = $this->is_first_profile( $profile_id );

		foreach ( $this->get_candidate_files() as $source_file ) {
			$mtime = @filemtime( $source_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- файл мог исчезнуть между сканированием и чтением.
			if ( ! $mtime ) {
				continue;
			}

			foreach ( array_unique( array( $group, '' ) ) as $try_group ) {
				$key        = md5( $source_file . '|' . $profile_id . '|' . $try_group . '|' . ( $is_first ? 1 : 0 ) );
				$cache_file = $cache_dir . '/search-v' . self::CACHE_VERSION . '-' . $key . '-' . $mtime . '.php';

				if ( file_exists( $cache_file ) ) {
					if ( filesize( $cache_file ) > 0 ) {
						return array(
							'file'  => $cache_file,
							'group' => $try_group,
						);
					}
					continue;
				}

				$source  = file_get_contents( $source_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- локальный файл темы.
				$snippet = $source ? $this->extract( $source, $profile_id, $try_group, $is_first ) : null;

				file_put_contents( $cache_file, (string) $snippet ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- кэш плагина.

				if ( $snippet ) {
					return array(
						'file'  => $cache_file,
						'group' => $try_group,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Для админки: где найден блок профиля и какие варианты pfs-group в нём есть.
	 *
	 * @param string $profile_id ID профиля поиска.
	 * @return array{file:string,groups:array}|null file — путь относительно темы.
	 */
	public function describe( $profile_id ) {
		$is_first = $this->is_first_profile( $profile_id );

		foreach ( $this->get_candidate_files() as $source_file ) {
			$source = file_get_contents( $source_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! $source ) {
				continue;
			}
			$root = $this->find_root( $source, $profile_id, $is_first );
			if ( null === $root || false === strpos( $root, 'pfs-results' ) ) {
				continue;
			}

			$groups = array();
			if ( preg_match_all( '/(?<![\w-])pfs-group\s*=\s*(["\'])([^"\']+)\1/i', $root, $m ) ) {
				$groups = array_values( array_unique( $m[2] ) );
			}

			return array(
				'file'   => $this->relative_path( $source_file ),
				'groups' => $groups,
			);
		}

		return null;
	}

	/**
	 * Для админки, когда блок профиля не найден: какие блоки [pfs] вообще
	 * есть в файлах темы — с их ID, файлом и тем, есть ли внутри
	 * [pfs-results] с циклом записей. Помогает сразу увидеть несовпадение
	 * ID (pfs="shop" в вёрстке, а у профиля ID search) или отсутствие метки
	 * цикла на [pfs-results].
	 *
	 * @return array Список ['value' => string, 'file' => string, 'has_results' => bool, 'has_loop' => bool].
	 */
	public function list_roots() {
		$out = array();
		foreach ( $this->get_candidate_files() as $source_file ) {
			$source = file_get_contents( $source_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! $source || ! preg_match_all( self::ROOT_PATTERN, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches as $m ) {
				$inner = $this->inner_of_match( $source, $m );
				if ( null === $inner ) {
					continue;
				}
				$results = $this->parser->find_element_inner( $inner, '(?<![\w-])pfs-results(?![\w-])' );
				$out[]   = array(
					'value'       => isset( $m[3] ) ? (string) $m[3][0] : '',
					'file'        => $this->relative_path( $source_file ),
					'has_results' => null !== $results,
					'has_loop'    => null !== $results && (bool) preg_match( '/the_post\s*\(\s*\)\s*;/i', $results ),
				);
			}
		}
		return $out;
	}

	/**
	 * Сбросить список файлов (после смены темы, со страницы настроек).
	 */
	public static function flush_files_cache() {
		delete_transient( self::FILES_TRANSIENT );
	}

	/**
	 * Вырезать снипет цикла для профиля/варианта из содержимого файла.
	 *
	 * @param string $source     Содержимое файла.
	 * @param string $profile_id ID профиля.
	 * @param string $group      Вариант pfs-group ('' — первый [pfs-results] в корне).
	 * @param bool   $is_first   Профиль — первый (обслуживает корень с пустым pfs).
	 * @return string|null
	 */
	private function extract( $source, $profile_id, $group, $is_first ) {
		$root = $this->find_root( $source, $profile_id, $is_first );
		if ( null === $root ) {
			return null;
		}

		$scope = $root;
		if ( '' !== $group ) {
			// Без захватывающих групп: фрагмент вставляется в регулярку
			// find_element_inner(), где группа 1 — имя тега.
			$scope = $this->parser->find_element_inner( $root, '(?<![\w-])pfs-group\s*=\s*["\']' . preg_quote( $group, '/' ) . '["\']' );
			if ( null === $scope ) {
				return null;
			}
		}

		$results = $this->parser->find_element_inner( $scope, '(?<![\w-])pfs-results(?![\w-])' );
		if ( null === $results ) {
			return null;
		}

		return $this->parser->extract_loop_from_inner( $results );
	}

	/**
	 * Содержимое корня [pfs="<id>"] (или корня с пустым pfs для первого
	 * профиля) в файле.
	 *
	 * @param string $source     Содержимое файла.
	 * @param string $profile_id ID профиля.
	 * @param bool   $is_first   Профиль первый.
	 * @return string|null
	 */
	private function find_root( $source, $profile_id, $is_first ) {
		if ( ! preg_match_all( self::ROOT_PATTERN, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			$matches = array();
		}

		$fallback = null;
		foreach ( $matches as $m ) {
			$value = isset( $m[3] ) ? (string) $m[3][0] : '';
			if ( $value === $profile_id ) {
				$chosen = $m;
			} elseif ( '' === $value && $is_first && null === $fallback ) {
				$fallback = $m;
				continue;
			} else {
				continue;
			}

			$inner = $this->inner_of_match( $source, $chosen );
			if ( null !== $inner ) {
				return $inner;
			}
		}

		if ( $fallback ) {
			return $this->inner_of_match( $source, $fallback );
		}

		// Поле поиска как группа фильтра: шаблон pf-template="search" — корень
		// без собственного значения pfs (профиль задаётся в настройках группы),
		// подходит любому профилю.
		$group_root = $this->parser->find_element_inner( $source, '\bpf-template\s*=\s*["\']search["\']' );
		if ( null !== $group_root && false !== strpos( $group_root, 'pfs-results' ) ) {
			return $group_root;
		}

		return null;
	}

	/**
	 * Внутреннее содержимое элемента по совпадению его открывающего тега.
	 *
	 * @param string $source Текст.
	 * @param array  $m      Совпадение (PREG_OFFSET_CAPTURE).
	 * @return string|null
	 */
	private function inner_of_match( $source, array $m ) {
		$tag          = $m[1][0];
		$open_tag_end = $m[0][1] + strlen( $m[0][0] );
		$close_pos    = $this->parser->find_matching_close_tag( $source, $tag, $open_tag_end );

		return null === $close_pos ? null : substr( $source, $open_tag_end, $close_pos - $open_tag_end );
	}

	/**
	 * PHP-файлы темы, где встречается pfs-results (дочерняя тема первой).
	 *
	 * @return string[]
	 */
	private function get_candidate_files() {
		$key    = get_stylesheet();
		$cached = get_transient( self::FILES_TRANSIENT );
		if ( is_array( $cached ) && ( $cached['theme'] ?? '' ) === $key && isset( $cached['files'] ) ) {
			return array_values( array_filter( (array) $cached['files'], 'is_file' ) );
		}

		$files = array();
		$dirs  = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$seen  = 0;

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveCallbackFilterIterator(
						new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
						static function ( $current ) {
							if ( $current->isDir() ) {
								return ! in_array( strtolower( $current->getFilename() ), self::SKIP_DIRS, true );
							}
							return 'php' === strtolower( $current->getExtension() );
						}
					)
				);
				foreach ( $iterator as $file ) {
					if ( ++$seen > self::MAX_FILES ) {
						break 2;
					}
					if ( $file->getSize() > 2 * MB_IN_BYTES ) {
						continue;
					}
					$content = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( $content && false !== strpos( $content, 'pfs-results' ) ) {
						$files[] = $file->getPathname();
					}
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		// Сначала файлы с явным [pfs="..."] шапки/подвала — обычно их мало,
		// порядок внутри не важен: блок ищется по ID профиля.
		sort( $files );

		set_transient(
			self::FILES_TRANSIENT,
			array(
				'theme' => $key,
				'files' => $files,
			),
			self::FILES_TTL
		);

		return $files;
	}

	/**
	 * Профиль — первый по порядку (корень с пустым pfs относится к нему).
	 *
	 * @param string $profile_id ID профиля.
	 * @return bool
	 */
	private function is_first_profile( $profile_id ) {
		$first = PF_Search_Config::resolve_profile( '' );
		return $first && $first['id'] === $profile_id;
	}

	/**
	 * Путь относительно каталога тем (для показа в админке).
	 *
	 * @param string $path Абсолютный путь.
	 * @return string
	 */
	private function relative_path( $path ) {
		$root = wp_normalize_path( get_theme_root() );
		$path = wp_normalize_path( $path );
		return 0 === strpos( $path, $root ) ? ltrim( substr( $path, strlen( $root ) ), '/' ) : basename( $path );
	}
}
