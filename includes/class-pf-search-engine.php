<?php
/**
 * Поисковый движок: разбор запроса, поиск термов в словаре, ранжирование
 * BM25F по постингам и бонусы (точная фраза в заголовке, артикул,
 * наличие, популярность).
 *
 * Поиск идёт ровно по одному типу записей (смешанной выдачи нет — см.
 * ROADMAP, «Модуль поиска»). Все слова запроса обязательны; если так не
 * нашлось ничего, а слов несколько — выдача по частичному совпадению
 * (записи, где совпало больше слов, выше).
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Engine
 */
class PF_Search_Engine {

	/**
	 * Минимальная длина запроса (символов, после trim).
	 */
	const MIN_QUERY_LENGTH = 3;

	/**
	 * Максимум слов запроса, которые учитываются.
	 */
	const MAX_WORDS = 8;

	/**
	 * Сколько вариантов дописывания последнего слова учитывать.
	 */
	const MAX_PREFIX_TERMS = 40;

	/**
	 * Потолок числа строк постингов за запрос (защита от запроса из одних
	 * очень частых слов на большом сайте).
	 */
	const MAX_POSTING_ROWS = 80000;

	/**
	 * Сколько лучших кандидатов получает бонусы и попадает в выдачу.
	 */
	const MAX_CANDIDATES = 3000;

	/**
	 * Параметры BM25.
	 */
	const K1 = 1.2;

	/**
	 * Вес совпадения только по дописыванию слова (не по полному слову).
	 */
	const PREFIX_WEIGHT = 0.7;

	/**
	 * Вес совпадения по варианту с беглой гласной (см. fleeting_vowel_variants()).
	 */
	const FLEETING_VOWEL_WEIGHT = 0.95;

	/**
	 * Выполнить поиск.
	 *
	 * @param array  $profile Профиль поиска (PF_Search_Config).
	 * @param string $query   Строка запроса.
	 * @param string $type    Тип записей (уже проверенный resolve_type()).
	 * @param array  $opts    page, per_page, prefix (дописывать последнее слово), debug.
	 * @return array{ids:array,total:int,took_ms:float,mode:string,debug:array}
	 */
	public static function search( array $profile, $query, $type, array $opts = array() ) {
		$started = microtime( true );
		$opts    = wp_parse_args(
			$opts,
			array(
				'page'     => 1,
				'per_page' => 10,
				'prefix'   => true,
				'debug'    => false,
			)
		);

		$empty = array(
			'ids'     => array(),
			'total'   => 0,
			'took_ms' => 0.0,
			'mode'    => 'empty',
			'debug'   => array(),
		);

		$query = trim( (string) $query );
		if ( '' === $type || mb_strlen( $query ) < self::MIN_QUERY_LENGTH || ! PF_Search_Index::is_installed() ) {
			return $empty;
		}
		$query = mb_substr( $query, 0, 200 );

		try {
			$ranked = self::rank( $profile, $query, $type, (bool) $opts['prefix'] );
		} catch ( \Throwable $e ) {
			return $empty;
		}

		$per_page = max( 1, (int) $opts['per_page'] );
		$page     = max( 1, (int) $opts['page'] );
		$ids      = array_slice( array_keys( $ranked['scores'] ), ( $page - 1 ) * $per_page, $per_page );

		$debug = array();
		if ( $opts['debug'] ) {
			foreach ( $ids as $id ) {
				$debug[ $id ] = $ranked['scores'][ $id ];
			}
		}

		return array(
			'ids'     => array_map( 'intval', $ids ),
			'total'   => $ranked['total'],
			'took_ms' => round( ( microtime( true ) - $started ) * 1000, 1 ),
			'mode'    => $ranked['mode'],
			'debug'   => $debug,
		);
	}

