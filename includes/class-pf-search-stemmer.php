<?php
/**
 * Стемминг для модуля поиска: Snowball Russian и Porter2 (Snowball English).
 *
 * Реализации написаны по официальным описаниям алгоритмов на
 * snowballstem.org, без внешних зависимостей. На вход ожидается уже
 * нормализованное слово (нижний регистр, «ё» заменена на «е») одного
 * алфавита — выбор алгоритма делает PF_Search_Tokenizer.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Stemmer
 */
class PF_Search_Stemmer {

	/**
	 * Кэш результатов за запрос (одни и те же слова встречаются в тексте
	 * многократно — стемминг заметно дешевле с кэшем).
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Стемминг одного слова — алгоритм выбирается по алфавиту.
	 *
	 * @param string $word Нормализованное слово.
	 * @return string
	 */
	public static function stem( $word ) {
		if ( isset( self::$cache[ $word ] ) ) {
			return self::$cache[ $word ];
		}

		if ( preg_match( '/^[а-я]+$/u', $word ) ) {
			$stem = self::russian( $word );
		} elseif ( preg_match( '/^[a-z\']+$/', $word ) ) {
			$stem = self::english( $word );
		} else {
			$stem = $word;
		}

		if ( count( self::$cache ) > 20000 ) {
			self::$cache = array();
		}
		self::$cache[ $word ] = $stem;

		return $stem;
	}

	/* ---------------------------------------------------------------------
	 * Snowball Russian
	 * ------------------------------------------------------------------ */

	const RU_VOWELS = 'аеиоуыэюя';

	const RU_PERFECTIVE_GERUND_1 = array( 'в', 'вши', 'вшись' );
	const RU_PERFECTIVE_GERUND_2 = array( 'ив', 'ивши', 'ившись', 'ыв', 'ывши', 'ывшись' );
	const RU_ADJECTIVE           = array( 'ее', 'ие', 'ые', 'ое', 'ими', 'ыми', 'ей', 'ий', 'ый', 'ой', 'ем', 'им', 'ым', 'ом', 'его', 'ого', 'ему', 'ому', 'их', 'ых', 'ую', 'юю', 'ая', 'яя', 'ою', 'ею' );
	const RU_PARTICIPLE_1        = array( 'ем', 'нн', 'вш', 'ющ', 'щ' );
	const RU_PARTICIPLE_2        = array( 'ивш', 'ывш', 'ующ' );
	const RU_REFLEXIVE           = array( 'ся', 'сь' );
	const RU_VERB_1              = array( 'ла', 'на', 'ете', 'йте', 'ли', 'й', 'л', 'ем', 'н', 'ло', 'но', 'ет', 'ют', 'ны', 'ть', 'ешь', 'нно' );
	const RU_VERB_2              = array( 'ила', 'ыла', 'ена', 'ейте', 'уйте', 'ите', 'или', 'ыли', 'ей', 'уй', 'ил', 'ыл', 'им', 'ым', 'ен', 'ило', 'ыло', 'ено', 'ят', 'ует', 'уют', 'ит', 'ыт', 'ены', 'ить', 'ыть', 'ишь', 'ую', 'ю' );
	const RU_NOUN                = array( 'а', 'ев', 'ов', 'ие', 'ье', 'е', 'иями', 'ями', 'ами', 'еи', 'ии', 'и', 'ией', 'ей', 'ой', 'ий', 'й', 'иям', 'ям', 'ием', 'ем', 'ам', 'ом', 'о', 'у', 'ах', 'иях', 'ях', 'ы', 'ь', 'ию', 'ью', 'ю', 'ия', 'ья', 'я' );
	const RU_SUPERLATIVE         = array( 'ейш', 'ейше' );
	const RU_DERIVATIONAL        = array( 'ост', 'ость' );

