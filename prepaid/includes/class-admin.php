<?php
namespace YHLM;
defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_panel' ) );
		add_action( 'admin_post_yhlm_action', array( __CLASS__, 'action' ) );
	}
	public static function menu() {
		add_submenu_page( 'woocommerce', 'مدیریت لایسنس ریف‌ساید', 'مدیریت لایسنس', 'manage_woocommerce', 'yhlm', array( __CLASS__, 'page' ) );
	}
	private static function allowed() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Access denied.', '', array( 'response' => 403 ) );
		}
	}
	public static function product_fields() {
		echo '<div class="options_group show_if_simple">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_yhlm_enabled',
				'label'       => Plugin::t( 'تحویل خودکار لایسنس', 'Automatic license delivery' ),
				'description' => Plugin::t( 'موجودی از تعداد کدهای آماده محاسبه می‌شود.', 'Inventory is calculated from available codes.' ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'    => '_yhlm_guide',
				'label' => Plugin::t( 'راهنمای فعال‌سازی', 'Activation guide' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_yhlm_download_url',
				'label' => Plugin::t( 'لینک رسمی نرم‌افزار', 'Official software URL' ),
				'type'  => 'url',
			)
		);
		global $product_object;
		if ( $product_object ) {
			echo '<p class="form-field">' . esc_html( Plugin::t( 'کد آماده: ', 'Available codes: ' ) . Store::available( $product_object->get_id() ) ) . ' — <a href="' . esc_url( add_query_arg( 'product_id', $product_object->get_id(), admin_url( 'admin.php?page=yhlm&tab=import' ) ) ) . '">' . esc_html( Plugin::t( 'ورود لایسنس‌ها', 'Import licenses' ) ) . '</a></p>';
		}
		echo '</div>';
	}
	public static function save_product( $product ) {
		if ( ! current_user_can( 'edit_post', $product->get_id() ) || ! current_user_can( 'manage_woocommerce' ) || ! $product->is_type( 'simple' ) ) {
			return;
		}
		// WooCommerce verifies its product save nonce before this hook.
		$enabled = isset( $_POST['_yhlm_enabled'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( '_yhlm_enabled', $enabled );
		$product->update_meta_data( '_yhlm_guide', wp_kses_post( wp_unslash( $_POST['_yhlm_guide'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( '_yhlm_download_url', esc_url_raw( wp_unslash( $_POST['_yhlm_download_url'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'yes' === $enabled ) {
			$product->set_virtual( true );
			$product->set_manage_stock( false );
			$product->set_backorders( 'no' );
			$count = Store::available( $product->get_id() );
			$product->set_stock_quantity( $count );
			$product->set_stock_status( $count ? 'instock' : 'outofstock' );
		}
	}
	public static function variation_fields( $loop, $data, $variation ) {
		$product = wc_get_product( $variation->ID );
		woocommerce_wp_checkbox(
			array(
				'id'    => 'yhlm_enabled_' . $loop,
				'name'  => 'yhlm_enabled[' . $loop . ']',
				'value' => $product->get_meta( '_yhlm_enabled' ),
				'label' => Plugin::t( 'تحویل لایسنس این تنوع', 'Deliver licenses for this variation' ),
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'            => 'yhlm_guide_' . $loop,
				'name'          => 'yhlm_guide[' . $loop . ']',
				'value'         => $product->get_meta( '_yhlm_guide' ),
				'label'         => Plugin::t( 'راهنمای فعال‌سازی', 'Activation guide' ),
				'wrapper_class' => 'form-row form-row-full',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => 'yhlm_url_' . $loop,
				'name'          => 'yhlm_url[' . $loop . ']',
				'value'         => $product->get_meta( '_yhlm_download_url' ),
				'label'         => Plugin::t( 'لینک رسمی نرم‌افزار', 'Official software URL' ),
				'type'          => 'url',
				'wrapper_class' => 'form-row form-row-full',
			)
		);
		echo '<p>' . esc_html( Plugin::t( 'شناسه تنوع: ', 'Variation ID: ' ) . $variation->ID . ' | ' . Plugin::t( 'کد آماده: ', 'Available codes: ' ) . Store::available( $variation->ID ) ) . '</p>';
	}
	public static function save_variation( $product, $index ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $product->get_parent_id() ) ) {
			return;
		}
		$enabled = isset( $_POST['yhlm_enabled'][ $index ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( '_yhlm_enabled', $enabled );
		$product->update_meta_data( '_yhlm_guide', wp_kses_post( wp_unslash( $_POST['yhlm_guide'][ $index ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( '_yhlm_download_url', esc_url_raw( wp_unslash( $_POST['yhlm_url'][ $index ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'yes' === $enabled ) {
			$product->set_virtual( true );
			$product->set_manage_stock( false );
			$product->set_backorders( 'no' );
			$count = Store::available( $product->get_id() );
			$product->set_stock_quantity( $count );
			$product->set_stock_status( $count ? 'instock' : 'outofstock' );
		}
	}
	public static function order_panel( $order ) {
		self::allowed();
		$rows = Store::order_codes( $order->get_id(), false );
		if ( ! $rows && ! Orders::requirements( $order ) ) {
			return;
		}
		echo '<div class="yhlm-order"><h3>' . esc_html( Plugin::t( 'لایسنس‌های سفارش', 'Order licenses' ) ) . '</h3>';
		foreach ( $rows as $row ) {
			echo '<p>#' . esc_html( $row['id'] ) . ' — ' . esc_html( self::state_label( $row['state'] ) ) . ' — ' . esc_html( $row['delivered_at'] ? get_date_from_gmt( $row['delivered_at'] ) : '—' ) . '</p>';
		}
		if ( $order->get_meta( '_yhlm_error' ) ) {
			echo '<p style="color:#b32d2e">' . esc_html( $order->get_meta( '_yhlm_error' ) ) . '</p>';
		}
		echo '<a href="' . esc_url( add_query_arg( 'order_id', $order->get_id(), admin_url( 'admin.php?page=yhlm&tab=report' ) ) ) . '">' . esc_html( Plugin::t( 'گزارش و تلاش مجدد تحویل', 'Report and retry delivery' ) ) . '</a></div>';
	}
	private static function state_label( $state ) {
		$fa = array(
			'available' => 'آماده',
			'reserved'  => 'رزروشده',
			'delivered' => 'تحویل‌شده',
			'blocked'   => 'مسدود',
			'replaced'  => 'تعویض‌شده',
		);
		return Plugin::t( $fa[ $state ] ?? $state, ucfirst( $state ) );
	}
	private static function form_start( $operation ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'yhlm_action' );
		echo '<input type="hidden" name="action" value="yhlm_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
	}
	private static function url( $tab ) {
		return admin_url( 'admin.php?page=yhlm&tab=' . $tab );
	}
	public static function page() {
		self::allowed();
		nocache_headers();
		$tab = sanitize_key( $_GET['tab'] ?? 'inventory' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap"><h1>مدیریت لایسنس ریف‌ساید</h1>';
		echo '<p><a class="button button-secondary" href="' . esc_url( \Riffside\LicenseManager\Plugin::PRODUCT_URL ) . '">' . esc_html( Plugin::t( 'ارتقا به پرو', 'Upgrade to Pro' ) ) . '</a></p>';
		echo '<nav class="nav-tab-wrapper" aria-label="مدیریت کدهای آماده">';
		foreach ( array(
			'inventory' => Plugin::t( 'موجودی', 'Inventory' ),
			'import'    => Plugin::t( 'ورود گروهی', 'Bulk import' ),
			'report'    => Plugin::t( 'گزارش تحویل', 'Delivery report' ),
			'events'    => Plugin::t( 'رویدادها', 'Events' ),
		) as $key => $label ) {
			echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		$flash_key = 'yhlm_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( $flash ) {
			delete_transient( $flash_key );
			echo '<div class="notice notice-info"><p>' . esc_html( $flash ) . '</p></div>';
		}
		if ( 'import' === $tab ) {
			self::import_page();
		} elseif ( 'report' === $tab ) {
			self::report();
		} elseif ( 'events' === $tab ) {
			self::events();
		} else {
			self::inventory();
		}
		echo '</div>';
	}
	private static function inventory() {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT product_id,state,COUNT(*) AS total FROM %i GROUP BY product_id,state ORDER BY product_id', Store::table() ), ARRAY_A );
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ $row['product_id'] ][ $row['state'] ] = $row['total'];
		}
		echo '<p>' . esc_html( Plugin::t( 'موجودی از کدهای آماده محاسبه می‌شود. رزرو پرداخت ۳۰ دقیقه است؛ سفارش در انتظار تأیید دستی تا تعیین تکلیف رزرو می‌ماند.', 'Available codes determine stock. Pending payment reservations last 30 minutes; on-hold orders retain reservations until resolved.' ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( Plugin::t( 'محصول', 'Product' ) ) . '</th>';
		foreach ( array( 'available', 'reserved', 'delivered', 'blocked', 'replaced' ) as $state ) {
			echo '<th>' . esc_html( self::state_label( $state ) ) . '</th>';
		}
		echo '<th>' . esc_html( Plugin::t( 'عملیات', 'Actions' ) ) . '</th></tr></thead><tbody>';
		foreach ( $groups as $id => $counts ) {
			$product = wc_get_product( $id );
			echo '<tr><td>' . esc_html( $product ? $product->get_name() : '#' . $id ) . ' (#' . esc_html( $id ) . ')</td>';
			foreach ( array( 'available', 'reserved', 'delivered', 'blocked', 'replaced' ) as $state ) {
				echo '<td>' . esc_html( $counts[ $state ] ?? 0 ) . ( 'available' === $state && ( $counts[ $state ] ?? 0 ) <= 5 ? ' ⚠' : '' ) . '</td>';
			}
			echo '<td><a href="' . esc_url( add_query_arg( 'product_id', $id, self::url( 'import' ) ) ) . '">' . esc_html( Plugin::t( 'افزودن کد', 'Add codes' ) ) . '</a></td></tr>';
		}
		echo '</tbody></table><p>' . esc_html( Plugin::t( '⚠ یعنی موجودی ۵ کد یا کمتر است. راهنما و فایل مشترک را در محصول تنظیم کنید.', '⚠ indicates 5 or fewer available codes. Configure the guide and shared downloadable files in the product.' ) ) . '</p>';
	}
	private static function import_page() {
		$pending = get_transient( 'yhlm_import_' . get_current_user_id() );
		if ( $pending ) {
			try {
				$rows    = json_decode( Crypto::decrypt( $pending['cipher'] ), true, 512, JSON_THROW_ON_ERROR );
				$preview = Store::preview( $rows );
				echo '<h2>' . esc_html( Plugin::t( 'پیش‌نمایش ورود', 'Import preview' ) ) . '</h2><p>' . esc_html( Plugin::t( 'کد جدید: ', 'New codes: ' ) . $preview['new'] . ' | ' . Plugin::t( 'تکراری (رد می‌شود): ', 'Duplicates (skipped): ' ) . $preview['duplicates'] ) . '</p><ul>';
				foreach ( $preview['products'] as $id => $count ) {
					echo '<li>' . esc_html( wc_get_product( $id )->get_name() . ' (#' . $id . '): ' . $count ) . '</li>';
				}
				echo '</ul>';
				self::form_start( 'confirm_import' );
				echo '<input type="hidden" name="token" value="' . esc_attr( $pending['token'] ) . '">';
				submit_button( Plugin::t( 'تأیید و ثبت کدها', 'Confirm import' ) );
				echo '</form>';
			} catch ( \Throwable $error ) {
				echo '<p>' . esc_html( $error->getMessage() ) . '</p>';
			}
		}
		echo '<h2>' . esc_html( Plugin::t( 'ورود کدهای جدید', 'Import new codes' ) ) . '</h2><p>' . esc_html( Plugin::t( 'برای TXT یا ورود دستی، شناسه محصول یا تنوع را وارد کنید. برای CSV چندمحصولی شناسه را خالی بگذارید.', 'For TXT or pasted codes, enter a product/variation ID. For a multi-product CSV leave the ID blank.' ) ) . '</p><p><code>product_id,code,batch</code> — ' . esc_html( Plugin::t( 'حداکثر ۵۰۰۰ کد و ۲ مگابایت؛ ابتدا تحویل لایسنس محصول را فعال کنید.', 'Maximum 5000 codes and 2 MB; enable license delivery on the product first.' ) ) . '</p>';
		self::form_start( 'preview_import' );
		echo '<p><label>' . esc_html( Plugin::t( 'شناسه محصول / تنوع', 'Product / variation ID' ) ) . ' <input type="number" min="0" name="product_id" value="' . esc_attr( absint( $_GET['product_id'] ?? 0 ) ) . '"></label></p>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<p><label>' . esc_html( Plugin::t( 'شناسه محموله', 'Batch label' ) ) . ' <input name="batch" maxlength="100"></label></p>';
		echo '<p><label>' . esc_html( Plugin::t( 'فایل CSV یا TXT', 'CSV or TXT file' ) ) . ' <input type="file" name="licenses_file" accept=".csv,.txt"></label></p>';
		echo '<p><label for="yhlm-codes">' . esc_html( Plugin::t( 'یا کدها را اینجا بچسبانید', 'Or paste codes here' ) ) . '</label></p><textarea id="yhlm-codes" name="codes" rows="10" cols="70" dir="ltr" autocomplete="off"></textarea>';
		submit_button( Plugin::t( 'بررسی و پیش‌نمایش', 'Validate and preview' ) );
		echo '</form>';
	}
	private static function report_query() {
		$filters = array();
		foreach ( array( 'product_id', 'order_id', 'customer_id' ) as $field ) {
			$filters[ $field ] = absint( $_GET[ $field ] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$state            = sanitize_key( $_GET['state'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filters['state'] = in_array( $state, array( 'available', 'reserved', 'delivered', 'blocked', 'replaced' ), true ) ? $state : '';
		return $filters;
	}
	private static function report() {
		global $wpdb;
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f     = self::report_query();
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE (%d=0 OR product_id=%d) AND (%d=0 OR order_id=%d) AND (%d=0 OR customer_id=%d) AND (%s='' OR state=%s)", Store::table(), $f['product_id'], $f['product_id'], $f['order_id'], $f['order_id'], $f['customer_id'], $f['customer_id'], $f['state'], $f['state'] ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE (%d=0 OR product_id=%d) AND (%d=0 OR order_id=%d) AND (%d=0 OR customer_id=%d) AND (%s='' OR state=%s) ORDER BY id DESC LIMIT 25 OFFSET %d", Store::table(), $f['product_id'], $f['product_id'], $f['order_id'], $f['order_id'], $f['customer_id'], $f['customer_id'], $f['state'], $f['state'], ( $page - 1 ) * 25 ), ARRAY_A );
		echo '<form method="get"><input type="hidden" name="page" value="yhlm"><input type="hidden" name="tab" value="report">';
		foreach ( array(
			'product_id'  => Plugin::t( 'شناسه محصول', 'Product ID' ),
			'order_id'    => Plugin::t( 'شماره سفارش', 'Order ID' ),
			'customer_id' => Plugin::t( 'شناسه مشتری', 'Customer ID' ),
		) as $field => $label ) {
			echo '<label>' . esc_html( $label ) . ' <input type="number" name="' . esc_attr( $field ) . '" value="' . esc_attr( absint( $_GET[ $field ] ?? 0 ) ) . '" style="width:100px"></label> '; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '<select name="state"><option value="">' . esc_html( Plugin::t( 'همه وضعیت‌ها', 'All states' ) ) . '</option>';
		foreach ( array( 'available', 'reserved', 'delivered', 'blocked', 'replaced' ) as $state ) {
			echo '<option value="' . esc_attr( $state ) . '" ' . selected( sanitize_key( $_GET['state'] ?? '' ), $state, false ) . '>' . esc_html( self::state_label( $state ) ) . '</option>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		echo '</select> <button class="button">' . esc_html( Plugin::t( 'فیلتر', 'Filter' ) ) . '</button></form><p>' . esc_html( Plugin::t( 'تعداد: ', 'Count: ' ) . $total ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( '#', Plugin::t( 'محصول', 'Product' ), Plugin::t( 'محموله', 'Batch' ), Plugin::t( 'وضعیت', 'State' ), Plugin::t( 'سفارش / مشتری', 'Order / customer' ), Plugin::t( 'تاریخ تحویل', 'Delivered at' ), Plugin::t( 'عملیات', 'Actions' ) ) as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$product = wc_get_product( $row['product_id'] );
			$order   = $row['order_id'] ? wc_get_order( $row['order_id'] ) : false;
			echo '<tr><td>' . esc_html( $row['id'] ) . '<br><code>••••••••</code></td><td>' . esc_html( $product ? $product->get_name() : '#' . $row['product_id'] ) . '</td><td>' . esc_html( $row['batch'] ) . '</td><td>' . esc_html( self::state_label( $row['state'] ) ) . '</td><td>';
			if ( $order ) {
				echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> / ' . esc_html( $row['customer_id'] );
			}
			echo '</td><td>' . esc_html( $row['delivered_at'] ? get_date_from_gmt( $row['delivered_at'] ) : '—' ) . '</td><td>';
			self::form_start( 'reveal' );
			echo '<input type="hidden" name="code_id" value="' . esc_attr( $row['id'] ) . '"><button class="button">' . esc_html( Plugin::t( 'مشاهده کد', 'Reveal code' ) ) . '</button></form>';
			if ( 'available' === $row['state'] ) {
				self::form_start( 'block' );
				echo '<input type="hidden" name="code_id" value="' . esc_attr( $row['id'] ) . '"><button class="button">' . esc_html( Plugin::t( 'مسدودکردن', 'Block' ) ) . '</button></form>';
			}
			if ( 'delivered' === $row['state'] ) {
				self::form_start( 'replace' );
				echo '<input type="hidden" name="code_id" value="' . esc_attr( $row['id'] ) . '"><input name="reason" required maxlength="150" placeholder="' . esc_attr( Plugin::t( 'دلیل تعویض', 'Replacement reason' ) ) . '"><button class="button">' . esc_html( Plugin::t( 'تعویض کد', 'Replace code' ) ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><p>';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => (int) ceil( $total / 25 ),
				)
			)
		);
		echo '</p><h3>' . esc_html( Plugin::t( 'تلاش مجدد برای سفارش پرداخت‌شده', 'Retry a paid order' ) ) . '</h3>';
		self::form_start( 'retry' );
		echo '<label>' . esc_html( Plugin::t( 'شناسه سفارش', 'Order ID' ) ) . ' <input name="order_id" type="number" min="1" required value="' . esc_attr( absint( $_GET['order_id'] ?? 0 ) ) . '"></label> '; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		submit_button( Plugin::t( 'تلاش مجدد تحویل', 'Retry delivery' ), 'secondary', 'submit', false );
		echo '</form><p>' . esc_html( Plugin::t( 'تعویض کد فوری انجام می‌شود و کد قبلی هرگز دوباره فروخته نمی‌شود.', 'Replacement is immediate; the previous code is never resold.' ) ) . '</p>';
	}
	private static function events() {
		global $wpdb;
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Store::audit_table() ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 50 OFFSET %d', Store::audit_table(), ( $page - 1 ) * 50 ), ARRAY_A );
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Event</th><th>Code / Order / Actor</th><th>Detail</th><th>Time</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['id'] ) . '</td><td>' . esc_html( $row['event'] ) . '</td><td>' . esc_html( $row['code_id'] . ' / ' . $row['order_id'] . ' / ' . $row['actor_id'] ) . '</td><td>' . esc_html( $row['detail'] ) . '</td><td>' . esc_html( get_date_from_gmt( $row['created_at'] ) ) . '</td></tr>';
		}
		echo '</tbody></table><p>' . wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => (int) ceil( $total / 50 ),
				)
			)
		) . '</p>';
	}
	public static function action() {
		self::allowed();
		check_admin_referer( 'yhlm_action' );
		nocache_headers();
		$operation = sanitize_key( $_POST['operation'] ?? '' );
		$tab       = 'report';
		try {
			if ( 'preview_import' === $operation ) {
				$text = wp_unslash( $_POST['codes'] ?? '' );
				if ( isset( $_FILES['licenses_file'] ) && UPLOAD_ERR_NO_FILE !== $_FILES['licenses_file']['error'] ) {
					$file = $_FILES['licenses_file'];
					if ( UPLOAD_ERR_OK !== $file['error'] || $file['size'] > 2 * 1024 * 1024 || ! is_uploaded_file( $file['tmp_name'] ) || ! in_array( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ), array( 'csv', 'txt' ), true ) ) {
						throw new \InvalidArgumentException( 'Invalid license upload.' );
					}
					$text = file_get_contents( $file['tmp_name'] );
				}
				if ( ! is_string( $text ) ) {
					throw new \InvalidArgumentException( 'License file could not be read.' );
				}
				$rows = Import::parse( $text, absint( $_POST['product_id'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) ) );
				Store::preview( $rows );
				set_transient(
					'yhlm_import_' . get_current_user_id(),
					array(
						'token'  => wp_generate_password( 32, false ),
						'cipher' => Crypto::encrypt( wp_json_encode( $rows ) ),
					),
					15 * MINUTE_IN_SECONDS
				);
				$tab     = 'import';
				$message = Plugin::t( 'فایل بررسی شد؛ پیش‌نمایش را تأیید کنید.', 'Validated; confirm the preview.' );
			} elseif ( 'confirm_import' === $operation ) {
				$tab     = 'import';
				$pending = get_transient( 'yhlm_import_' . get_current_user_id() );
				if ( ! $pending || ! hash_equals( $pending['token'], (string) ( $_POST['token'] ?? '' ) ) ) {
					throw new \RuntimeException( 'Import preview expired; upload again.' );
				}
				$rows   = json_decode( Crypto::decrypt( $pending['cipher'] ), true, 512, JSON_THROW_ON_ERROR );
				$result = Store::import( $rows );
				delete_transient( 'yhlm_import_' . get_current_user_id() );
				$message = Plugin::t( 'کد ثبت‌شده: ', 'Imported codes: ' ) . $result['new'] . ' | ' . Plugin::t( 'تکراری: ', 'Duplicates: ' ) . $result['duplicates'];
			} elseif ( 'retry' === $operation ) {
				$order_id = absint( $_POST['order_id'] ?? 0 );
				$message  = Orders::deliver( $order_id ) ? Plugin::t( 'بررسی تحویل انجام شد.', 'Delivery check completed.' ) : Plugin::t( 'تحویل انجام نشد؛ پرداخت و یادداشت سفارش را بررسی کنید.', 'Delivery failed; check payment and order notes.' );
			} elseif ( 'block' === $operation ) {
				Store::block( absint( $_POST['code_id'] ?? 0 ) );
				$message = Plugin::t( 'کد مسدود شد.', 'Code blocked.' );
			} elseif ( 'replace' === $operation ) {
				$reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
				if ( ! $reason || strlen( $reason ) > 150 ) {
					throw new \InvalidArgumentException( 'Replacement reason is required (maximum 150 bytes).' );
				}
				$order_id = Orders::replace( absint( $_POST['code_id'] ?? 0 ), $reason );
				$order    = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note( Plugin::t( 'کد جایگزین در حساب کاربری تحویل شد.', 'A replacement license was delivered to your account.' ), true );
				}
				$message = Plugin::t( 'کد تعویض شد و سابقه محفوظ است.', 'Code replaced; history preserved.' );
			} elseif ( 'reveal' === $operation ) {
				global $wpdb;
				$id  = absint( $_POST['code_id'] ?? 0 );
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', Store::table(), $id ), ARRAY_A );
				if ( ! $row ) {
					throw new \RuntimeException( 'Code not found.' );
				}
				$code = Crypto::decrypt( $row['code_cipher'] );
				Store::event( 'admin_revealed', $id, $row['order_id'] ? $row['order_id'] : 0 );
				wp_die( '<p><code dir="ltr">' . esc_html( $code ) . '</code></p><p><a href="' . esc_url( self::url( 'report' ) ) . '">' . esc_html( Plugin::t( 'بازگشت به گزارش', 'Back to report' ) ) . '</a></p>', esc_html( Plugin::t( 'کد لایسنس', 'License code' ) ), array( 'response' => 200 ) );
			} else {
				throw new \InvalidArgumentException( 'Unknown operation.' );
			}
		} catch ( \Throwable $error ) {
			$message = $error->getMessage();
			if ( in_array( $operation, array( 'preview_import', 'confirm_import' ), true ) ) {
				$tab = 'import';
			}
		}
		set_transient( 'yhlm_flash_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}
}
