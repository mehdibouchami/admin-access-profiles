<?php
/**
 * Small helpers: wildcard matching and admin menu slug parsing.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Util {

	private static $regex_cache = array();

	/** Case-insensitive wildcard match ("*" = any characters). */
	public static function matches( $value, array $patterns ) {
		$value = strtolower( (string) $value );
		foreach ( $patterns as $pattern ) {
			if ( ! isset( self::$regex_cache[ $pattern ] ) ) {
				self::$regex_cache[ $pattern ] = '/^' . str_replace( '\*', '.*', preg_quote( strtolower( $pattern ), '/' ) ) . '$/';
			}
			if ( preg_match( self::$regex_cache[ $pattern ], $value ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when any of the regular expressions matches. */
	public static function matches_regex( $value, array $regexes ) {
		foreach ( $regexes as $regex ) {
			if ( preg_match( $regex, (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	/** "page" id a menu slug opens, or '' for core .php screens. */
	public static function page_id_from_slug( $slug ) {
		$slug = html_entity_decode( (string) $slug );
		$args = self::slug_args( $slug );
		if ( isset( $args['page'] ) && is_string( $args['page'] ) ) {
			return $args['page'];
		}
		if ( false !== strpos( $slug, '.php' ) || preg_match( '#^https?://#i', $slug ) ) {
			return '';
		}
		$amp = strpos( $slug, '&' );
		return false === $amp ? $slug : substr( $slug, 0, $amp );
	}

	/** Core file a menu slug points to ("edit.php"), or ''. */
	public static function slug_file( $slug ) {
		$path = (string) wp_parse_url( html_entity_decode( (string) $slug ), PHP_URL_PATH );
		return '.php' === substr( $path, -4 ) ? basename( $path ) : '';
	}

	public static function slug_args( $slug ) {
		$slug  = html_entity_decode( (string) $slug );
		$query = wp_parse_url( $slug, PHP_URL_QUERY );
		if ( null === $query && false === strpos( $slug, '.php' ) && false !== strpos( $slug, '&' ) ) {
			$query = substr( $slug, strpos( $slug, '&' ) + 1 ); // "wc-admin&path=/analytics/revenue".
		}
		$args = array();
		if ( $query ) {
			parse_str( $query, $args );
		}
		return $args;
	}

	public static function plugin_folder( $plugin_file ) {
		return false !== strpos( $plugin_file, '/' ) ? dirname( $plugin_file ) : basename( $plugin_file, '.php' );
	}

	public static function request_value( $key ) {
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : '';
	}
}
