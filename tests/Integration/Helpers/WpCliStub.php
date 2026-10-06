<?php
/**
 * Minimal WP-CLI runtime stub for integration-testing SPIO's CLI commands.
 *
 * The real wp-cli package is not part of the WP test harness, but SPIO's
 * command classes (WpCliController, SpioCommandBase, SpioSingle, SpioBulk in
 * class/external/wp-cli/) are ALWAYS loaded — they sit in the autoloader's
 * "files" list, not behind the WP_CLI constant. So the command methods can be
 * invoked directly once a \WP_CLI class and \WP_CLI\Utils\format_items()
 * exist. This file provides both, recording all output for assertions.
 *
 * Faithfulness notes:
 *   - Static method calls are case-insensitive in PHP, so the plugin's mixed
 *     \WP_CLI::Error / ::error / ::Line / ::line spellings all land here.
 *   - error() with $exit !== false throws WP_CLI_Stub_ExitException, matching
 *     real WP-CLI's exit-on-error semantics — several SPIO commands RELY on
 *     error() not returning (e.g. add() would otherwise pass `false` to
 *     addItemtoQueue()).
 *   - The WP_CLI CONSTANT is deliberately never defined: the plugin's boot
 *     path (shortpixel-plugin.php:163) must keep seeing a non-CLI request.
 *
 * @package Shortpixel_Image_Optimiser
 */

namespace {

	class WP_CLI_Stub_ExitException extends \Exception {}

	class WP_CLI {

		/** @var array<string, mixed> Commands registered via add_command(). */
		public static $commands = array();

		/** @var array<int, array{0:string, 1:string}> Recorded output as [type, text] pairs. */
		public static $output = array();

		/** @var array<int, array{items:array, fields:array}> Tables rendered via format_items(). */
		public static $tables = array();

		public static function reset() {
			self::$commands = array();
			self::$output   = array();
			self::$tables   = array();
		}

		public static function add_command( $name, $callable ) {
			self::$commands[ $name ] = $callable;
		}

		public static function line( $message = '' ) {
			self::$output[] = array( 'line', (string) $message );
		}

		public static function log( $message ) {
			self::$output[] = array( 'log', (string) $message );
		}

		public static function success( $message ) {
			self::$output[] = array( 'success', (string) $message );
		}

		public static function warning( $message ) {
			self::$output[] = array( 'warning', (string) $message );
		}

		public static function error( $message, $exit = true ) {
			self::$output[] = array( 'error', (string) $message );
			if ( false !== $exit ) {
				throw new WP_CLI_Stub_ExitException( (string) $message );
			}
		}

		public static function colorize( $string ) {
			return $string;
		}

		public static function halt( $code ) {
			throw new WP_CLI_Stub_ExitException( 'halt: ' . $code );
		}

		// ---- assertion helpers (not part of the real WP-CLI surface) ----

		/** All recorded output joined to one haystack string. */
		public static function allText(): string {
			$lines = array();
			foreach ( self::$output as $entry ) {
				$lines[] = $entry[1];
			}
			return implode( "\n", $lines );
		}

		/** Messages of one type ('line', 'log', 'success', 'warning', 'error'). */
		public static function messagesOfType( string $type ): array {
			$found = array();
			foreach ( self::$output as $entry ) {
				if ( $entry[0] === $type ) {
					$found[] = $entry[1];
				}
			}
			return $found;
		}
	}
}

namespace WP_CLI\Utils {

	function format_items( $format, $items, $fields ) {
		\WP_CLI::$tables[] = array(
			'items'  => $items,
			'fields' => $fields,
		);
		\WP_CLI::$output[] = array( 'table', \wp_json_encode( $items ) );
	}
}
