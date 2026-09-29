<?php
/**
 * Исправление запроса модуля поиска — запасной путь, когда обычный поиск
 * ничего не нашёл (или нашёл только частично): неверная раскладка
 * клавиатуры, опечатки, транслит. Сам ничего не ищет — предлагает
 * исправленные варианты запроса; применяет их PF_Search_Engine, и только
 * если по исправленному запросу действительно что-то находится.
 *
 * Словарь для исправлений — таблица исходных слов pf_search_words
 * (заголовки, таксономии, ACF), см. PF_Search_Index::add_words().
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Corrector
 */
class PF_Search_Corrector {

	/**
	 * Раскладки: одна и та же клавиша в английской и русской раскладке.
	 */
	const LAYOUT_EN = "qwertyuiop[]asdfghjkl;'zxcvbnm,.`";
	const LAYOUT_RU = 'йцукенгшщзхъфывапролджэячсмитьбюё';

	/**
	 * Сколько кандидатов словаря проверять на одно слово.
	 */
	const MAX_CANDIDATES = 5000;

	/**
	 * Транслит кириллица → латиница.
	 */
	const RU_TO_LAT = array(
		'а' => 'a',
		'б' => 'b',
		'в' => 'v',
		'г' => 'g',
		'д' => 'd',
		'е' => 'e',
		'ж' => 'zh',
		'з' => 'z',
		'и' => 'i',
		'й' => 'y',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'о' => 'o',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'у' => 'u',
		'ф' => 'f',
		'х' => 'h',
		'ц' => 'ts',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'sch',
		'ъ' => '',
		'ы' => 'y',
		'ь' => '',
		'э' => 'e',
		'ю' => 'yu',
		'я' => 'ya',
	);

	/**
	 * Транслит латиница → кириллица (сначала буквосочетания).
	 */
	const LAT_TO_RU = array(
		'sch' => 'щ',
		'sh'  => 'ш',
		'ch'  => 'ч',
		'zh'  => 'ж',
		'kh'  => 'х',
		'ts'  => 'ц',
		'yu'  => 'ю',
		'ya'  => 'я',
		'yo'  => 'ё',
		'ph'  => 'ф',
		'a'   => 'а',
		'b'   => 'б',
		'c'   => 'к',
		'd'   => 'д',
		'e'   => 'е',
		'f'   => 'ф',
		'g'   => 'г',
		'h'   => 'х',
		'i'   => 'и',
		'j'   => 'дж',
		'k'   => 'к',
		'l'   => 'л',
		'm'   => 'м',
		'n'   => 'н',
		'o'   => 'о',
		'p'   => 'п',
		'q'   => 'к',
		'r'   => 'р',
		's'   => 'с',
		't'   => 'т',
		'u'   => 'у',
		'v'   => 'в',
		'w'   => 'в',
		'x'   => 'кс',
		'y'   => 'и',
		'z'   => 'з',
	);

	/**
	 * Запрос, набранный в неверной раскладке: целиком латиница, которая
	 * по клавишам даёт кириллицу, или наоборот. null — не похоже.
	 *
	 * @param string $query Запрос.
	 * @return string|null
	 */
	public static function layout_swap( $query ) {
		$query = mb_strtolower( trim( (string) $query ) );
		if ( '' === $query ) {
			return null;
		}

		$en = self::chars( self::LAYOUT_EN );
		$ru = self::chars( self::LAYOUT_RU );

		$has_lat = (bool) preg_match( '/[a-z]/', $query );
		$has_cyr = (bool) preg_match( '/[а-яё]/u', $query );
		if ( $has_lat === $has_cyr ) {
			return null; // Смешанный или вообще без букв — не раскладка.
		}

		$map = $has_lat ? array_combine( $en, $ru ) : array_combine( $ru, $en );
		$out = '';
		foreach ( self::chars( $query ) as $char ) {
			$out .= $map[ $char ] ?? $char;
		}

		return $out !== $query ? $out : null;
	}

	/**
	 * Слово в другом алфавите (кириллица ↔ латиница). null — нечего менять.
	 *
	 * @param string $word Нормализованное слово.
	 * @return string|null
	 */
	public static function translit( $word ) {
		if ( preg_match( '/^[а-я]+$/u', $word ) ) {
			return strtr( $word, self::RU_TO_LAT );
		}
		if ( preg_match( '/^[a-z]+$/', $word ) ) {
			return strtr( $word, self::LAT_TO_RU );
		}
		return null;
	}

	/**
	 * Допустимое число правок для слова такой длины.
	 *
	 * @param int $len Длина слова.
	 * @return int
	 */
	public static function max_distance( $len ) {
		if ( $len < 4 ) {
			return 0;
		}
		return $len >= 8 ? 2 : 1;
	}

