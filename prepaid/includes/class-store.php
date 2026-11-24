<?php
namespace YHLM;
defined( 'ABSPATH' ) || exit;

/** All inventory mutations share a connection-scoped lock and InnoDB transaction. */
final class Store {
	private static $dirty_products = array();
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'yhlm_codes';
	}

	public static function audit_table() {
		global $wpdb;
		return $wpdb->prefix . 'yhlm_events';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$table   = self::table();
		$events  = self::audit_table();
		dbDelta(
			"CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            code_cipher longtext NOT NULL,
            code_hash varchar(64) NOT NULL,
            batch varchar(100) NOT NULL DEFAULT '',
            state varchar(20) NOT NULL DEFAULT 'available',
            order_id bigint(20) unsigned DEFAULT NULL,
            item_id bigint(20) unsigned DEFAULT NULL,
            slot int(10) unsigned DEFAULT NULL,
            customer_id bigint(20) unsigned DEFAULT NULL,
            reserved_until datetime DEFAULT NULL,
            delivered_at datetime DEFAULT NULL,
            guide_snapshot longtext DEFAULT NULL,
            download_snapshot text DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_hash (code_hash),
            UNIQUE KEY allocation (item_id,slot),
            KEY inventory (product_id,state),
            KEY order_id (order_id),
            KEY customer (customer_id,state),
            KEY reservation (state,reserved_until)
        ) ENGINE=InnoDB $collate;"
		);
		dbDelta(
			"CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event varchar(40) NOT NULL,
            detail varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY code_id (code_id),
            KEY order_id (order_id)
        ) ENGINE=InnoDB $collate;"
		);
		foreach ( array( $table, $events ) as $name ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name ) );
			if ( 'InnoDB' !== $engine ) {
				throw new \RuntimeException( 'License tables require InnoDB.' );
			}
		}
		update_option( 'yhlm_schema_version', 1, false );
	}

	public static function transaction( $callback ) {
		global $wpdb;
		$lock = 'yhlm_' . substr( hash( 'sha256', DB_NAME . $wpdb->prefix ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			throw new \RuntimeException( 'License inventory busy; retry shortly.' );
		}
		try {
			self::query( 'START TRANSACTION' );
			$result = $callback();
			self::query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		} finally {
			try {
				// Keep the inventory lock until catalog mirrors are refreshed.
				self::sync_stock();
			} finally {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	public static function query( $sql ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal callers pass a prepared query or literal transaction command.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// Never surface database text: it can contain codes or customer data.
			throw new \RuntimeException( 'License database operation failed.' );
		}
		return $result;
	}

	public static function event( $event, $code_id = 0, $order_id = 0, $detail = '' ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::audit_table(),
			array(
				'event'      => $event,
				'code_id'    => $code_id,
				'order_id'   => $order_id,
				'actor_id'   => get_current_user_id(),
				'detail'     => $detail,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'License audit write failed.' );
		}
		if ( $code_id ) {
			$product_id                          = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT product_id FROM %i WHERE id=%d', self::table(), $code_id ) );
			self::$dirty_products[ $product_id ] = true;
		}
	}

	/** Persist a catalog mirror; code pool remains the authoritative checkout stock. */
	public static function sync_stock() {
		$ids                  = array_keys( self::$dirty_products );
		self::$dirty_products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! self::enabled( $product ) ) {
				continue;
			}
			$count = self::available( $id );
			$product->set_manage_stock( false );
			$product->set_stock_quantity( $count );
			$product->set_stock_status( $count ? 'instock' : 'outofstock' );
			$product->set_backorders( 'no' );
			$product->save();
			wc_delete_product_transients( $id );
			if ( $product->is_type( 'variation' ) ) {
				\WC_Product_Variable::sync_stock_status( $product->get_parent_id() );
				wc_delete_product_transients( $product->get_parent_id() );
			}
		}
	}

	public static function available( $product_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE product_id=%d AND state='available'", self::table(), $product_id ) );
	}

	public static function enabled( $product ) {
		return $product && 'yes' === $product->get_meta( '_yhlm_enabled', true );
	}

	public static function validate_product( $id ) {
		$product = wc_get_product( $id );
		if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) || ! self::enabled( $product ) ) {
			throw new \InvalidArgumentException( 'Product must be an enabled simple product or variation: ' . absint( $id ) );
		}
		return $product;
	}

	public static function preview( $rows ) {
		global $wpdb;
		$result = array(
			'new'        => 0,
			'duplicates' => 0,
			'products'   => array(),
		);
		$seen   = array();
		foreach ( $rows as $row ) {
			self::validate_product( $row['product_id'] );
			$hash          = Crypto::fingerprint( $row['code'] );
			$exists        = isset( $seen[ $hash ] ) || $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE code_hash=%s', self::table(), $hash ) );
			$seen[ $hash ] = true;
			if ( $exists ) {
				++$result['duplicates'];
			} else {
				++$result['new'];
				$id                        = $row['product_id'];
				$result['products'][ $id ] = ( $result['products'][ $id ] ?? 0 ) + 1;
			}
		}
		return $result;
	}

	public static function import( $rows ) {
		return self::transaction(
			static function () use ( $rows ) {
				global $wpdb;
				$preview  = self::preview( $rows );
				$inserted = 0;
				foreach ( $rows as $row ) {
					$hash = Crypto::fingerprint( $row['code'] );
					if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE code_hash=%s', self::table(), $hash ) ) ) {
						continue;
					}
					$ok = $wpdb->insert(
						self::table(),
						array(
							'product_id'  => $row['product_id'],
							'code_cipher' => Crypto::encrypt( $row['code'] ),
							'code_hash'   => $hash,
							'batch'       => $row['batch'],
							'created_at'  => gmdate( 'Y-m-d H:i:s' ),
						)
					);
					if ( false === $ok ) {
						throw new \RuntimeException( 'License import failed.' );
					}
					self::event( 'imported', $wpdb->insert_id );
					++$inserted;
				}
				$preview['new'] = $inserted;
				return $preview;
			}
		);
	}

	public static function order_codes( $order_id, $delivered_only = true ) {
		global $wpdb;
		if ( $delivered_only ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE order_id=%d AND state='delivered' ORDER BY item_id,slot,id", self::table(), $order_id ), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id=%d ORDER BY item_id,slot,id', self::table(), $order_id ), ARRAY_A );
	}

	public static function block( $id ) {
		self::transaction(
			static function () use ( $id ) {
				global $wpdb;
				$changed = self::query( $wpdb->prepare( "UPDATE %i SET state='blocked' WHERE id=%d AND state='available'", self::table(), $id ) );
				if ( 1 !== $changed ) {
					throw new \RuntimeException( 'Only available codes can be blocked.' );
				}
				self::event( 'blocked', $id );
			}
		);
	}
}
