<?php
namespace BetaAI\Search\Search;

use BetaAI\Search\Core\Settings;

defined( 'ABSPATH' ) || exit;

/** Deterministic, testable acceptance gates. No keyword-search fallback. */
final class Matcher {
	public static function thresholds( array $settings ): array {
		$g = max( 0, min( 100, (int) $settings['global_flex'] ) ) / 100;
		return [ 'semantic' => 0.76 - 0.26 * $g, 'overall' => 0.70 - 0.15 * $g ];
	}

	public static function score( array $intent, array $facts, float $semantic, array $settings ): array {
		$thresholds = self::thresholds( $settings );
		$semantic = max( 0, min( 1, $semantic ) );
		$scores = [];
		$accepted = $semantic >= $thresholds['semantic'];
		$reasons = [];
		if ( ! $accepted ) {
			$reasons[] = 'semantic_threshold';
		}
		foreach ( $intent['attributes'] as $attribute ) {
			$key = $attribute['key'];
			$flex = max( 0, min( 100, (int) ( $settings['attribute_flex'][ $key ] ?? $settings['global_flex'] ) ) ) / 100;
			$product_values = $facts['attributes'][ $key ] ?? [];
			if ( 'other' === $key ) {
				foreach ( $facts['attributes'] as $values ) {
					$product_values = array_merge( $product_values, $values );
				}
			}
			$value_score = self::attribute_score( $attribute['values'], $product_values, $key, $flex );
			$scores[] = $value_score;
			if ( 'required' === $attribute['importance'] && $value_score < 1 - 0.5 * $flex - 1e-9 ) {
				$accepted = false;
				$reasons[] = 'attribute_' . $key;
			}
		}
		$price_score = 1.0;
		$price = $intent['price'];
		if ( null !== $price['min'] || null !== $price['max'] ) {
			$flex = max( 0, min( 100, (int) $settings['attribute_flex']['price'] ) ) / 100;
			$product_price = $facts['price'] ?? null;
			if ( null === $product_price || '' === $product_price ) {
				$accepted = false;
				$reasons[] = 'price_unknown';
				$price_score = 0.0;
			} else {
				foreach ( [ 'min', 'max' ] as $bound ) {
					if ( null === $price[ $bound ] ) {
						continue;
					}
					$limit = (float) $price[ $bound ];
					$distance = 'max' === $bound ? max( 0, (float) $product_price - $limit ) : max( 0, $limit - (float) $product_price );
					// Accepted overflow is 0.25 × flex × bound (5% at flex=20%).
					// The score reaches the required gate exactly at that boundary.
					$zone = max( 0.01, abs( $limit ) * 0.25 * $flex );
					$score = $flex > 0 ? max( 0, 1 - ( 0.5 * $flex ) * ( $distance / $zone ) ) : ( $distance <= 0.00001 ? 1.0 : 0.0 );
					$price_score = min( $price_score, $score );
					if ( $score < 1 - 0.5 * $flex - 1e-9 ) {
						$accepted = false;
						$reasons[] = 'price_' . $bound;
					}
				}
			}
			$scores[] = $price_score;
		}
		$attribute_mean = $scores ? array_sum( $scores ) / count( $scores ) : $semantic;
		$overall = 0.65 * $semantic + 0.35 * $attribute_mean;
		if ( $overall < $thresholds['overall'] ) {
			$accepted = false;
			$reasons[] = 'overall_threshold';
		}
		return [ 'accepted' => $accepted, 'score' => round( $overall, 8 ), 'semantic' => $semantic, 'attributes' => $attribute_mean, 'reasons' => array_values( array_unique( $reasons ) ) ];
	}

	public static function attribute_score( array $wanted, array $available, string $facet, float $flex ): float {
		$best = 0.0;
		foreach ( $wanted as $a ) {
			foreach ( $available as $b ) {
				$score = self::value_similarity( (string) $a, (string) $b, $facet, $flex );
				$best = max( $best, $score );
				if ( 1.0 === $best ) {
					return 1.0;
				}
			}
		}
		return $best;
	}

