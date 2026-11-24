<?php
namespace YHLM;
defined( 'ABSPATH' ) || exit;

final class Customer {
	public static function register() {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email' ), 20, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'privacy' ), 1 );
	}
	public static function menu( $items ) {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['licenses'] = Plugin::t( 'لایسنس‌های من', 'My licenses' );
		if ( $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}
	public static function assets() {
		if ( is_account_page() ) {
			wp_enqueue_style( 'yhlm', plugins_url( 'prepaid/assets/customer.css', RSLM_FILE ), array(), RSLM_VERSION );
			wp_enqueue_script( 'yhlm', plugins_url( 'prepaid/assets/customer.js', RSLM_FILE ), array(), RSLM_VERSION, true );
		}
	}
	public static function privacy() {
		if ( is_account_page() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}
	public static function can_view( $row, $user_id ) {
		if ( ! $user_id || (int) $row['customer_id'] !== (int) $user_id || 'delivered' !== $row['state'] ) {
			return false;
		}
		$order = wc_get_order( $row['order_id'] );
		return $order && (int) $order->get_customer_id() === (int) $user_id;
	}
	public static function account() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		global $wpdb;
		$page = max( 1, absint( $_GET['license_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE customer_id=%d AND state='delivered' ORDER BY delivered_at DESC,id DESC LIMIT 21 OFFSET %d", Store::table(), get_current_user_id(), ( $page - 1 ) * 20 ), ARRAY_A );
		echo '<section aria-label="' . esc_attr( Plugin::t( 'کدهای آماده', 'Prepaid codes' ) ) . '"><h2>' . esc_html( Plugin::t( 'کدهای آماده', 'Prepaid codes' ) ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html( Plugin::t( 'هنوز لایسنسی تحویل نشده است.', 'No licenses have been delivered yet.' ) ) . '</p>';
		}
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			if ( self::can_view( $row, get_current_user_id() ) ) {
				self::card( $row );
			}
		}
		if ( $page > 1 ) {
			echo '<a href="' . esc_url( add_query_arg( 'license_page', $page - 1 ) ) . '">' . esc_html( Plugin::t( 'قبلی', 'Previous' ) ) . '</a> ';
		}
		if ( count( $rows ) > 20 ) {
			echo '<a href="' . esc_url( add_query_arg( 'license_page', $page + 1 ) ) . '">' . esc_html( Plugin::t( 'بعدی', 'Next' ) ) . '</a>';
		}
		echo '</section>';
	}
	public static function order( $order ) {
		if ( ! is_user_logged_in() || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return;
		}
		foreach ( Store::order_codes( $order->get_id() ) as $row ) {
			if ( self::can_view( $row, get_current_user_id() ) ) {
				self::card( $row );
			}
		}
		if ( $order->get_meta( '_yhlm_error' ) ) {
			echo '<p>' . esc_html( Plugin::t( 'تحویل لایسنس این سفارش نیازمند بررسی پشتیبانی است.', 'License delivery for this order requires support assistance.' ) ) . '</p>';
		}
	}
	public static function card( $row ) {
		$order = wc_get_order( $row['order_id'] );
		$item  = $order ? $order->get_item( $row['item_id'] ) : false;
		try {
			$code = Crypto::decrypt( $row['code_cipher'] );
		} catch ( \Throwable $error ) {
			echo '<p>' . esc_html( Plugin::t( 'برای دریافت کد با پشتیبانی تماس بگیرید.', 'Please contact support to retrieve your code.' ) ) . '</p>';
			return;
		}
		echo '<article class="yhlm-card"><h3>' . esc_html( $item ? $item->get_name() : Plugin::t( 'لایسنس', 'License' ) ) . '</h3>';
		echo '<p>' . esc_html( Plugin::t( 'سفارش: ', 'Order: ' ) ) . '<a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></p>';
		echo '<p>' . esc_html( Plugin::t( 'تاریخ تحویل: ', 'Delivered at: ' ) ) . esc_html( get_date_from_gmt( $row['delivered_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</p>';
		echo '<div class="yhlm-code"><code dir="ltr">' . esc_html( $code ) . '</code><button type="button" class="yhlm-copy" data-copied="' . esc_attr( Plugin::t( 'کپی شد', 'Copied' ) ) . '">' . esc_html( Plugin::t( 'کپی کد', 'Copy code' ) ) . '</button></div>';
		echo '<p class="yhlm-validity">' . esc_html( Plugin::validity_note() ) . '</p>';
		if ( $row['guide_snapshot'] ) {
			echo '<div class="yhlm-guide"><h4>' . esc_html( Plugin::t( 'راهنمای نصب و فعال‌سازی', 'Installation and activation guide' ) ) . '</h4>' . wp_kses_post( wpautop( $row['guide_snapshot'] ) ) . '</div>';
		}
		if ( $row['download_snapshot'] ) {
			echo '<p><a href="' . esc_url( $row['download_snapshot'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( Plugin::t( 'دانلود نرم‌افزار', 'Download software' ) ) . '</a></p>';
		}
		echo '</article>';
	}
	public static function email( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! $order || ! Orders::requirements( $order ) || ! $order->get_customer_id() ) {
			return;
		}
		$message = Plugin::t( 'لایسنس و راهنمای فعال‌سازی پس از تأیید پرداخت در حساب کاربری شما قرار می‌گیرد.', 'Your license and activation guide will be available in your account after payment confirmation.' );
		$url     = wc_get_account_endpoint_url( 'licenses' );
		if ( $plain_text ) {
			echo "\n" . esc_html( $message ) . "\n" . esc_html( Plugin::validity_note() ) . "\n" . esc_url_raw( $url ) . "\n";
		} else {
			echo '<p>' . esc_html( $message ) . '</p><p>' . esc_html( Plugin::validity_note() ) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html( Plugin::t( 'مشاهده لایسنس‌های من', 'View my licenses' ) ) . '</a></p>';
		}
	}
}
