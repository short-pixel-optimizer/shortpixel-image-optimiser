<?php
/**
 * Tests for ShortPixel\NextGenController.
 *
 * Focus areas:
 *   - Singleton contract (getInstance)
 *   - has_nextgen — reflects the NGG_PLUGIN constant state
 *   - optimizeNextGen — dual gate (override flag OR includeNextGen setting)
 *   - isNextGenScreen — passthrough getter
 *   - add_screen_loads — precise match (`ngg` property) vs substring matches
 *     vs no-match, plus the sticky `$is_ngg_screen` flag side-effect
 *   - checkAddFiles — three-branch NextGen-folder + optimize-setting gate
 *   - Regression sentinel — onDeleteImage's array_merge($paths, string)
 *     TypeError trap (see project_deferred_root_bugs.md)
 *
 * Skipped at the unit level (integration territory — need real NextGen
 * classes, the shortpixel_folders DB table, or OtherMediaController state):
 *   - hooks()                        → runtime hook wiring
 *   - checkCurrentScreen             → hook registration side-effect
 *   - loadNextGenItem                → renders a view via NextGenViewController
 *   - loadFolder                     → queries ngg_gallery + folder mutation
 *   - refreshFolderOnLoad            → getGalleries + folder refresh
 *   - getGalleries                   → ngg_gallery query + FS walk
 *   - addNextGenGalleriesToCustom    → mutates OtherMediaController + folders table
 *   - handleImageUpload              → needs a NextGen image entity
 *   - resetNotification              → trivial single Notice call
 *   - enableNextGen                  → calls addNextGenGalleriesToCustom (heavy path)
 *   - getNGImageByID / getImageAbspath / getImageSizes → depend on NextGen's
 *     legacy `\C_*` or modern `\Imagely\NGG\*` classes existing
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\NextGenController;

class NextGenControllerTest extends WP_UnitTestCase {

	/** @var mixed */
	private $savedIncludeNextGen;

	public function set_up() {
		parent::set_up();
		// Save the current setting so per-test mutations don't leak.
		$this->savedIncludeNextGen = \wpSPIO()->settings()->includeNextGen;
	}

	public function tear_down() {
		\wpSPIO()->settings()->includeNextGen = $this->savedIncludeNextGen;
		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function getPrivate( NextGenController $c, string $prop ) {
		$ref = new ReflectionClass( NextGenController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $c );
	}

	private function setPrivate( NextGenController $c, string $prop, $value ): void {
		$ref = new ReflectionClass( NextGenController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $c, $value );
	}

	/**
	 * Build a fresh controller without running the constructor (which
	 * would register the plugins_loaded + shortpixel/init/optimize_on_screens
	 * hooks a second time on top of the file-load singleton's).
	 */
	private function freshController(): NextGenController {
		$ref = new ReflectionClass( NextGenController::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/*
	 * getInstance — singleton contract
	 */

	public function test_getInstance_returns_a_NextGenController() {
		$this->assertInstanceOf( NextGenController::class, NextGenController::getInstance() );
	}

	public function test_getInstance_returns_the_same_instance_on_repeated_calls() {
		$a = NextGenController::getInstance();
		$b = NextGenController::getInstance();

		$this->assertSame( $a, $b );
	}

	/*
	 * has_nextgen — mirrors the NGG_PLUGIN constant
	 */

	public function test_has_nextgen_reflects_the_NGG_PLUGIN_constant_state() {
		$expected = defined( 'NGG_PLUGIN' );
		$this->assertSame( $expected, $this->freshController()->has_nextgen() );
	}

	/*
	 * optimizeNextGen — override flag OR settings.includeNextGen
	 */

	public function test_optimizeNextGen_returns_false_when_neither_override_nor_setting_is_on() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'enableOverride', false );
		\wpSPIO()->settings()->includeNextGen = 0;

		$this->assertFalse( $c->optimizeNextGen() );
	}

	public function test_optimizeNextGen_returns_true_when_enableOverride_is_true() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'enableOverride', true );
		\wpSPIO()->settings()->includeNextGen = 0;

		$this->assertTrue( $c->optimizeNextGen() );
	}

	public function test_optimizeNextGen_returns_true_when_the_setting_is_on() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'enableOverride', false );
		\wpSPIO()->settings()->includeNextGen = 1;

		$this->assertTrue( $c->optimizeNextGen() );
	}

	/*
	 * isNextGenScreen — passthrough getter for the sticky flag
	 */

	public function test_isNextGenScreen_returns_the_private_flag_value() {
		$c = $this->freshController();

		$this->setPrivate( $c, 'is_ngg_screen', false );
		$this->assertFalse( $c->isNextGenScreen() );

		$this->setPrivate( $c, 'is_ngg_screen', true );
		$this->assertTrue( $c->isNextGenScreen() );
	}

	/*
	 * add_screen_loads — precise match (screen has `ngg` property),
	 * substring match, or no match. Assert BOTH the return value AND
	 * the sticky $is_ngg_screen side-effect for each case.
	 */

	public function test_add_screen_loads_appends_the_screen_id_when_screen_has_an_ngg_property() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'is_ngg_screen', false );

		$screen      = new \stdClass();
		$screen->id  = 'some-nggish-screen';
		$screen->ngg = true; // precise-match trigger

		$result = $c->add_screen_loads( array(), $screen );

		$this->assertSame( array( 'some-nggish-screen' ), $result );
		// Sentinel: assert the side-effect too — the sticky flag is
		// what isNextGenScreen() consumes downstream.
		$this->assertTrue( $this->getPrivate( $c, 'is_ngg_screen' ) );
	}

	public function test_add_screen_loads_appends_when_screen_id_contains_ngg_substring() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'is_ngg_screen', false );

		$screen     = new \stdClass();
		// 'ngg' substring but NOT 'nggallery' — avoids the known
		// "double-append when id matches multiple patterns" quirk.
		$screen->id = 'toplevel_page_ngg';

		$result = $c->add_screen_loads( array(), $screen );

		$this->assertSame( array( 'toplevel_page_ngg' ), $result );
		$this->assertTrue( $this->getPrivate( $c, 'is_ngg_screen' ) );
	}

	public function test_add_screen_loads_appends_when_screen_id_contains_nextgen_gallery_substring() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'is_ngg_screen', false );

		$screen     = new \stdClass();
		$screen->id = 'nextgen-gallery-page';

		$result = $c->add_screen_loads( array(), $screen );

		$this->assertSame( array( 'nextgen-gallery-page' ), $result );
		$this->assertTrue( $this->getPrivate( $c, 'is_ngg_screen' ) );
	}

	public function test_add_screen_loads_leaves_use_screens_unchanged_when_screen_does_not_match() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'is_ngg_screen', false );

		$screen     = new \stdClass();
		$screen->id = 'edit-post';

		$existing = array( 'upload', 'edit-attachment' );
		$result   = $c->add_screen_loads( $existing, $screen );

		$this->assertSame( $existing, $result );
		// Sentinel: no match ⇒ flag must stay false. Otherwise the
		// downstream isNextGenScreen() consumer would go wrong.
		$this->assertFalse( $this->getPrivate( $c, 'is_ngg_screen' ) );
	}

	/*
	 * checkAddFiles — three branches: non-NG folder / NG folder with
	 * setting off / NG folder with setting on
	 */

	public function test_checkAddFiles_returns_the_incoming_bool_untouched_for_non_nextgen_folders() {
		$c = $this->freshController();

		$dirObj = $this->makeDirStub( false ); // is_nextgen=false

		// Assert BOTH bool variants — sentinel against a regression that
		// forces one specific return regardless of input.
		$this->assertTrue( $c->checkAddFiles( true, array(), $dirObj ) );
		$this->assertFalse( $c->checkAddFiles( false, array(), $dirObj ) );
	}

	public function test_checkAddFiles_returns_false_for_nextgen_folders_when_optimize_setting_is_off() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'enableOverride', false );
		\wpSPIO()->settings()->includeNextGen = 0;

		$dirObj = $this->makeDirStub( true );

		// Even when the caller passes true, an NG folder with the setting
		// off must return false.
		$this->assertFalse( $c->checkAddFiles( true, array(), $dirObj ) );
	}

	public function test_checkAddFiles_returns_the_incoming_bool_for_nextgen_folders_when_optimize_setting_is_on() {
		$c = $this->freshController();
		$this->setPrivate( $c, 'enableOverride', false );
		\wpSPIO()->settings()->includeNextGen = 1;

		$dirObj = $this->makeDirStub( true );

		$this->assertTrue( $c->checkAddFiles( true, array(), $dirObj ) );
		$this->assertFalse( $c->checkAddFiles( false, array(), $dirObj ) );
	}

	/**
	 * Build a directory-object stub with a `get()` method that returns
	 * whatever was scripted for the given field name. Only `is_nextgen`
	 * is actually read by checkAddFiles.
	 */
	private function makeDirStub( bool $is_nextgen ) {
		return new class( $is_nextgen ) {
			private $is_nextgen;
			public function __construct( $is_nextgen ) {
				$this->is_nextgen = $is_nextgen;
			}
			public function get( $name ) {
				if ( $name === 'is_nextgen' ) return $this->is_nextgen;
				return null;
			}
		};
	}

	/*
	 * onDeleteImage — regression sentinel guarding the PHP 8
	 * array_merge($paths, string) TypeError fix.
	 */

	/**
	 * Regression sentinel: onDeleteImage must not raise a TypeError
	 * when $size is a specific string (e.g. 'thumbnail').
	 *
	 * Before the fix, onDeleteImage's else-branch called
	 * `array_merge($paths, $this->getImageAbspath($image, $size))` —
	 * but getImageAbspath returns a string, and PHP 8's array_merge
	 * requires arrays. Fix: `$paths[] = ...` (append instead of merge).
	 * See project_deferred_root_bugs.md.
	 */
	public function test_onDeleteImage_does_not_fatal_when_size_is_a_specific_string() {
		$c = $this->makeTestableController();

		// Downstream: onDeleteImage → OtherMediaController->getCustomImageByPath
		// → CustomImageModel->setStub, which SELECTs from `shortpixel_meta`.
		// This test file doesn't seed that table (checkTables() in every set_up
		// would slow the other 15 tests in this file that don't touch the DB),
		// so wpdb emits "Table doesn't exist" errors as pure noise. Suppress
		// them so the test output stays scannable — the assertion we care
		// about is the TypeError guard below.
		global $wpdb;
		$prevSuppress = $wpdb->suppress_errors( true );
		$prevShow     = $wpdb->hide_errors();
		ob_start();

		// Sentinel: only TypeError counts as failure. Downstream
		// exceptions from OtherMediaController::getInstance()->
		// getCustomImageByPath() are integration territory and not
		// what this test is pinning.
		try {
			$c->onDeleteImage( 1, 'thumbnail' );
		} catch ( \TypeError $e ) {
			ob_end_clean();
			$wpdb->suppress_errors( $prevSuppress );
			if ( $prevShow ) { $wpdb->show_errors(); }
			$this->fail(
				'onDeleteImage raised TypeError — regression of the array_merge($paths, string) bug. ' .
				'Message: ' . $e->getMessage()
			);
		} catch ( \Throwable $t ) {
			// Non-TypeError — probably from the OtherMediaController
			// path further down. Not this test's concern.
		} finally {
			ob_end_clean();
			$wpdb->suppress_errors( $prevSuppress );
			if ( $prevShow ) { $wpdb->show_errors(); }
		}

		// Explicit assertion so PHPUnit doesn't mark the test risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Build a NextGenController subclass that stubs the three NextGen-API
	 * adapters so onDeleteImage can run to completion without needing
	 * the `\C_*` or `\Imagely\NGG\*` classes to exist.
	 */
	private function makeTestableController(): NextGenController {
		return new class extends NextGenController {
			// Skip the constructor so we don't re-register hooks.
			public function __construct() {}

			protected function getNGImageByID( $nggId ) {
				return (object) array( 'id' => $nggId );
			}

			// Bas added a `: string` return type to the parent method in 399b29e2
			// (P1 fix for onDeleteImage array_merge TypeError). The override
			// signature must stay compatible or PHP fatals with
			// "Declaration ... must be compatible with ..." at load time.
			protected function getImageAbspath( $image, $size = 'full' ): string {
				return '/tmp/spio-test-nextgen-file.jpg';
			}

			protected function getImageSizes( $image ) {
				return array( 'full', 'thumbnail' );
			}
		};
	}
}
