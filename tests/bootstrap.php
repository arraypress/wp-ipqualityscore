<?php
/**
 * Test bootstrap.
 *
 * The transport is the seam: a test says what the API returns, and the
 * assertions are about what the library does with it.
 *
 * @package ArrayPress\IPQualityScore
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * What the next HTTP call returns, what has been asked, and what is cached.
 *
 * @var array
 */
$GLOBALS["iqs"] = [
	'response' => null,
	'requests' => [],
	'options'  => [],
];

/**
 * Reset everything a test set up.
 */
function iqs_reset(): void {
	$GLOBALS["iqs"] = [
		'response' => null,
		'requests' => [],
		'options'  => [],
	];
}

/**
 * Queue the response the next request receives.
 *
 * @param mixed $response A WP_Error, or an array with 'code' and 'body'.
 */
function iqs_will_return( $response ): void {
	$GLOBALS["iqs"]['response'] = $response;
}

/**
 * A successful IPQualityScore body.
 *
 * @param array $data Response fields.
 *
 * @return array
 */
function iqs_ok( array $data = [] ): array {
	return [ 'code' => 200, 'body' => (string) json_encode( $data ) ];
}

/**
 * How many requests have been made.
 *
 * @return int
 */
function iqs_request_count(): int {
	return count( $GLOBALS["iqs"]['requests'] );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		public string $code;
		public string $message;
		public array $data;

		public function __construct( string $code = '', string $message = '', $data = [] ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = (array) $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ) {
		$GLOBALS["iqs"]['requests'][] = $url;

		return $GLOBALS["iqs"]['response'] ?? [ 'code' => 200, 'body' => '{}' ];
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = [] ) {
		$GLOBALS["iqs"]['requests'][] = $url;

		return $GLOBALS["iqs"]['response'] ?? [ 'code' => 200, 'body' => '{}' ];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['code'] ?? 200 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		$options = &$GLOBALS["iqs"]['options'];

		if ( ! array_key_exists( '_transient_' . $key, $options ) ) {
			return false;
		}

		$timeout = $options[ '_transient_timeout_' . $key ] ?? 0;

		if ( $timeout && $timeout < time() ) {
			unset( $options[ '_transient_' . $key ], $options[ '_transient_timeout_' . $key ] );

			return false;
		}

		return $options[ '_transient_' . $key ];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		$GLOBALS["iqs"]['options'][ '_transient_' . $key ] = $value;

		if ( $expiration ) {
			$GLOBALS["iqs"]['options'][ '_transient_timeout_' . $key ] = time() + $expiration;
		}

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		// Core removes both rows. A stub that removes one cannot show the
		// orphaned-timeout bug that clear_cache() used to leave behind.
		unset(
			$GLOBALS["iqs"]['options'][ '_transient_' . $key ],
			$GLOBALS["iqs"]['options'][ '_transient_timeout_' . $key ]
		);

		return true;
	}
}

if ( ! class_exists( 'IQS_Test_wpdb' ) ) {
	/**
	 * Enough of $wpdb for clear_cache().
	 *
	 * LIKE is implemented properly rather than with str_starts_with: in SQL an
	 * unescaped `_` matches any single character, so a prefix built without
	 * esc_like() silently matches more rows than intended. A stub that ignores
	 * that cannot catch the bug it exists to catch.
	 */
	class IQS_Test_wpdb {

		/**
		 * @var string
		 */
		public string $options = 'wp_options';

		/**
		 * Escape LIKE wildcards.
		 *
		 * @param string $text Text to escape.
		 *
		 * @return string
		 */
		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		/**
		 * Interpolate a prepared statement.
		 *
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Values.
		 *
		 * @return string
		 */
		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace(
					'/%[sd]/',
					is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'",
					$query,
					1
				);
			}

			return $query;
		}

		/**
		 * Run the one SELECT clear_cache() issues.
		 *
		 * @param string $query The interpolated query.
		 *
		 * @return array
		 */
		public function get_col( string $query ): array {
			if ( ! preg_match( "/option_name LIKE '(.*)'\s*$/", trim( $query ), $m ) ) {
				return [];
			}

			$pattern = str_replace( "''", "'", $m[1] );

			return array_values(
				array_filter(
					array_keys( $GLOBALS["iqs"]['options'] ),
					static fn( $name ) => (bool) preg_match( iqs_like_to_regex( $pattern ), $name )
				)
			);
		}
	}
}

if ( ! function_exists( 'iqs_like_to_regex' ) ) {
	/**
	 * Translate a SQL LIKE pattern into a regex, honouring backslash escapes.
	 *
	 * @param string $pattern The LIKE pattern.
	 *
	 * @return string A PCRE pattern.
	 */
	function iqs_like_to_regex( string $pattern ): string {
		$out    = '';
		$length = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];

			if ( '\\' === $char && $i + 1 < $length ) {
				$out .= preg_quote( $pattern[ ++$i ], '#' );
				continue;
			}

			$out .= match ( $char ) {
				'%'     => '.*',
				'_'     => '.',
				default => preg_quote( $char, '#' ),
			};
		}

		return '#^' . $out . '$#';
	}
}

$GLOBALS['wpdb'] = new IQS_Test_wpdb();