	/**
	 * Ближайшее слово словаря (Дамерау–Левенштейн в пределах допуска,
	 * при равенстве — более частое). Кандидаты — по длине и по первой или
	 * второй букве (опечатка сразу в обеих первых буквах — редкость).
	 *
	 * @param string $word  Нормализованное слово.
	 * @param int    $extra Дополнительный допуск (транслит сам по себе
	 *                      неточен: «конверс» → «konvers», а не «converse»).
	 * @return string|null
	 */
	public static function closest_word( $word, $extra = 0 ) {
		$chars = self::chars( $word );
		$len   = count( $chars );
		$max   = self::max_distance( $len );
		if ( ! $max ) {
			return null;
		}
		$max += (int) $extra;

		global $wpdb;
		$t    = PF_Search_Index::tables();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT word, freq FROM {$t['words']} WHERE len BETWEEN %d AND %d AND ( word LIKE %s OR word LIKE %s ) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$len - $max,
				$len + $max,
				$wpdb->esc_like( $chars[0] ) . '%',
				'_' . $wpdb->esc_like( $chars[1] ?? '' ) . '%',
				self::MAX_CANDIDATES
			)
		);

		$best      = null;
		$best_dist = $max + 1;
		$best_freq = -1;
		foreach ( (array) $rows as $row ) {
			$candidate = (string) $row->word;
			if ( $candidate === $word ) {
				return $word;
			}
			$dist = self::distance( $chars, self::chars( $candidate ), $max );
			if ( $dist < $best_dist || ( $dist === $best_dist && (int) $row->freq > $best_freq ) ) {
				$best      = $candidate;
				$best_dist = $dist;
				$best_freq = (int) $row->freq;
			}
		}

		return $best_dist <= $max ? $best : null;
	}

	/**
	 * Есть ли слово в словаре как есть.
	 *
	 * @param string $word Слово.
	 * @return bool
	 */
	public static function is_known_word( $word ) {
		global $wpdb;
		$t = PF_Search_Index::tables();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t['words']} WHERE word = %s", $word ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Исправленный запрос по словам: слова, которых нет в индексе,
	 * заменяются ближайшими словами словаря (напрямую или через транслит).
	 *
	 * @param array $words        Нормализованные слова запроса.
	 * @param array $known_terms  term => true — стемы, которые есть в индексе.
	 * @return string|null null — исправлять нечего или не на что.
	 */
	public static function correct_words( array $words, array $known_terms ) {
		$changed = false;
		$out     = array();

		foreach ( $words as $word ) {
			$term = PF_Search_Tokenizer::term( $word );
			if ( null === $term || isset( $known_terms[ $term ] ) || ctype_digit( $word ) ) {
				$out[] = $word;
				continue;
			}

			$fix = null;
			$alt = self::translit( $word );
			if ( null !== $alt && self::is_known_word( $alt ) ) {
				$fix = $alt;
			}
			if ( null === $fix ) {
				$fix = self::closest_word( $word );
			}
			if ( null === $fix && null !== $alt ) {
				$fix = self::closest_word( $alt, 1 );
			}

			if ( null !== $fix && $fix !== $word ) {
				$out[]   = $fix;
				$changed = true;
			} else {
				$out[] = $word;
			}
		}

		return $changed ? implode( ' ', $out ) : null;
	}

	/**
	 * Damerau–Levenshtein (optimal string alignment) с досрочным выходом,
	 * когда расстояние заведомо больше $max.
	 *
	 * @param array $a   Символы первого слова.
	 * @param array $b   Символы второго слова.
	 * @param int   $max Порог.
	 * @return int
	 */
	public static function distance( array $a, array $b, $max ) {
		$la = count( $a );
		$lb = count( $b );
		if ( abs( $la - $lb ) > $max ) {
			return $max + 1;
		}

		$prev2 = array();
		$prev  = range( 0, $lb );
		for ( $i = 1; $i <= $la; $i++ ) {
			$cur     = array( $i );
			$row_min = $i;
			for ( $j = 1; $j <= $lb; $j++ ) {
				$cost      = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
				$cur[ $j ] = min( $prev[ $j ] + 1, $cur[ $j - 1 ] + 1, $prev[ $j - 1 ] + $cost );
				if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
					$cur[ $j ] = min( $cur[ $j ], $prev2[ $j - 2 ] + 1 );
				}
				$row_min = min( $row_min, $cur[ $j ] );
			}
			if ( $row_min > $max ) {
				return $max + 1;
			}
			$prev2 = $prev;
			$prev  = $cur;
		}

		return $prev[ $lb ];
	}

	/**
	 * UTF-8 строка → символы.
	 *
	 * @param string $s Строка.
	 * @return array
	 */
	private static function chars( $s ) {
		return preg_split( '//u', (string) $s, -1, PREG_SPLIT_NO_EMPTY );
	}
}
