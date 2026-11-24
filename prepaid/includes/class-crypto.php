<?php
namespace YHLM;
defined( 'ABSPATH' ) || exit;

/** Authenticated encryption; key is independent of WordPress salt rotations. */
final class Crypto {
	public static function key() {
		$encoded = defined( 'YHLM_ENCRYPTION_KEY' ) ? YHLM_ENCRYPTION_KEY : get_option( 'yhlm_encryption_key' );
		$key     = base64_decode( (string) $encoded, true );
		if ( false === $key || 32 !== strlen( $key ) ) {
			throw new \RuntimeException( 'License encryption key unavailable. Restore the key from backup.' );
		}
		return $key;
	}

	public static function encrypt( $value ) {
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'YHLM:v1', 16 );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'License encryption failed.' );
		}
		return base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( $encoded ) {
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			throw new \RuntimeException( 'Invalid encrypted license.' );
		}
		$value = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'YHLM:v1' );
		if ( false === $value ) {
			throw new \RuntimeException( 'License authentication failed.' );
		}
		return $value;
	}

	public static function fingerprint( $value ) {
		return hash_hmac( 'sha256', $value, self::key() );
	}
}
