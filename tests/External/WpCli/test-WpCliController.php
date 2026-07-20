<?php
/**
 * Tests for ShortPixel\WpCliController — the WP-CLI bootstrap that
 * wires the plugin's two command groups (`wp spio` / `wp spio bulk`)
 * into WP-CLI's dispatcher.
 *
 * Focus areas:
 *   - Singleton contract (getInstance)
 *   - initCommands() registers exactly the two expected command groups,
 *     bound to the right handler classes
 *
 * Skipped at the unit level (integration territory):
 *   - Logger-path branching in __construct — only fires when SPIO
 *     debug mode is active, and setLogPath writes to a real file.
 *     Better exercised by an end-to-end run under `wp spio ...`.
 *
 * WP-CLI stub:
 *   The real `WP_CLI` class ships with the wp-cli phar and isn't
 *   present in the WordPress unit-test harness. We define a minimal
 *   stub here so `initCommands()` can call `add_command()` and we can
 *   introspect the calls. The stub is defined once (guarded by
 *   class_exists) so it survives across all tests in the process.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\WpCliController;

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static $commands = array();

		public static function add_command( $name, $class ) {
			self::$commands[ $name ] = $class;
		}

		public static function reset_stub() {
			self::$commands = array();
		}
	}
}

class WpCliControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Reset the singleton so getInstance() actually constructs a
		// fresh instance per test — otherwise state from earlier tests
		// (including the initCommands() calls) would leak.
		$ref = new ReflectionClass( WpCliController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );

		WP_CLI::reset_stub();
	}

	public function tear_down() {
		$ref = new ReflectionClass( WpCliController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );

		WP_CLI::reset_stub();

		parent::tear_down();
	}

	/*
	 * Singleton contract
	 */

	public function test_getInstance_returns_a_WpCliController() {
		$this->assertInstanceOf( WpCliController::class, WpCliController::getInstance() );
	}

	public function test_getInstance_returns_the_same_instance_on_repeated_calls() {
		$a = WpCliController::getInstance();
		$b = WpCliController::getInstance();

		// Sentinel: assertSame (identity, not equality) — a regression
		// that constructed a fresh instance on every call would still
		// pass assertEquals but fail here.
		$this->assertSame( $a, $b );
	}

	/*
	 * initCommands() — command registration
	 */

	public function test_constructor_registers_the_spio_and_spio_bulk_command_groups() {
		WpCliController::getInstance();

		$this->assertArrayHasKey( 'spio', WP_CLI::$commands );
		$this->assertArrayHasKey( 'spio bulk', WP_CLI::$commands );
	}

	public function test_constructor_binds_spio_to_SpioSingle_and_spio_bulk_to_SpioBulk() {
		WpCliController::getInstance();

		// Sentinel: the handler-class binding is the reason `wp spio
		// restore …` reaches SpioSingle::restore rather than SpioBulk
		// (which lacks that method). A swap here would break the CLI
		// dispatch chain in a way that only shows up at runtime.
		$this->assertSame( '\ShortPixel\SpioSingle', WP_CLI::$commands['spio'] );
		$this->assertSame( '\ShortPixel\SpioBulk', WP_CLI::$commands['spio bulk'] );
	}

	public function test_constructor_registers_exactly_two_command_groups_and_no_more() {
		WpCliController::getInstance();

		// Sentinel: catches accidental additions (e.g. an experimental
		// `spio ai` group added and forgotten) that would silently
		// broaden the CLI surface without a docblock update.
		$this->assertCount( 2, WP_CLI::$commands );
	}
}
