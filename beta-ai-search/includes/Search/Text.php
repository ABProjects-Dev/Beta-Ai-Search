<?php
namespace BetaAI\Search\Search;

defined( 'ABSPATH' ) || exit;

final class Text {
	public static function normalize( string $text ): string {
		$text = strtr( $text, [ 'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', '‌' => ' ', '‏' => '', '‎' => '' ] );
		$text = strtr( $text, [ '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9' ] );
		$text = mb_strtolower( trim( $text ), 'UTF-8' );
		$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	public static function clean_query( string $text ): string {
		$text = sanitize_textarea_field( $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		if ( mb_strlen( $text ) > 600 || '' === trim( $text ) ) {
			throw new \InvalidArgumentException( 'query_length' );
		}
		return trim( $text );
	}

	/** Explicit numeric constraints are parsed deterministically, not left to an LLM guess. */
	public static function price_bounds( string $normalized ): ?array {
		$number = '(?:\d{1,3}(?:[,\x{066C}\x{060C}]\d{3})+(?:[.\x{066B}]\d+)?|\d+(?:[.,\x{066B}]\d+)?)';
		$unit = '(million|میلیون|ميليون|billion|میلیارد|ميليارد|هزار|thousand|k|m)?';
		$currency = '(تومان|تومن|toman(?:s)?|ریال|rial(?:s)?|IRT|IRR|USD|EUR|دلار|یورو|يورو|\$|€)?';
		$amount = '(' . $number . ')\s*' . $unit . '\s*' . $currency;
		$between = '/(?:بین|بين|between|from)\s+' . $amount . '\s*(?:و|تا|and|to|-)\s*' . $amount . '/iu';
		if ( preg_match( $between, $normalized, $m ) ) {
			$first_currency = self::currency( $m[3] ?? '' );
			$second_currency = self::currency( $m[6] ?? '' );
			if ( 'store' !== $first_currency && 'store' !== $second_currency && $first_currency !== $second_currency ) {
				return null;
			}
			$unit = ( $m[2] ?? '' ) ?: ( $m[5] ?? '' );
			return [ 'min' => self::amount( $m[1], $unit ), 'max' => self::amount( $m[4], $unit ), 'currency' => 'store' !== $second_currency ? $second_currency : $first_currency ];
		}
		$max_pattern = '/(?:تا|زیر|زير|کمتر از|حداکثر|up to|under|below|less than|max(?:imum)?|budget)\s*' . $amount . '/iu';
		$min_pattern = '/(?:بیشتر از|بيشتر از|حداقل|از|over|above|more than|at least|min(?:imum)?)\s*' . $amount . '/iu';
		$result = [ 'min' => null, 'max' => null, 'currency' => 'store' ];
		$currencies = [];
		foreach ( [ 'max' => $max_pattern, 'min' => $min_pattern ] as $key => $pattern ) {
			if ( preg_match( $pattern, $normalized, $m ) ) {
				$result[ $key ] = self::amount( $m[1], $m[2] ?? '' );
				$currency = self::currency( $m[3] ?? '' );
				$result['currency'] = $currency;
				if ( 'store' !== $currency ) { $currencies[] = $currency; }
			}
		}
		if ( count( array_unique( $currencies ) ) > 1 ) { return null; }
		if ( 'store' === $result['currency'] && $currencies ) { $result['currency'] = $currencies[0]; }
		return null !== $result['min'] || null !== $result['max'] ? $result : null;
	}

	private static function amount( string $number, string $unit ): float {
		$grouped = (bool) preg_match( '/^\d{1,3}([,\x{066C}\x{060C}]\d{3})+/u', $number );
		$number = $grouped ? str_replace( [ ',', '،', '٬' ], '', $number ) : str_replace( [ ',', '٫' ], '.', $number );
		$number = (float) str_replace( '٫', '.', $number );
		$multiplier = match ( mb_strtolower( $unit ) ) {
			'million', 'میلیون', 'ميليون', 'm' => 1000000,
			'billion', 'میلیارد', 'ميليارد' => 1000000000,
			'هزار', 'thousand', 'k' => 1000,
			default => 1,
		};
		return $number * $multiplier;
	}
	private static function currency( string $unit ): string {
		return match ( mb_strtolower( $unit ) ) {
			'تومان', 'تومن', 'toman', 'tomans', 'irt' => 'IRT',
			'ریال', 'rial', 'rials', 'irr' => 'IRR',
			'usd', 'دلار', '$' => 'USD',
			'eur', 'یورو', 'يورو', '€' => 'EUR',
			default => 'store',
		};
	}
}
