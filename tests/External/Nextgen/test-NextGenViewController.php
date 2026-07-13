<?php
/**
 * Tests for ShortPixel\NextGenViewController.
 *
 * Focus areas:
 *   - hooks() — intentionally-empty no-op contract
 *   - nggColumns — records the static column index AND returns
 *     defaults unchanged (dual side-effect / return assertion)
 *   - nggCountColumns — trivial +1 count
 *   - nggColumnHeader — localised label + dashicons style enqueue
 *
 * Skipped at the unit level (integration territory):
 *   - loadComparer / loadItem — render views via the parent
 *     ViewController::loadView(), which requires the real template
 *     files + partials and (for loadItem) OtherMediaController state
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\NextGenViewController;

class NextGenViewControllerTest extends WP_UnitTestCase {

	public function tear_down() {
		// nggColumnHeader() enqueues dashicons — dequeue after each
		// test so the enqueued flag doesn't leak into subsequent tests.
		wp_dequeue_style( 'dashicons' );

		// Reset the static column index so state doesn't leak.
		$ref = new ReflectionClass( NextGenViewController::class );
		$p   = $ref->getProperty( 'nggColumnIndex' );
		$p->setAccessible( true );
		$p->setValue( null, 0 );

		parent::tear_down();
	}

	private function getStatic( string $prop ) {
		$ref = new ReflectionClass( NextGenViewController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( null );
	}

	private function invokePrivate( NextGenViewController $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( NextGenViewController::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $c, ...$args );
	}

	/**
	 * Build a fresh view controller without running the parent
	 * ViewController::__construct (which does its own hook wiring).
	 */
	private function freshViewController(): NextGenViewController {
		$ref = new ReflectionClass( NextGenViewController::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/*
	 * hooks() — intentionally empty; verify it doesn't throw or return
	 * anything unexpected.
	 */

	public function test_hooks_is_a_noop_returning_void() {
		$c      = $this->freshViewController();
		$result = $this->invokePrivate( $c, 'hooks' );

		$this->assertNull( $result );
	}

	/*
	 * nggColumns — dual assertion: static side-effect + return value
	 */

	public function test_nggColumns_records_the_column_index_and_returns_defaults_unchanged() {
		$c        = $this->freshViewController();
		$defaults = array(
			'a' => 'A',
			'b' => 'B',
			'c' => 'C',
		);

		$result = $c->nggColumns( $defaults );

		// Sentinel: BOTH the return-value (passthrough) AND the static
		// side-effect (index = count + 1) must fire. Regressions in
		// either would silently break the column-registration handshake
		// with NextGen.
		$this->assertSame( $defaults, $result );
		$this->assertSame( 4, $this->getStatic( 'nggColumnIndex' ) );
	}

	/*
	 * nggCountColumns — trivial +1
	 */

	public function test_nggCountColumns_returns_count_plus_one() {
		$c = $this->freshViewController();

		$this->assertSame( 7, $c->nggCountColumns( 6 ) );
		// Sentinel: verify the transformation on a second, distinct
		// value so a hardcoded "return 7" regression is caught.
		$this->assertSame( 1, $c->nggCountColumns( 0 ) );
	}

	/*
	 * nggColumnHeader — label + style-enqueue side-effect
	 */

	public function test_nggColumnHeader_returns_the_localised_ShortPixel_Compression_label() {
		$c = $this->freshViewController();

		$this->assertSame(
			'ShortPixel Compression',
			$c->nggColumnHeader( 'default-ignored' )
		);
	}

	public function test_nggColumnHeader_enqueues_the_dashicons_style() {
		// Sanity check: dashicons is registered by WP core but shouldn't
		// be enqueued yet in a clean test environment.
		$this->assertFalse(
			wp_style_is( 'dashicons', 'enqueued' ),
			'test setup issue: dashicons already enqueued before the call'
		);

		$c = $this->freshViewController();
		$c->nggColumnHeader( 'default-ignored' );

		$this->assertTrue( wp_style_is( 'dashicons', 'enqueued' ) );
	}
}
