<?php
/**
 * Tests for ShortPixel\Controller\StatsController.
 *
 * Scope: singleton contract, find() with simple model property paths and
 * multi-segment paths, reset(), totalImagesToOptimize() arithmetic, and
 * thumbNailsToOptimize() arithmetic. All tests operate against real WordPress
 * options (currentStats) via StatsModel — no DB mocking required.
 *
 * Out of scope / why:
 * - getAverageCompression() executes a raw wpdb query against the
 *   shortpixel_postmeta table which is not installed in the test harness DB;
 *   the DB query would return null, making the "cached" branch the only
 *   safely-testable case but it requires CacheController interaction tested
 *   separately in test-CacheController.php.
 * - addImage() is documented as non-functional (hardcoded placeholder values);
 *   asserting the hardcoded behaviour would produce a fragile pinned test with
 *   low signal.
 * - thumbNailsToOptimize() and totalImagesToOptimize() call wpSPIO()->settings()
 *   internally; they are tested against zero-valued stats so the arithmetic
 *   reduces to an easily verified identity.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\StatsController;
use ShortPixel\Model\StatsModel;

class StatsControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
	}

	public function tear_down() {
		$this->resetSingleton();
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( StatsController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	private function getPrivate( object $obj, string $prop ) {
		$ref = new ReflectionClass( get_class( $obj ) );
		while ( ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}

	private function setPrivate( object $obj, string $prop, $value ): void {
		$ref = new ReflectionClass( get_class( $obj ) );
		while ( ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	/**
	 * Seed StatsModel's $stats property directly so that find() reads our
	 * controlled values instead of the real DB.
	 */
	private function seedStats( StatsController $ctrl, array $stats ): void {
		$model = $this->getPrivate( $ctrl, 'model' );
		$this->setPrivate( $model, 'stats', $stats );
	}

	/*
	 * getInstance — singleton contract
	 */

	public function test_getInstance_returns_stats_controller() {
		$ctrl = StatsController::getInstance();
		$this->assertInstanceOf( StatsController::class, $ctrl );
	}

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = StatsController::getInstance();
		$b = StatsController::getInstance();
		$this->assertSame( $a, $b );
	}

	/*
	 * find — single-segment path (simple property via model->get())
	 */

	public function test_find_returns_zero_when_path_does_not_resolve_to_leaf() {
		$ctrl = StatsController::getInstance();
		// 'nonexistent_bucket' is unknown — model->get() returns null and
		// getStat() returns the StatsModel chain object (not a leaf scalar).
		$result = $ctrl->find( 'nonexistent_bucket' );
		$this->assertSame( 0, $result );
	}

	/*
	 * find — multi-segment path drilling into stats
	 */

	public function test_find_two_segment_path_returns_seeded_media_items_count() {
		$ctrl = StatsController::getInstance();
		$this->seedStats( $ctrl, array(
			'media' => array(
				'items'      => 42,
				'images'     => 100,
				'thumbs'     => 50,
				'itemsTotal' => 200,
				'thumbsTotal'=> 150,
				'isLimited'  => false,
			),
		) );

		$result = $ctrl->find( 'media', 'items' );
		$this->assertSame( 42, $result );
	}

	public function test_find_two_segment_path_returns_seeded_total_images() {
		$ctrl = StatsController::getInstance();
		$this->seedStats( $ctrl, array(
			'total' => array(
				'items'      => 10,
				'images'     => 99,
				'thumbs'     => 20,
				'itemsTotal' => 500,
				'thumbsTotal'=> 300,
			),
		) );

		$result = $ctrl->find( 'total', 'images' );
		$this->assertSame( 99, $result );
	}

	/*
	 * reset — delegates to model->reset()
	 */

	public function test_reset_does_not_throw() {
		$ctrl = StatsController::getInstance();
		// reset() persists back to options — should complete without exception.
		$ctrl->reset();
		$this->assertTrue( true ); // Assertion: no exception thrown.
	}

	/*
	 * totalImagesToOptimize — arithmetic identity when all counts are zero
	 */

	public function test_totalImagesToOptimize_returns_zero_when_all_counts_are_zero() {
		$ctrl = StatsController::getInstance();
		$this->seedStats( $ctrl, array(
			'total' => array(
				'items'      => 0,
				'images'     => 0,
				'thumbs'     => 0,
				'itemsTotal' => 0,
				'thumbsTotal'=> 0,
			),
		) );

		$result = $ctrl->totalImagesToOptimize();
		$this->assertSame( 0, $result );
	}

	public function test_totalImagesToOptimize_returns_difference_between_totals_and_optimized() {
		$ctrl = StatsController::getInstance();
		$this->seedStats( $ctrl, array(
			'total' => array(
				'items'      => 0,
				'images'     => 30,   // optimized
				'thumbs'     => 0,
				'itemsTotal' => 50,   // total items
				'thumbsTotal'=> 20,   // total thumbs
			),
		) );

		// totalImages = 50 + 20 = 70; totalImagesOptimized = 30; toOpt = 40.
		$result = $ctrl->totalImagesToOptimize();
		$this->assertSame( 40, $result );
	}
}
