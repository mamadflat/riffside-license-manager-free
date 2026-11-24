<?php
namespace YHLM;
defined( 'ABSPATH' ) || exit;

/** Pure parser: never uploads a license file to WordPress media. */
final class Import {
	public static function parse( $text, $product_id = 0, $batch = '' ) {
		if ( strlen( $text ) > 2 * 1024 * 1024 ) {
			throw new \InvalidArgumentException( 'Import exceeds 2 MB.' );
		}
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$rows = array();
		if ( $product_id ) {
			foreach ( explode( "\n", $text ) as $index => $code ) {
				if ( '' !== trim( $code ) ) {
					$rows[] = self::row( $product_id, $code, $batch, $index + 1 );
				}
			}
		} else {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory CSV stream, not a filesystem operation; WP_Filesystem cannot replace fgetcsv streams.
			$stream = fopen( 'php://temp', 'r+' );
			fwrite( $stream, $text );
			rewind( $stream );
			$header = fgetcsv( $stream, 0, ',', '"', '\\' );
			if ( array( 'product_id', 'code', 'batch' ) !== $header ) {
				fclose( $stream );
				throw new \InvalidArgumentException( 'CSV header must be product_id,code,batch.' );
			}
			$line = 1;
			while ( true ) {
				$cells = fgetcsv( $stream, 0, ',', '"', '\\' );
				if ( false === $cells ) {
					break;
				}
				++$line;
				if ( array( null ) === $cells ) {
					continue;
				}
				if ( 3 !== count( $cells ) || ! ctype_digit( trim( $cells[0] ) ) ) {
					fclose( $stream );
					throw new \InvalidArgumentException( 'Invalid CSV row: ' . (int) $line );
				}
				$rows[] = self::row( (int) $cells[0], $cells[1], $cells[2], $line );
			}
			fclose( $stream );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( ! $rows || count( $rows ) > 5000 ) {
			throw new \InvalidArgumentException( 'Import requires 1–5000 licenses.' );
		}
		return $rows;
	}

	private static function row( $product_id, $code, $batch, $line ) {
		$code  = trim( $code );
		$batch = trim( $batch );
		if ( $product_id < 1 || '' === $code || strlen( $code ) > 2048 || preg_match( '/[\x00-\x1F\x7F]/', $code ) || strlen( $batch ) > 100 ) {
			throw new \InvalidArgumentException( 'Invalid license data at row ' . (int) $line );
		}
		return array(
			'product_id' => (int) $product_id,
			'code'       => $code,
			'batch'      => $batch,
		);
	}
}