	/**
	 * Snowball Russian.
	 *
	 * @param string $word Слово.
	 * @return string
	 */
	public static function russian( $word ) {
		$chars = self::chars( $word );
		$len   = count( $chars );

		// RV — после первой гласной; R2 — стандартное определение R1/R2.
		$rv = $len;
		for ( $i = 0; $i < $len; $i++ ) {
			if ( self::ru_vowel( $chars[ $i ] ) ) {
				$rv = $i + 1;
				break;
			}
		}
		$r1 = self::region_after( $chars, 0, 'ru' );
		$r2 = self::region_after( $chars, $r1, 'ru' );

		if ( $rv >= $len ) {
			return $word;
		}

		// Шаг 1.
		$gerund = self::ru_grouped_suffix( $chars, $rv, self::RU_PERFECTIVE_GERUND_1, self::RU_PERFECTIVE_GERUND_2 );
		if ( $gerund > 0 ) {
			$chars = array_slice( $chars, 0, count( $chars ) - $gerund );
		} else {
			$refl = self::longest_suffix( $chars, $rv, self::RU_REFLEXIVE );
			if ( $refl > 0 ) {
				$chars = array_slice( $chars, 0, count( $chars ) - $refl );
			}

			$adj = self::longest_suffix( $chars, $rv, self::RU_ADJECTIVE );
			if ( $adj > 0 ) {
				$chars = array_slice( $chars, 0, count( $chars ) - $adj );
				$part  = self::ru_grouped_suffix( $chars, $rv, self::RU_PARTICIPLE_1, self::RU_PARTICIPLE_2 );
				if ( $part > 0 ) {
					$chars = array_slice( $chars, 0, count( $chars ) - $part );
				}
			} else {
				$verb = self::ru_grouped_suffix( $chars, $rv, self::RU_VERB_1, self::RU_VERB_2 );
				if ( $verb > 0 ) {
					$chars = array_slice( $chars, 0, count( $chars ) - $verb );
				} else {
					$noun = self::longest_suffix( $chars, $rv, self::RU_NOUN );
					if ( $noun > 0 ) {
						$chars = array_slice( $chars, 0, count( $chars ) - $noun );
					}
				}
			}
		}

		// Шаг 2.
		if ( self::longest_suffix( $chars, $rv, array( 'и' ) ) > 0 ) {
			array_pop( $chars );
		}

		// Шаг 3.
		$deriv = self::longest_suffix( $chars, $r2, self::RU_DERIVATIONAL );
		if ( $deriv > 0 ) {
			$chars = array_slice( $chars, 0, count( $chars ) - $deriv );
		}

		// Шаг 4.
		$sup = self::longest_suffix( $chars, $rv, self::RU_SUPERLATIVE );
		if ( $sup > 0 ) {
			$chars = array_slice( $chars, 0, count( $chars ) - $sup );
		}
		if ( self::longest_suffix( $chars, $rv, array( 'нн' ) ) > 0 ) {
			array_pop( $chars );
		} elseif ( 0 === $sup && self::longest_suffix( $chars, $rv, array( 'ь' ) ) > 0 ) {
			array_pop( $chars );
		}

		return implode( '', $chars );
	}

	/**
	 * Самое длинное окончание из двух групп Snowball: окончания первой
	 * группы допустимы только после «а»/«я» (сама буква остаётся),
	 * второй — без условия. Как в Snowball `among`: условие проверяется
	 * только у самого длинного совпадения, на более короткое не откатываемся.
	 *
	 * @param array $chars  Символы слова.
	 * @param int   $limit  Начало региона (RV).
	 * @param array $group1 Окончания с условием.
	 * @param array $group2 Окончания без условия.
	 * @return int Длина удаляемого окончания (0 — не найдено).
	 */
	private static function ru_grouped_suffix( array $chars, $limit, array $group1, array $group2 ) {
		$len1 = self::longest_suffix( $chars, $limit, $group1 );
		$len2 = self::longest_suffix( $chars, $limit, $group2 );

		if ( $len2 >= $len1 ) {
			return $len2;
		}

		$before = count( $chars ) - $len1 - 1;
		if ( $before >= $limit && ( 'а' === $chars[ $before ] || 'я' === $chars[ $before ] ) ) {
			return $len1;
		}

		return 0;
	}

	/**
	 * Гласная русского алфавита.
	 *
	 * @param string $c Символ.
	 * @return bool
	 */
	private static function ru_vowel( $c ) {
		return false !== mb_strpos( self::RU_VOWELS, $c );
	}

	/* ---------------------------------------------------------------------
	 * Porter2 (Snowball English)
	 * ------------------------------------------------------------------ */