	/**
	 * Полное ранжирование: post_id => ['score', 'matched', 'fields'] в
	 * порядке выдачи.
	 *
	 * @param array  $profile Профиль.
	 * @param string $query   Запрос.
	 * @param string $type    Тип записей.
	 * @param bool   $prefix  Дописывать последнее слово.
	 * @return array{scores:array,mode:string}
	 */
	private static function rank( array $profile, $query, $type, $prefix ) {
		global $wpdb;
		$t = PF_Search_Index::tables();

		$field_weights = self::get_field_weights( $profile, $type );
		$words         = self::parse_words( $query );
		$word_terms    = self::resolve_word_terms( $words, $prefix && ! preg_match( '/\s$/u', $query ) );

		$scores = array();
		$mode   = 'and';

		$all_term_ids = array();
		foreach ( $word_terms as $terms ) {
			$all_term_ids += $terms;
		}

		$type_id = PF_Search_Index::get_type_id( $type, false );

		if ( $all_term_ids && $field_weights && $type_id ) {
			$term_in  = implode( ',', array_map( 'intval', array_keys( $all_term_ids ) ) );
			$field_in = implode( ',', array_map( 'intval', array_keys( $field_weights ) ) );
			$rows     = self::fetch_rows(
				$wpdb->prepare(
					"SELECT term_id, post_id, field, tf, flen FROM {$t['postings']} WHERE term_id IN ({$term_in}) AND field IN ({$field_in}) AND type_id = %d LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$type_id,
					self::MAX_POSTING_ROWS
				)
			);

			$stats = PF_Search_Index::get_stats();
			$tstat = $stats['types'][ $type ] ?? array(
				'docs' => 0,
				'avg'  => array(),
			);

			$scores = self::score_postings( $word_terms, (array) $rows, $field_weights, $tstat['avg'], (int) $tstat['docs'] );

			$required = count( $word_terms );
			$all      = array_filter(
				$scores,
				static function ( $s ) use ( $required ) {
					return $s['matched'] >= $required;
				}
			);
			if ( $all || $required < 2 ) {
				$scores = $all;
			} else {
				$mode = 'partial';
			}
		}

		// Артикул: совпадение по компактной форме всей строки запроса.
		$compact = PF_Search_Tokenizer::compact( $query );
		if ( mb_strlen( $compact ) >= self::MIN_QUERY_LENGTH && preg_match( '/\d/', $compact ) ) {
			$sku_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT post_id, sku FROM {$t['docs']} WHERE post_type = %s AND sku LIKE %s LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$type,
					'%' . $wpdb->esc_like( $compact ) . '%'
				)
			);
			foreach ( (array) $sku_rows as $row ) {
				$bonus = 0;
				foreach ( explode( ' ', (string) $row->sku ) as $sku ) {
					if ( $sku === $compact ) {
						$bonus = 1000;
						break;
					}
					if ( 0 === strpos( $sku, $compact ) ) {
						$bonus = max( $bonus, 60 );
					}
				}
				if ( ! $bonus ) {
					continue;
				}
				$id = (int) $row->post_id;
				if ( ! isset( $scores[ $id ] ) ) {
					$scores[ $id ] = array(
						'score'   => 0.0,
						'matched' => count( $word_terms ),
						'fields'  => array(),
					);
				}
				$scores[ $id ]['score']   += $bonus;
				$scores[ $id ]['fields'][] = PF_Search_Index::FIELD_SKU;
				$scores[ $id ]['sku']      = true;
			}
		}

		if ( ! $scores ) {
			return array(
				'scores' => array(),
				'total'  => 0,
				'mode'   => 'empty',
			);
		}

		$total = count( $scores );
		self::sort_scores( $scores );
		if ( $total > self::MAX_CANDIDATES ) {
			$scores = array_slice( $scores, 0, self::MAX_CANDIDATES, true );
		}

		self::apply_doc_boosts( $scores, PF_Search_Tokenizer::normalize( $query ) );
		self::sort_scores( $scores );

		return array(
			'scores' => $scores,
			'total'  => $total,
			'mode'   => $mode,
		);
	}

	/**
	 * Строки постингов как массивы чисел. На частых словах их десятки
	 * тысяч, и штатный $wpdb->get_results() заметно тормозит (сначала
	 * объекты на каждую строку, потом конвертация) — при стандартном
	 * драйвере mysqli читаем напрямую. Любой другой драйвер БД (drop-in
	 * вроде HyperDB/LudicrousDB, SQLite) — обычный путь через $wpdb.
	 *
	 * @param string $sql Уже подготовленный запрос.
	 * @return array
	 */
	private static function fetch_rows( $sql ) {
		global $wpdb;

		if ( 'wpdb' === get_class( $wpdb ) && isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) {
			$result = mysqli_query( $wpdb->dbh, $sql ); // phpcs:ignore WordPress.DB.RestrictedFunctions
			if ( $result instanceof \mysqli_result ) {
				$rows = $result->fetch_all( MYSQLI_NUM );
				$result->free();
				return $rows;
			}
		}

		return (array) $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Слова запроса для поиска: нормализованные, стоп-слова отброшены
	 * (если запрос целиком из стоп-слов — остаются как есть).
	 *
	 * @param string $query Запрос.
	 * @return array Список ['word' => нормализованное слово, 'term' => стем].
	 */
	public static function parse_words( $query ) {
		$words = array_slice( PF_Search_Tokenizer::words( $query ), 0, self::MAX_WORDS );
		$out   = array();

		foreach ( $words as $word ) {
			$term = PF_Search_Tokenizer::term( $word );
			if ( null !== $term ) {
				$out[] = array(
					'word' => $word,
					'term' => $term,
					'alts' => self::fleeting_vowel_variants( $term ),
				);
			}
		}

		if ( ! $out ) {
			foreach ( $words as $word ) {
				$term  = PF_Search_Stemmer::stem( $word );
				$out[] = array(
					'word' => $word,
					'term' => $term,
					'alts' => self::fleeting_vowel_variants( $term ),
				);
			}
		}

		return $out;
	}

	/**
	 * Варианты стема с беглой гласной. Snowball Russian её не обрабатывает:
	 * «кроссовок» → «кроссовок», но «кроссовки» → «кроссовк» (так же
	 * носок/носки, подарок/подарки, кружек/кружки, конец/концы). Индекс
	 * хранит стемы как есть, а на запросе к слову добавляются оба варианта:
	 * без гласной перед конечными «к»/«ц» и с «о»/«е» перед ними.
	 *
	 * @param string $term Стем слова запроса.
	 * @return array
	 */
	public static function fleeting_vowel_variants( $term ) {
		if ( mb_strlen( $term ) < 4 || ! preg_match( '/^[а-я]+$/u', $term ) ) {
			return array();
		}
		if ( preg_match( '/^(.+[^аеиоуыэюяь])[ое]([кц])$/u', $term, $m ) ) {
			return array( $m[1] . $m[2] );
		}
		if ( preg_match( '/^(.+[^аеиоуыэюяь])([кц])$/u', $term, $m ) ) {
			return array( $m[1] . 'о' . $m[2], $m[1] . 'е' . $m[2] );
		}
		return array();
	}

	/**
	 * Для каждого слова — ID термов словаря с весом совпадения: полный
	 * стем — 1, дописывание последнего слова — PREFIX_WEIGHT.
	 *
	 * @param array $words  Результат parse_words().
	 * @param bool  $prefix Дописывать последнее слово.
	 * @return array Список [term_id => weight] по словам (слова без единого
	 *               терма в словаре остаются пустыми массивами — они
	 *               учитываются как обязательные и не дают полного совпадения).
	 */
	private static function resolve_word_terms( array $words, $prefix ) {
		global $wpdb;
		$t = PF_Search_Index::tables();

		$lookup = array_column( $words, 'term' );
		foreach ( $words as $w ) {
			$lookup = array_merge( $lookup, $w['alts'] ?? array() );
		}
		$exact = PF_Search_Index::lookup_terms( array_unique( $lookup ) );
		$out   = array();
		$last  = count( $words ) - 1;

		foreach ( $words as $i => $w ) {
			$terms = array();
			if ( isset( $exact[ $w['term'] ] ) ) {
				$terms[ $exact[ $w['term'] ] ] = 1.0;
			}
			foreach ( $w['alts'] ?? array() as $alt ) {
				if ( isset( $exact[ $alt ] ) && ! isset( $terms[ $exact[ $alt ] ] ) ) {
					$terms[ $exact[ $alt ] ] = self::FLEETING_VOWEL_WEIGHT;
				}
			}

			if ( $prefix && $i === $last && mb_strlen( $w['word'] ) >= 2 ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT id FROM {$t['terms']} WHERE term LIKE %s ORDER BY CHAR_LENGTH(term) ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$wpdb->esc_like( $w['word'] ) . '%',
						self::MAX_PREFIX_TERMS
					)
				);
				foreach ( (array) $rows as $row ) {
					if ( ! isset( $terms[ (int) $row->id ] ) ) {
						$terms[ (int) $row->id ] = self::PREFIX_WEIGHT;
					}
				}
			}

			$out[] = $terms;
		}

		return $out;
	}

	/**
	 * Веса полей профиля для типа записей: field_id => weight. Выключенные
	 * в профиле поля в выборку не попадают вовсе.
	 *
	 * @param array  $profile Профиль.
	 * @param string $type    Тип записей.
	 * @return array
	 */
	public static function get_field_weights( array $profile, $type ) {
		$map = array(
			'title'   => PF_Search_Index::FIELD_TITLE,
			'content' => PF_Search_Index::FIELD_CONTENT,
			'excerpt' => PF_Search_Index::FIELD_EXCERPT,
			'sku'     => PF_Search_Index::FIELD_SKU,
			'terms'   => PF_Search_Index::FIELD_TERMS,
		);

		$weights = array();
		foreach ( $map as $key => $field_id ) {
			if ( ! empty( $profile['fields'][ $key ] ) ) {
				$weights[ $field_id ] = max( 0.0, (float) ( $profile['weights'][ $key ] ?? 1 ) );
			}
		}

		$acf_names = (array) ( $profile['acf_fields'][ $type ] ?? array() );
		if ( $acf_names ) {
			$acf_weight = max( 0.0, (float) ( $profile['weights']['acf'] ?? 1 ) );
			foreach ( PF_Search_Index::get_existing_acf_field_ids( $acf_names ) as $field_id ) {
				$weights[ (int) $field_id ] = $acf_weight;
			}
		}

		return array_filter( $weights );
	}

	/**
	 * BM25F по строкам постингов. Чистая функция (без БД) — вход:
	 *
	 * @param array $word_terms    Список [term_id => weight] по словам запроса.
	 * @param array $rows          Строки [term_id, post_id, field, tf, flen].
	 * @param array $field_weights field_id => вес поля.
	 * @param array $avg_lengths   field_id => средняя длина поля по типу записей.
	 * @param int   $total_docs    Документов этого типа в индексе.
	 * @return array post_id => ['score' => float, 'matched' => число совпавших слов, 'fields' => [field_id...]]
	 */
	public static function score_postings( array $word_terms, array $rows, array $field_weights, array $avg_lengths, $total_docs ) {
		// term_id => [[word_index, weight], ...] — один терм может
		// относиться к нескольким словам запроса.
		$term_words = array();
		foreach ( $word_terms as $wi => $terms ) {
			foreach ( $terms as $term_id => $weight ) {
				$term_words[ (int) $term_id ][] = array( $wi, (float) $weight );
			}
		}

		$tfw    = array(); // post_id => word_index => взвешенная частота.
		$fields = array(); // post_id => field_id => true.
		$df     = array(); // word_index => post_id => true.

		// Нормализация по длине зависит только от поля и его длины —
		// коэффициенты поля считаем один раз, не на каждую строку.
		$field_k = array();
		foreach ( $field_weights as $field => $unused ) {
			$avg               = isset( $avg_lengths[ $field ] ) ? max( 1.0, (float) $avg_lengths[ $field ] ) : 10.0;
			$b                 = self::field_b( $field );
			$field_k[ $field ] = array( 1 - $b, $b / $avg );
		}

		foreach ( $rows as $row ) {
			$term_id = (int) $row[0];
			$post_id = (int) $row[1];
			$field   = (int) $row[2];
			if ( ! isset( $term_words[ $term_id ], $field_k[ $field ] ) ) {
				continue;
			}

			$norm = (int) $row[3] / ( $field_k[ $field ][0] + $field_k[ $field ][1] * (int) $row[4] );

			foreach ( $term_words[ $term_id ] as $pair ) {
				list( $wi, $weight ) = $pair;
				$tfw[ $post_id ][ $wi ] = ( $tfw[ $post_id ][ $wi ] ?? 0.0 ) + $field_weights[ $field ] * $weight * $norm;
				$df[ $wi ][ $post_id ]  = true;
			}
			$fields[ $post_id ][ $field ] = true;
		}

		$n   = max( (int) $total_docs, 1 );
		$idf = array();
		foreach ( $df as $wi => $posts ) {
			$d          = count( $posts );
			$n          = max( $n, $d );
			$idf[ $wi ] = log( 1 + ( $n - $d + 0.5 ) / ( $d + 0.5 ) );
		}

		$out = array();
		foreach ( $tfw as $post_id => $per_word ) {
			$score = 0.0;
			foreach ( $per_word as $wi => $x ) {
				$score += $idf[ $wi ] * ( $x * ( self::K1 + 1 ) ) / ( $x + self::K1 );
			}
			$out[ $post_id ] = array(
				'score'   => $score,
				'matched' => count( $per_word ),
				'fields'  => array_keys( $fields[ $post_id ] ),
			);
		}

		return $out;
	}

	/**
	 * Параметр b (нормализация по длине) по полю: короткие поля почти не
	 * штрафуем за длину, длинный текст — по стандарту BM25.
	 *
	 * @param int $field Field ID.
	 * @return float
	 */
	private static function field_b( $field ) {
		switch ( $field ) {
			case PF_Search_Index::FIELD_TITLE:
			case PF_Search_Index::FIELD_TERMS:
				return 0.5;
			case PF_Search_Index::FIELD_SKU:
				return 0.3;
			default:
				return 0.75;
		}
	}

	/**
	 * Бонусы по данным документа: точная фраза/начало в заголовке, наличие,
	 * популярность.
	 *
	 * @param array  $scores Результаты (по ссылке).
	 * @param string $phrase Нормализованная строка запроса.
	 */
	private static function apply_doc_boosts( array &$scores, $phrase ) {
		global $wpdb;
		$t = PF_Search_Index::tables();

		foreach ( array_chunk( array_keys( $scores ), 1000 ) as $chunk ) {
			$in   = implode( ',', array_map( 'intval', $chunk ) );
			$rows = $wpdb->get_results( "SELECT post_id, title, in_stock, popularity FROM {$t['docs']} WHERE post_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $rows as $row ) {
				$id = (int) $row->post_id;
				$scores[ $id ]['score'] *= self::doc_boost( (string) $row->title, $phrase, (int) $row->in_stock, (int) $row->popularity );
			}
		}
	}

	/**
	 * Множитель бонусов документа. Чистая функция.
	 *
	 * @param string $title      Нормализованный заголовок.
	 * @param string $phrase     Нормализованный запрос.
	 * @param int    $in_stock   1/0.
	 * @param int    $popularity Продажи.
	 * @return float
	 */
	public static function doc_boost( $title, $phrase, $in_stock, $popularity ) {
		$boost = 1.0;

		if ( '' !== $phrase && '' !== $title ) {
			if ( $title === $phrase ) {
				$boost *= 2.5;
			} elseif ( 0 === strpos( $title, $phrase ) ) {
				$boost *= 1.8;
			} elseif ( false !== strpos( ' ' . $title, ' ' . $phrase ) ) {
				$boost *= 1.4;
			}
		}

		if ( ! $in_stock ) {
			$boost *= 0.6;
		}

		if ( $popularity > 0 ) {
			$boost *= 1 + 0.1 * log10( 1 + $popularity );
		}

		return $boost;
	}

	/**
	 * Сортировка: больше совпавших слов → выше счёт → новее запись.
	 *
	 * @param array $scores Результаты (по ссылке).
	 */
	private static function sort_scores( array &$scores ) {
		$ids     = array_keys( $scores );
		$matched = array();
		$score   = array();
		foreach ( $scores as $s ) {
			$matched[] = $s['matched'];
			$score[]   = $s['score'];
		}
		array_multisort( $matched, SORT_DESC, SORT_NUMERIC, $score, SORT_DESC, SORT_NUMERIC, $ids, SORT_DESC, SORT_NUMERIC );

		$sorted = array();
		foreach ( $ids as $id ) {
			$sorted[ $id ] = $scores[ $id ];
		}
		$scores = $sorted;
	}
}