	private static function value_similarity( string $a, string $b, string $facet, float $flex ): float {
		$a = self::canonical_text( $a );
		$b = self::canonical_text( $b );
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}
		if ( $a === $b ) {
			return 1.0;
		}
		$ca = self::canonical_color( $a );
		$cb = self::canonical_color( $b );
		if ( 'color' === $facet && $ca && $cb ) {
			if ( $ca === $cb ) {
				return 1.0;
			}
			$near = [ 'black:gray', 'gray:silver', 'blue:navy', 'white:cream', 'red:burgundy', 'green:olive', 'brown:tan', 'purple:violet' ];
			$pair = implode( ':', [ $ca, $cb ] );
			$reverse = implode( ':', [ $cb, $ca ] );
			return $flex > 0 && ( in_array( $pair, $near, true ) || in_array( $reverse, $near, true ) ) ? 0.9 : 0.0;
		}
		$aliases = self::aliases();
		foreach ( $aliases as $group ) {
			$normalized = array_map( [ self::class, 'canonical_text' ], $group );
			if ( in_array( $a, $normalized, true ) && in_array( $b, $normalized, true ) ) {
				return 1.0;
			}
		}
		if ( 'brand' === $facet || 'size' === $facet ) {
			return 0.0;
		}
		// Containment is a soft match only; explicit requirements still need sufficient flexibility.
		if ( mb_strlen( $a ) >= 3 && mb_strlen( $b ) >= 3 && ( str_contains( $a, $b ) || str_contains( $b, $a ) ) ) {
			return $flex > 0 ? 0.9 : 0.0;
		}
		return 0.0;
	}

	private static function canonical_text( string $value ): string {
		$value = Text::normalize( $value );
		$value = preg_replace( '/[\x{200c}\-_.\/\\()\[\]،,:;]+/u', ' ', $value );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}
	private static function canonical_color( string $value ): string {
		$groups = [
			'black' => [ 'black', 'مشکی', 'سیاه', 'مشکي' ],
			'white' => [ 'white', 'سفید', 'سفيد' ],
			'red' => [ 'red', 'قرمز', 'سرخ' ],
			'blue' => [ 'blue', 'ابی', 'آبی', 'ازرق' ],
			'navy' => [ 'navy', 'سرمه ای', 'سرمه اي', 'navy blue', 'ابی سرمه ای' ],
			'green' => [ 'green', 'سبز' ],
			'yellow' => [ 'yellow', 'زرد' ],
			'gray' => [ 'gray', 'grey', 'خاکستری', 'خاکستري', 'طوسی', 'طوسي' ],
			'brown' => [ 'brown', 'قهوه ای', 'قهوه اي' ],
			'purple' => [ 'purple', 'بنفش' ],
			'violet' => [ 'violet', 'یاسی', 'ياسي' ],
			'pink' => [ 'pink', 'صورتی', 'صورتي' ],
			'orange' => [ 'orange', 'نارنجی', 'نارنجي' ],
			'silver' => [ 'silver', 'نقره ای', 'نقره اي' ],
			'gold' => [ 'gold', 'طلایی', 'طلايي', 'golden' ],
			'cream' => [ 'cream', 'کرم', 'کرمی', ' ivory', 'عاجی' ],
			'burgundy' => [ 'burgundy', 'زرشکی', 'زرشكي', 'maroon' ],
			'olive' => [ 'olive', 'زیتونی', 'زيتوني' ],
			'tan' => [ 'tan', 'beige', 'بژ', 'کرم روشن' ],
		];
		foreach ( $groups as $color => $names ) {
			foreach ( $names as $name ) {
				if ( $value === trim( $name ) ) {
					return $color;
				}
			}
		}
		return '';
	}
	private static function aliases(): array {
		return apply_filters( 'bas_attribute_aliases', [
			[ 'shoe', 'shoes', 'کفش', 'کفشات' ], [ 'sneaker', 'sneakers', 'کتانی', 'کتاني', 'اسنیکرز' ],
			[ 'walking', 'walk', 'پیاده روی', 'پیادهروی', 'قدم زدن' ], [ 'running', 'run', 'دویدن', 'دو' ],
			[ 'comfortable', 'comfort', 'راحت', 'آسوده' ], [ 'leather', 'چرم', 'چرمی' ],
			[ 'cotton', 'نخ', 'پنبه', 'نخی', 'پنبه ای' ], [ 'wool', 'پشم', 'پشمی' ],
			[ 'waterproof', 'water resistant', 'ضد اب', 'ضدآب', 'ضد آب' ], [ 'wireless', 'بی سیم', 'بیسیم', 'بی سیم' ],
			[ 'lightweight', 'light', 'سبک' ], [ 'nike', 'نایکی', 'نایک' ], [ 'adidas', 'ادیداس', 'آدیداس' ],
			[ 'samsung', 'سامسونگ' ], [ 'apple', 'اپل' ], [ 'xiaomi', 'شیائومی', 'شیائومي' ],
			[ 'phone', 'mobile', 'smartphone', 'موبایل', 'گوشی', 'تلفن همراه' ],
			[ 'laptop', 'notebook', 'لپ تاپ', 'لپتاپ', 'نوت بوک' ],
			[ 'headphones', 'headphone', 'هدفون', 'هدフォン' ],
		] );
	}

	public static function cosine( array $a, array $b ): float {
		if ( count( $a ) !== count( $b ) ) {
			return 0.0;
		}
		$dot = 0.0;
		foreach ( $a as $i => $value ) {
			$dot += $value * $b[ $i ];
		}
		return max( 0.0, min( 1.0, $dot ) );
	}
}