	const EN_EXCEPTIONS_1 = array(
		'skis'   => 'ski',
		'skies'  => 'sky',
		'dying'  => 'die',
		'lying'  => 'lie',
		'tying'  => 'tie',
		'idly'   => 'idl',
		'gently' => 'gentl',
		'ugly'   => 'ugli',
		'early'  => 'earli',
		'only'   => 'onli',
		'singly' => 'singl',
		'sky'    => 'sky',
		'news'   => 'news',
		'howe'   => 'howe',
		'atlas'  => 'atlas',
		'cosmos' => 'cosmos',
		'bias'   => 'bias',
		'andes'  => 'andes',
	);

	const EN_EXCEPTIONS_2 = array( 'inning', 'outing', 'canning', 'herring', 'earring', 'proceed', 'exceed', 'succeed' );

	const EN_STEP2 = array(
		'ization' => 'ize',
		'ational' => 'ate',
		'fulness' => 'ful',
		'ousness' => 'ous',
		'iveness' => 'ive',
		'tional'  => 'tion',
		'biliti'  => 'ble',
		'lessli'  => 'less',
		'entli'   => 'ent',
		'ation'   => 'ate',
		'alism'   => 'al',
		'aliti'   => 'al',
		'ousli'   => 'ous',
		'iviti'   => 'ive',
		'fulli'   => 'ful',
		'enci'    => 'ence',
		'anci'    => 'ance',
		'abli'    => 'able',
		'izer'    => 'ize',
		'ator'    => 'ate',
		'alli'    => 'al',
		'bli'     => 'ble',
		'ogi'     => 'og',
		'li'      => '',
	);

	const EN_STEP3 = array(
		'ational' => 'ate',
		'tional'  => 'tion',
		'alize'   => 'al',
		'icate'   => 'ic',
		'iciti'   => 'ic',
		'ative'   => '',
		'ical'    => 'ic',
		'ness'    => '',
		'ful'     => '',
	);

	const EN_STEP4 = array( 'ement', 'ance', 'ence', 'able', 'ible', 'ment', 'ant', 'ent', 'ism', 'ate', 'iti', 'ous', 'ive', 'ize', 'ion', 'al', 'er', 'ic' );

