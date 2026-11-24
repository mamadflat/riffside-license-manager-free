<?php
namespace YHLM;
defined( 'ABSPATH' ) || exit;

final class Orders {
	public static function register() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'checkout' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'checkout' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'deliver' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'deliver' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'deliver' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release' ), 20 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'release' ), 20 );
		add_action( 'yhlm_cleanup', array( __CLASS__, 'cleanup' ) );
		add_filter( 'woocommerce_product_get_manage_stock', array( __CLASS__, 'manage_stock' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_manage_stock', array( __CLASS__, 'manage_stock' ), 20, 2 );
		add_filter( 'woocommerce_product_get_stock_status', array( __CLASS__, 'stock_status' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_status', array( __CLASS__, 'stock_status' ), 20, 2 );
		add_filter( 'woocommerce_product_get_stock_quantity', array( __CLASS__, 'stock_quantity' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_quantity', array( __CLASS__, 'stock_quantity' ), 20, 2 );
		add_filter( 'woocommerce_product_get_backorders', array( __CLASS__, 'backorders' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_backorders', array( __CLASS__, 'backorders' ), 20, 2 );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'gateways' ) );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'cart_check' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_to_cart' ), 20, 6 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'update_cart' ), 20, 4 );
	}

	public static function manage_stock( $value, $product ) {
		// The code pool owns stock, avoiding WooCommerce's second reduction/restoration.
		return Store::enabled( $product ) ? false : $value;
	}
	public static function stock_status( $value, $product ) {
		return Store::enabled( $product ) ? ( Store::available( $product->get_id() ) > 0 ? 'instock' : 'outofstock' ) : $value;
	}
	public static function stock_quantity( $value, $product ) {
		return Store::enabled( $product ) ? Store::available( $product->get_id() ) : $value;
	}
	public static function backorders( $value, $product ) {
		return Store::enabled( $product ) ? 'no' : $value;
	}

	private static function cart_quantities( $excluding = '' ) {
		$counts = array();
		if ( ! WC()->cart ) {
			return $counts;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $key !== $excluding && Store::enabled( $item['data'] ) ) {
				$id            = $item['data']->get_id();
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + (int) $item['quantity'];
			}
		}
		return $counts;
	}

	public static function add_to_cart( $valid, $product_id, $quantity, $variation_id = 0, $variations = array(), $data = array() ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( Store::enabled( $product ) ) {
			$counts = self::cart_quantities();
			if ( $quantity < 1 || floor( $quantity ) !== (float) $quantity || $quantity + ( $counts[ $product->get_id() ] ?? 0 ) > Store::available( $product->get_id() ) ) {
				wc_add_notice( Plugin::t( 'تعداد درخواستی از موجودی لایسنس بیشتر است.', 'Requested quantity exceeds license inventory.' ), 'error' );
				return false;
			}
		}
		return $valid;
	}

	public static function update_cart( $valid, $key, $item, $quantity ) {
		if ( Store::enabled( $item['data'] ) ) {
			$id     = $item['data']->get_id();
			$counts = self::cart_quantities( $key );
			if ( $quantity < 0 || floor( $quantity ) !== (float) $quantity || $quantity + ( $counts[ $id ] ?? 0 ) > Store::available( $id ) ) {
				wc_add_notice( Plugin::t( 'موجودی لایسنس کافی نیست.', 'Insufficient license inventory.' ), 'error' );
				return false;
			}
		}
		return $valid;
	}

	public static function cart_check() {
		foreach ( self::cart_quantities() as $id => $quantity ) {
			if ( $quantity > Store::available( $id ) ) {
				wc_add_notice( Plugin::t( 'موجودی لایسنس کافی نیست؛ سبد خرید را اصلاح کنید.', 'Insufficient licenses; update your cart.' ), 'error' );
			}
		}
	}

	public static function gateways( $gateways ) {
		if ( self::cart_quantities() ) {
			unset( $gateways['cod'] );
		}
		return $gateways;
	}

	public static function checkout( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_rslm_method' ) !== 'issuer' && Store::enabled( $item->get_product() ) ) {
				$item->update_meta_data( '_rslm_method', 'prepaid' );
				$item->update_meta_data( '_yhlm_product_id', $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
				$item->save();
			}
		}
		if ( ! self::requirements( $order ) ) {
			return;
		}
		if ( ! $order->get_customer_id() ) {
			throw new \Exception( esc_html( Plugin::t( 'برای خرید لایسنس وارد حساب کاربری شوید یا حساب بسازید.', 'Sign in or create an account to purchase licenses.' ) ) );
		}
		self::allocate( $order, false );
	}

	public static function requirements( $order ) {
		$requirements = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$id = (int) $item->get_meta( '_yhlm_product_id' );
			if ( ! $id && $item->get_meta( '_rslm_method' ) !== 'issuer' && Store::enabled( $item->get_product() ) ) {
				$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			}
			if ( $id ) {
				$quantity = $item->get_quantity();
				if ( $quantity < 1 || floor( $quantity ) !== (float) $quantity ) {
					throw new \RuntimeException( 'License quantities must be positive integers.' );
				}
				$requirements[ $item_id ] = array(
					'product_id' => $id,
					'quantity'   => (int) $quantity,
				);
			}
		}
		return $requirements;
	}

	public static function allocate( $order, $deliver ) {
		$requirements = self::requirements( $order );
		if ( ! $requirements && ! Store::order_codes( $order->get_id(), false ) ) {
			return 0;
		}
		if ( ! $order->get_customer_id() ) {
			throw new \RuntimeException( 'License delivery requires a customer account.' );
		}
		return Store::transaction(
			static function () use ( $order, $deliver, $requirements ) {
				global $wpdb;
				$table    = Store::table();
				$order_id = $order->get_id();
				$changed  = 0;
				// An administrator may reduce or remove an unpaid order line.
				foreach ( Store::order_codes( $order_id, false ) as $existing ) {
					$needed = $requirements[ $existing['item_id'] ]['quantity'] ?? 0;
					if ( 'reserved' === $existing['state'] && (int) $existing['slot'] > $needed ) {
						Store::query( $wpdb->prepare( "UPDATE %i SET state='available',order_id=NULL,item_id=NULL,slot=NULL,customer_id=NULL,reserved_until=NULL WHERE id=%d AND state='reserved'", $table, $existing['id'] ) );
						Store::event( 'released_edited_line', $existing['id'], $order_id );
					}
				}
				foreach ( $requirements as $item_id => $requirement ) {
					$product = wc_get_product( $requirement['product_id'] );
					for ( $slot = 1; $slot <= $requirement['quantity']; ++$slot ) {
						$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE item_id=%d AND slot=%d FOR UPDATE', $table, $item_id, $slot ), ARRAY_A );
						if ( $row && (int) $row['order_id'] !== $order_id ) {
							throw new \RuntimeException( 'License allocation mismatch.' );
						}
						if ( $row && 'delivered' === $row['state'] ) {
							continue;
						}
						if ( $row && 'reserved' !== $row['state'] ) {
							throw new \RuntimeException( 'License allocation blocked.' );
						}
						if ( ! $row ) {
							$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE product_id=%d AND state='available' ORDER BY id LIMIT 1 FOR UPDATE", $table, $requirement['product_id'] ), ARRAY_A );
							if ( ! $row ) {
								throw new \RuntimeException( esc_html( Plugin::t( 'موجودی لایسنس کافی نیست.', 'Insufficient license inventory.' ) ) );
							}
							Store::query( $wpdb->prepare( "UPDATE %i SET state='reserved',order_id=%d,item_id=%d,slot=%d,customer_id=%d,reserved_until=%s WHERE id=%d AND state='available'", $table, $order_id, $item_id, $slot, $order->get_customer_id(), gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ), $row['id'] ) );
							Store::event( 'reserved', $row['id'], $order_id );
						}
						if ( $deliver ) {
							// Verify decryptability BEFORE publishing the entitlement.
							Crypto::decrypt( $row['code_cipher'] );
							Store::query( $wpdb->prepare( "UPDATE %i SET state='delivered',delivered_at=%s,reserved_until=NULL,guide_snapshot=%s,download_snapshot=%s WHERE id=%d AND state='reserved'", $table, gmdate( 'Y-m-d H:i:s' ), $product ? $product->get_meta( '_yhlm_guide' ) : '', $product ? $product->get_meta( '_yhlm_download_url' ) : '', $row['id'] ) );
							Store::event( 'delivered', $row['id'], $order_id );
							++$changed;
						}
					}
				}
				return $changed;
			}
		);
	}

