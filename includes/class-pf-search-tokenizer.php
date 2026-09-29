<?php
/**
 * Токенизация текста для модуля поиска: нормализация, разбиение на слова,
 * стоп-слова, стемминг. Одна и та же функция используется и при
 * индексации, и при разборе запроса — иначе слова в индексе и в запросе
 * приводились бы к разным формам.
 *
 * @package PF_Filter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class PF_Search_Tokenizer
 */
class PF_Search_Tokenizer {

	/**
	 * Максимальная длина терма в словаре (столбец VARCHAR(64)).
	 */
	const MAX_TERM_LENGTH = 64;

	/**
	 * Стоп-слова (уже в нормализованной форме, до стемминга). Короткий
	 * список самых частых служебных слов — длинные списки вредят поиску
	 * по названиям товаров («без сахара», «для детей»).
	 */
	const STOP_WORDS = array(
		'и', 'в', 'во', 'для', 'не', 'что', 'он', 'на', 'я', 'с', 'со', 'как', 'а', 'то', 'все', 'она', 'так', 'его', 'но', 'да', 'ты', 'к', 'у', 'же', 'вы', 'за', 'бы', 'по', 'только', 'ее', 'мне', 'было', 'вот', 'от', 'меня', 'еще', 'нет', 'о', 'из', 'ему', 'ли', 'если', 'уже', 'или', 'ни', 'быть', 'был', 'него', 'до', 'вас', 'нибудь', 'уж', 'вам', 'ведь', 'там', 'потом', 'себя', 'ничего', 'ей', 'может', 'они', 'тут', 'где', 'есть', 'надо', 'ней', 'мы', 'тебя', 'их', 'чем', 'была', 'сам', 'чтоб', 'чего', 'раз', 'тоже', 'себе', 'под', 'будет', 'ж', 'тогда', 'кто', 'этот', 'того', 'потому', 'этого', 'какой', 'совсем', 'ним', 'здесь', 'этом', 'один', 'почти', 'мой', 'тем', 'чтобы', 'нее', 'были', 'куда', 'зачем', 'всех', 'можно', 'при', 'об', 'это', 'эти', 'эта',
		'a', 'an', 'the', 'and', 'or', 'of', 'to', 'in', 'on', 'at', 'by', 'is', 'are', 'was', 'were', 'be', 'it', 'its', 'as', 'that', 'this', 'these', 'those', 'from', 'but', 'not', 'into', 'than', 'then', 'so', 'if', 'do', 'does',
	);

	/**
	 * Нормализовать произвольный текст: без HTML, нижний регистр, «ё» → «е»,
	 * всё, что не буква/цифра, — в пробел.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}

		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = str_replace( 'ё', 'е', $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Нормализованные слова текста (без стемминга, стоп-слова на месте).
	 *
	 * @param string $text Текст.
	 * @return array
	 */
	public static function words( $text ) {
		$normalized = self::normalize( $text );
		return '' === $normalized ? array() : explode( ' ', $normalized );
	}

	/**
	 * Термы текста для индекса: слово → стем, стоп-слова и одиночные буквы
	 * отброшены (одиночные цифры оставлены — «iphone 5»).
	 *
	 * @param string $text Текст.
	 * @return array Список термов с повторами (для подсчёта tf).
	 */
	public static function terms( $text ) {
		$out = array();
		foreach ( self::words( $text ) as $word ) {
			$term = self::term( $word );
			if ( null !== $term ) {
				$out[] = $term;
			}
		}
		return $out;
	}

	/**
	 * Терм одного нормализованного слова, либо null, если слово не
	 * индексируется.
	 *
	 * @param string $word Нормализованное слово.
	 * @return string|null
	 */
	public static function term( $word ) {
		if ( '' === $word || in_array( $word, self::STOP_WORDS, true ) ) {
			return null;
		}
		if ( 1 === mb_strlen( $word ) && ! ctype_digit( $word ) ) {
			return null;
		}

		$term = PF_Search_Stemmer::stem( $word );

		if ( mb_strlen( $term ) > self::MAX_TERM_LENGTH ) {
			$term = mb_substr( $term, 0, self::MAX_TERM_LENGTH );
		}

		return $term;
	}

	/**
	 * Компактная форма артикула/кода: только буквы и цифры, нижний регистр
	 * («AB-123 x» → «ab123x») — чтобы «AB-123», «ab 123» и «AB123» считались
	 * одним и тем же.
	 *
	 * @param string $text Артикул или запрос.
	 * @return string
	 */
	public static function compact( $text ) {
		return str_replace( ' ', '', self::normalize( $text ) );
	}
}