	/**
	 * Porter2.
	 *
	 * @param string $word Слово (латиница, нижний регистр).
	 * @return string
	 */
	public static function english( $word ) {
		if ( strlen( $word ) <= 2 ) {
			return $word;
		}

		$word = ltrim( $word, "'" );

		if ( isset( self::EN_EXCEPTIONS_1[ $word ] ) ) {
			return self::EN_EXCEPTIONS_1[ $word ];
		}

		// «y» в начале слова или после гласной — согласная (Y).
		if ( 'y' === $word[0] ) {
			$word[0] = 'Y';
		}
		$n = strlen( $word );
		for ( $i = 1; $i < $n; $i++ ) {
			if ( 'y' === $word[ $i ] && self::en_vowel( $word[ $i - 1 ] ) ) {
				$word[ $i ] = 'Y';
			}
		}

		if ( preg_match( '/^(gener|commun|arsen)/', $word, $m ) ) {
			$r1 = strlen( $m[1] );
		} else {
			$r1 = self::en_region_after( $word, 0 );
		}
		$r2 = self::en_region_after( $word, $r1 );

		// Шаг 0.
		foreach ( array( "'s'", "'s", "'" ) as $suffix ) {
			if ( self::ends( $word, $suffix ) ) {
				$word = substr( $word, 0, -strlen( $suffix ) );
				break;
			}
		}

		// Шаг 1a.
		if ( self::ends( $word, 'sses' ) ) {
			$word = substr( $word, 0, -2 );
		} elseif ( self::ends( $word, 'ied' ) || self::ends( $word, 'ies' ) ) {
			$word = strlen( $word ) > 4 ? substr( $word, 0, -2 ) : substr( $word, 0, -1 );
		} elseif ( self::ends( $word, 'us' ) || self::ends( $word, 'ss' ) ) {
			// Ничего.
			$word = $word;
		} elseif ( self::ends( $word, 's' ) ) {
			$stem = substr( $word, 0, -1 );
			// Удаляем, если в части слова до «s» есть гласная не прямо перед «s».
			if ( preg_match( '/[aeiouy]/', substr( $stem, 0, -1 ) ) ) {
				$word = $stem;
			}
		}

		if ( in_array( $word, self::EN_EXCEPTIONS_2, true ) ) {
			return $word;
		}

		// Шаг 1b.
		$step1b_done = false;
		foreach ( array( 'eedly', 'eed' ) as $suffix ) {
			if ( self::ends( $word, $suffix ) ) {
				if ( strlen( $word ) - strlen( $suffix ) >= $r1 ) {
					$word = substr( $word, 0, -strlen( $suffix ) ) . 'ee';
				}
				$step1b_done = true;
				break;
			}
		}
		if ( ! $step1b_done ) {
			foreach ( array( 'ingly', 'edly', 'ing', 'ed' ) as $suffix ) {
				if ( ! self::ends( $word, $suffix ) ) {
					continue;
				}
				$stem = substr( $word, 0, -strlen( $suffix ) );
				if ( preg_match( '/[aeiouy]/', $stem ) ) {
					$word = $stem;
					if ( self::ends( $word, 'at' ) || self::ends( $word, 'bl' ) || self::ends( $word, 'iz' ) ) {
						$word .= 'e';
					} elseif ( preg_match( '/(bb|dd|ff|gg|mm|nn|pp|rr|tt)$/', $word ) ) {
						$word = substr( $word, 0, -1 );
					} elseif ( self::en_is_short( $word, $r1 ) ) {
						$word .= 'e';
					}
				}
				break;
			}
		}

		// Шаг 1c.
		$n = strlen( $word );
		if ( $n > 2 && ( 'y' === $word[ $n - 1 ] || 'Y' === $word[ $n - 1 ] ) && ! self::en_vowel( $word[ $n - 2 ] ) ) {
			$word[ $n - 1 ] = 'i';
		}

		// Шаг 2.
		foreach ( self::EN_STEP2 as $suffix => $replacement ) {
			if ( ! self::ends( $word, $suffix ) ) {
				continue;
			}
			$stem_len = strlen( $word ) - strlen( $suffix );
			if ( $stem_len >= $r1 ) {
				if ( 'ogi' === $suffix ) {
					if ( $stem_len > 0 && 'l' === $word[ $stem_len - 1 ] ) {
						$word = substr( $word, 0, $stem_len ) . $replacement;
					}
				} elseif ( 'li' === $suffix ) {
					if ( $stem_len > 0 && false !== strpos( 'cdeghkmnrt', $word[ $stem_len - 1 ] ) ) {
						$word = substr( $word, 0, $stem_len );
					}
				} else {
					$word = substr( $word, 0, $stem_len ) . $replacement;
				}
			}
			break;
		}

		// Шаг 3.
		foreach ( self::EN_STEP3 as $suffix => $replacement ) {
			if ( ! self::ends( $word, $suffix ) ) {
				continue;
			}
			$stem_len = strlen( $word ) - strlen( $suffix );
			if ( $stem_len >= $r1 ) {
				if ( 'ative' === $suffix ) {
					if ( $stem_len >= $r2 ) {
						$word = substr( $word, 0, $stem_len );
					}
				} else {
					$word = substr( $word, 0, $stem_len ) . $replacement;
				}
			}
			break;
		}

		// Шаг 4.
		foreach ( self::EN_STEP4 as $suffix ) {
			if ( ! self::ends( $word, $suffix ) ) {
				continue;
			}
			$stem_len = strlen( $word ) - strlen( $suffix );
			if ( $stem_len >= $r2 ) {
				if ( 'ion' === $suffix ) {
					if ( $stem_len > 0 && ( 's' === $word[ $stem_len - 1 ] || 't' === $word[ $stem_len - 1 ] ) ) {
						$word = substr( $word, 0, $stem_len );
					}
				} else {
					$word = substr( $word, 0, $stem_len );
				}
			}
			break;
		}

		// Шаг 5.
		$n = strlen( $word );
		if ( $n > 0 && 'e' === $word[ $n - 1 ] ) {
			$stem = substr( $word, 0, -1 );
			if ( $n - 1 >= $r2 || ( $n - 1 >= $r1 && ! self::en_ends_short_syllable( $stem ) ) ) {
				$word = $stem;
			}
		} elseif ( $n > 1 && 'l' === $word[ $n - 1 ] && 'l' === $word[ $n - 2 ] && $n - 1 >= $r2 ) {
			$word = substr( $word, 0, -1 );
		}

		return str_replace( 'Y', 'y', $word );
	}