	public static function deliver( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || 'cod' === $order->get_payment_method() ) {
			return false;
		}
		try {
			$count = self::allocate( $order, true );
			if ( $order->get_meta( '_yhlm_error' ) ) {
				$order->delete_meta_data( '_yhlm_error' );
				$order->save();
			}
			if ( $count ) {
				$order->add_order_note( Plugin::t( 'لایسنس‌ها در حساب کاربری مشتری تحویل شدند. تعداد: ', 'Licenses delivered to the customer account. Quantity: ' ) . $count );
				do_action( 'yhlm_order_delivered', $order_id, $count );
			}
			return true;
		} catch ( \Throwable $error ) {
			$message = $error->getMessage();
			if ( $order->get_meta( '_yhlm_error' ) !== $message ) {
				$order->update_meta_data( '_yhlm_error', $message );
				$order->save();
				$order->add_order_note( 'YHLM: ' . $message );
			}
			return false;
		}
	}

	public static function release( $order_id ) {
		Store::transaction(
			static function () use ( $order_id ) {
				global $wpdb;
				foreach ( Store::order_codes( $order_id, false ) as $row ) {
					if ( 'reserved' === $row['state'] ) {
						Store::query( $wpdb->prepare( "UPDATE %i SET state='available',order_id=NULL,item_id=NULL,slot=NULL,customer_id=NULL,reserved_until=NULL WHERE id=%d AND state='reserved'", Store::table(), $row['id'] ) );
						Store::event( 'released', $row['id'], $order_id );
					}
				}
			}
		);
	}

	public static function cleanup() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM %i WHERE state='reserved' AND reserved_until < %s LIMIT 100", Store::table(), gmdate( 'Y-m-d H:i:s' ) ) );
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order && $order->is_paid() ) {
				self::deliver( $id );
			} elseif ( ! $order || $order->has_status( array( 'pending', 'failed', 'cancelled', 'checkout-draft' ) ) ) {
				self::release( $id );
			}
		}
	}

	public static function replace( $code_id, $reason ) {
		return Store::transaction(
			static function () use ( $code_id, $reason ) {
				global $wpdb;
				$table = Store::table();
				$old   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d FOR UPDATE', $table, $code_id ), ARRAY_A );
				if ( ! $old || 'delivered' !== $old['state'] ) {
					throw new \RuntimeException( 'Only a delivered code can be replaced.' );
				}
				$new = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE product_id=%d AND state='available' ORDER BY id LIMIT 1 FOR UPDATE", $table, $old['product_id'] ), ARRAY_A );
				if ( ! $new ) {
					throw new \RuntimeException( 'No replacement code available.' );
				}
				Crypto::decrypt( $new['code_cipher'] );
				Store::query( $wpdb->prepare( "UPDATE %i SET state='replaced',slot=NULL WHERE id=%d", $table, $old['id'] ) );
				Store::query( $wpdb->prepare( "UPDATE %i SET state='delivered',order_id=%d,item_id=%d,slot=%d,customer_id=%d,delivered_at=%s,guide_snapshot=%s,download_snapshot=%s WHERE id=%d", $table, $old['order_id'], $old['item_id'], $old['slot'], $old['customer_id'], gmdate( 'Y-m-d H:i:s' ), $old['guide_snapshot'], $old['download_snapshot'], $new['id'] ) );
				Store::event( 'replaced', $old['id'], $old['order_id'], 'Replacement #' . $new['id'] . ': ' . $reason );
				Store::event( 'delivered_replacement', $new['id'], $old['order_id'], 'Previous #' . $old['id'] );
				return $old['order_id'];
			}
		);
	}
}
