<?php
namespace YHLM;
defined( 'ABSPATH' ) || exit;

final class Plugin {
	public static function t( $fa, $en ) {
		return 0 === strpos( determine_locale(), 'fa' ) ? $fa : $en;
	}
	public static function validity_note() {
		return self::t( 'مدت اعتبار این لایسنس از زمانی که آن را فعال کنید شروع می‌شود، نه از زمان خرید.', 'The license validity period starts when you activate it, not when you purchase it.' );
	}
	public static function setup() {
		if ( ! extension_loaded( 'openssl' ) ) {
			throw new \RuntimeException( 'برای تحویل امن کد آماده، OpenSSL باید فعال باشد.' );
		}
		if ( ! defined( 'YHLM_ENCRYPTION_KEY' ) && ! get_option( 'yhlm_encryption_key' ) ) {
			global $wpdb;
			$table = Store::table();
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) ) {
				throw new \RuntimeException( 'موجودی قبلی کلید رمزگذاری ندارد. کلید اصلی را از پشتیبان برگردان.' );
			}
			add_option( 'yhlm_encryption_key', base64_encode( random_bytes( 32 ) ), '', false );
		}
		Crypto::key();
		if ( (int) get_option( 'yhlm_schema_version' ) < 1 ) {
			Store::install();
		}
		if ( ! defined( 'RSLM_PREPAID_READY' ) ) {
			define( 'RSLM_PREPAID_READY', true );
		}
		if ( ! wp_next_scheduled( 'yhlm_cleanup' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'yhlm_cleanup' );
		}
	}
	public static function boot() {
		Orders::register();
		Customer::register();
		Admin::register();
	}
}
