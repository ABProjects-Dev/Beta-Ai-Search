<?php
namespace BetaAI\Search\Core;

defined( 'ABSPATH' ) || exit;

/** Secrets never leave PHP. AES-256-GCM; a deployment constant hardens DB-only disclosure. */
final class Secrets {
	private const OPTION = 'bas_provider_secrets';

	private static function key(): string {
		$material = defined( 'BAS_ENCRYPTION_KEY' ) && strlen( (string) BAS_ENCRYPTION_KEY ) >= 32 ? BAS_ENCRYPTION_KEY : wp_salt( 'auth' ) . '|beta-ai-search-v1';
		return hash( 'sha256', $material, true );
	}

	public static function configured( string $provider ): bool {
		return '' !== self::get( $provider );
	}

	public static function set( string $provider, string $value ): bool {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		$all = get_option( self::OPTION, [] );
		$all = is_array( $all ) ? $all : [];
		if ( '' === trim( $value ) ) {
			return true;
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return false;
		}
		$all[ $provider ] = 'v1:' . base64_encode( $iv . $tag . $cipher );
		return update_option( self::OPTION, $all, false );
	}

	public static function get( string $provider ): string {
		$all = get_option( self::OPTION, [] );
		$raw = is_array( $all ) ? ( $all[ $provider ] ?? '' ) : '';
		if ( ! is_string( $raw ) || ! str_starts_with( $raw, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$binary = base64_decode( substr( $raw, 3 ), true );
		if ( false === $binary || strlen( $binary ) < 29 ) {
			return '';
		}
		$value = openssl_decrypt( substr( $binary, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $binary, 0, 12 ), substr( $binary, 12, 16 ) );
		return false === $value ? '' : $value;
	}

	public static function delete( string $provider ): void {
		$all = get_option( self::OPTION, [] );
		if ( is_array( $all ) ) {
			unset( $all[ $provider ] );
			update_option( self::OPTION, $all, false );
		}
	}
}