	/**
	 * Гласная Porter2.
	 *
	 * @param string $c Символ.
	 * @return bool
	 */
	private static function en_vowel( $c ) {
		return false !== strpos( 'aeiouy', $c );
	}

	/**
	 * Начало региона R1/R2 для латиницы: позиция после первой согласной,
	 * следующей за гласной, начиная с $from.
	 *
	 * @param string $word Слово.
	 * @param int    $from Откуда искать.
	 * @return int
	 */
	private static function en_region_after( $word, $from ) {
		$n = strlen( $word );
		for ( $i = $from + 1; $i < $n; $i++ ) {
			if ( ! self::en_vowel( $word[ $i ] ) && self::en_vowel( $word[ $i - 1 ] ) ) {
				return $i + 1;
			}
		}
		return $n;
	}

	/**
	 * Слово заканчивается «коротким слогом» Porter2.
	 *
	 * @param string $word Слово.
	 * @return bool
	 */
	private static function en_ends_short_syllable( $word ) {
		$n = strlen( $word );
		if ( 2 === $n ) {
			return self::en_vowel( $word[0] ) && ! self::en_vowel( $word[1] );
		}
		if ( $n < 3 ) {
			return false;
		}
		return ! self::en_vowel( $word[ $n - 3 ] )
			&& self::en_vowel( $word[ $n - 2 ] )
			&& ! self::en_vowel( $word[ $n - 1 ] )
			&& false === strpos( 'wxY', $word[ $n - 1 ] );
	}

	/**
	 * «Короткое слово» Porter2: заканчивается коротким слогом и R1 пуст.
	 *
	 * @param string $word Слово.
	 * @param int    $r1   Начало R1 (посчитано для исходного слова).
	 * @return bool
	 */
	private static function en_is_short( $word, $r1 ) {
		return $r1 >= strlen( $word ) && self::en_ends_short_syllable( $word );
	}

	/* ---------------------------------------------------------------------
	 * Общее
	 * ------------------------------------------------------------------ */

	/**
	 * Разбить UTF-8 строку на символы.
	 *
	 * @param string $word Слово.
	 * @return array
	 */
	private static function chars( $word ) {
		return preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Начало региона R1/R2 для кириллицы (см. en_region_after()).
	 *
	 * @param array  $chars Символы слова.
	 * @param int    $from  Откуда искать.
	 * @param string $lang  Язык (зарезервировано).
	 * @return int
	 */
	private static function region_after( array $chars, $from, $lang ) {
		$n = count( $chars );
		for ( $i = $from + 1; $i < $n; $i++ ) {
			if ( ! self::ru_vowel( $chars[ $i ] ) && self::ru_vowel( $chars[ $i - 1 ] ) ) {
				return $i + 1;
			}
		}
		return $n;
	}

	/**
	 * Длина самого длинного окончания из списка, целиком лежащего в регионе.
	 *
	 * @param array $chars    Символы слова.
	 * @param int   $limit    Начало региона.
	 * @param array $suffixes Окончания.
	 * @return int
	 */
	private static function longest_suffix( array $chars, $limit, array $suffixes ) {
		$n    = count( $chars );
		$best = 0;
		foreach ( $suffixes as $suffix ) {
			$slen = mb_strlen( $suffix );
			if ( $slen <= $best || $n - $slen < $limit ) {
				continue;
			}
			if ( implode( '', array_slice( $chars, $n - $slen ) ) === $suffix ) {
				$best = $slen;
			}
		}
		return $best;
	}

	/**
	 * Строка заканчивается подстрокой (байтово).
	 *
	 * @param string $word   Слово.
	 * @param string $suffix Окончание.
	 * @return bool
	 */
	private static function ends( $word, $suffix ) {
		$len = strlen( $suffix );
		return strlen( $word ) >= $len && substr( $word, -$len ) === $suffix;
	}
}
