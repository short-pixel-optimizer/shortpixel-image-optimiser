<?php
/**
 * Tests for ShortPixel\Model\StatsModel.
 *
 * Covers the pure-logic surface:
 *   - Constructor + load() branches (missing / non-array / legacy / fresh / stale)
 *   - save() / reset()
 *   - get() property accessor
 *   - The fluent chain (getStat + grab descent)
 *   - grab() leaf return when the value is already loaded (not the -1 sentinel)
 *   - checkInt coercion
 *
 * Skipped at the unit level (integration territory — hit real DB tables):
 *   - fetchStatData() and every countXxx / customItems method it dispatches to
 *   - grab() when the leaf is still the -1 sentinel (falls through to fetchStatData)
 *   - add() — deliberately non-functional per the class @todo
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\StatsModel;

class StatsModelTest extends WP_UnitTestCase {

	/** @var mixed */
	private $savedCurrentStats;

	public function set_up() {
		parent::set_up();
		$settings                = \wpSPIO()->settings();
		$this->savedCurrentStats = $settings->currentStats;
		$settings->deleteOption( 'currentStats' );
	}

	public function tear_down() {
		$settings = \wpSPIO()->settings();
		if ( is_array( $this->savedCurrentStats ) ) {
			$settings->currentStats = $this->savedCurrentStats;
		} else {
			$settings->deleteOption( 'currentStats' );
		}
		remove_all_filters( 'shortpixel/statistics/refresh' );
		parent::tear_down();
	}

	private function getPrivate( StatsModel $s, string $prop ) {
		$ref = new ReflectionClass( StatsModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $s );
	}

	private function setPrivate( StatsModel $s, string $prop, $value ): void {
		$ref = new ReflectionClass( StatsModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $s, $value );
	}

	private function invokePrivate( StatsModel $s, string $method, array $args = array() ) {
		$ref = new ReflectionClass( StatsModel::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $s, ...$args );
	}

	private function freshModel(): StatsModel {
		$ref = new ReflectionClass( StatsModel::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/*
	 * Constructor + refreshStatTime filter
	 */

	public function test_constructor_applies_default_week_long_refresh_ttl() {
		$s = new StatsModel();
		$this->assertSame( WEEK_IN_SECONDS, $this->getPrivate( $s, 'refreshStatTime' ) );
	}

	public function test_constructor_respects_shortpixel_statistics_refresh_filter() {
		add_filter( 'shortpixel/statistics/refresh', function () {
			return 3600;
		} );

		$s = new StatsModel();
		$this->assertSame( 3600, $this->getPrivate( $s, 'refreshStatTime' ) );
	}

	public function test_constructor_calls_load_so_stats_are_populated() {
		$s = new StatsModel();
		$this->assertIsArray( $this->getPrivate( $s, 'stats' ) );
	}

	/*
	 * load() branches
	 */

	public function test_load_falls_back_to_defaults_when_option_is_missing() {
		\wpSPIO()->settings()->deleteOption( 'currentStats' );

		$s = new StatsModel();
		$stats = $this->getPrivate( $s, 'stats' );
		$defaults = $this->getPrivate( $s, 'defaults' );

		$this->assertSame( $defaults, $stats );
	}

	public function test_load_falls_back_to_defaults_when_stored_value_is_not_an_array() {
		\wpSPIO()->settings()->currentStats = 'garbage-string';

		$s = new StatsModel();
		$stats = $this->getPrivate( $s, 'stats' );
		$defaults = $this->getPrivate( $s, 'defaults' );

		$this->assertSame( $defaults, $stats );
	}

	public function test_load_discards_legacy_pre_5_shape_containing_APIKeyValid() {
		\wpSPIO()->settings()->currentStats = array(
			'APIKeyValid' => true,
			'media' => array( 'items' => 999 ),
			'time' => time(),
		);

		$s = new StatsModel();
		$stats = $this->getPrivate( $s, 'stats' );
		$defaults = $this->getPrivate( $s, 'defaults' );

		// Should have been discarded — media.items should be back to the -1 sentinel.
		$this->assertSame( $defaults['media']['items'], $stats['media']['items'] );
	}

	public function test_load_uses_persisted_stats_when_fresh() {
		$now = time();
		\wpSPIO()->settings()->currentStats = array(
			'media' => array( 'items' => 123 ) + $this->baselineMediaBucket(),
			'time'  => $now,
		);

		$s = new StatsModel();
		$stats = $this->getPrivate( $s, 'stats' );

		$this->assertSame( 123, $stats['media']['items'] );
		$this->assertSame( $now, $this->getPrivate( $s, 'lastUpdate' ) );
	}

	public function test_load_falls_back_to_defaults_when_persisted_stats_are_stale() {
		\wpSPIO()->settings()->currentStats = array(
			'media' => array( 'items' => 123 ) + $this->baselineMediaBucket(),
			'time'  => time() - ( 2 * WEEK_IN_SECONDS ), // definitely stale
		);

		$s = new StatsModel();
		$stats = $this->getPrivate( $s, 'stats' );
		$defaults = $this->getPrivate( $s, 'defaults' );

		$this->assertSame( $defaults['media']['items'], $stats['media']['items'] );
	}

	/**
	 * A media bucket that is complete enough for load() to consider the
	 * stored value valid — every field the merge expects.
	 */
	private function baselineMediaBucket(): array {
		return array(
			'items'       => -1,
			'images'      => -1,
			'thumbs'      => -1,
			'itemsTotal'  => -1,
			'thumbsTotal' => -1,
			'isLimited'   => false,
		);
	}

	/*
	 * save() — persists to the currentStats setting with a time stamp
	 */

	public function test_save_writes_stats_plus_time_stamp_to_the_setting() {
		$s = new StatsModel();
		$this->setPrivate( $s, 'stats', array( 'media' => array( 'items' => 42 ) ) );

		$before = time();
		$s->save();
		$after  = time();

		$persisted = \wpSPIO()->settings()->currentStats;
		$this->assertSame( 42, $persisted['media']['items'] );
		$this->assertGreaterThanOrEqual( $before, $persisted['time'] );
		$this->assertLessThanOrEqual( $after, $persisted['time'] );
	}

	/*
	 * reset() — clears in-memory + deletes the persisted row
	 */

	public function test_reset_reverts_stats_to_defaults_and_deletes_the_option() {
		\wpSPIO()->settings()->currentStats = array(
			'media' => array( 'items' => 999 ) + $this->baselineMediaBucket(),
			'time'  => time(),
		);
		$s = new StatsModel();

		$s->reset();

		$stats = $this->getPrivate( $s, 'stats' );
		$defaults = $this->getPrivate( $s, 'defaults' );
		$this->assertSame( $defaults, $stats );

		// After reset(), the persisted row is gone — the setting falls back to its own default.
		// SettingsModel's currentStats default is an empty array.
		$this->assertSame( array(), \wpSPIO()->settings()->currentStats );
	}

	/*
	 * get() — property accessor
	 */

	public function test_get_returns_declared_property_value() {
		$s = new StatsModel();
		$this->assertSame( $this->getPrivate( $s, 'stats' ), $s->get( 'stats' ) );
	}

	public function test_get_returns_null_for_unknown_property() {
		$this->assertNull( ( new StatsModel() )->get( 'definitely_not_a_property' ) );
	}

	/*
	 * getStat() — chain entry point
	 */

	public function test_getStat_returns_this_for_chaining() {
		$s = new StatsModel();
		$this->assertSame( $s, $s->getStat( 'media' ) );
	}

	public function test_getStat_populates_currentStat_and_path_for_known_bucket() {
		$s = new StatsModel();
		$s->getStat( 'media' );

		$this->assertIsArray( $this->getPrivate( $s, 'currentStat' ) );
		$this->assertSame( array( 'media' ), $this->getPrivate( $s, 'path' ) );
	}

	public function test_getStat_leaves_currentStat_null_for_unknown_bucket() {
		$s = new StatsModel();
		$s->getStat( 'not_a_real_bucket' );

		$this->assertNull( $this->getPrivate( $s, 'currentStat' ) );
	}

	public function test_getStat_resets_currentStat_between_chains() {
		$s = new StatsModel();
		$s->getStat( 'media' );
		$this->assertIsArray( $this->getPrivate( $s, 'currentStat' ) );

		$s->getStat( 'not_a_real_bucket' );
		$this->assertNull( $this->getPrivate( $s, 'currentStat' ) );
	}

	/*
	 * grab() — chain descent + leaf return
	 */

	public function test_grab_returns_null_when_no_currentStat_is_set() {
		$s = new StatsModel();
		$s->getStat( 'not_a_real_bucket' );

		$this->assertNull( $s->grab( 'items' ) );
	}

	public function test_grab_descends_when_key_is_present_on_current_cursor_and_returns_leaf_value() {
		// Pre-seed a fresh stats payload so grab() doesn't fall through to fetchStatData.
		$s = new StatsModel();
		$this->setPrivate( $s, 'stats', array(
			'media' => array( 'items' => 42 ) + $this->baselineMediaBucket(),
		) );

		$result = $s->getStat( 'media' )->grab( 'items' );
		$this->assertSame( 42, $result );
	}

	public function test_grab_returns_this_when_the_descended_value_is_still_an_array() {
		$s = new StatsModel();
		$this->setPrivate( $s, 'stats', array(
			'period' => array( 'months' => array( '1' => 5, '2' => 3, '3' => 1, '4' => 0 ) ),
		) );

		$intermediate = $s->getStat( 'period' )->grab( 'months' );
		$this->assertSame( $s, $intermediate );
	}

	public function test_grab_returns_leaf_value_after_two_step_chain() {
		$s = new StatsModel();
		$this->setPrivate( $s, 'stats', array(
			'period' => array( 'months' => array( '1' => 5, '2' => 3, '3' => 1, '4' => 0 ) ),
		) );

		$this->assertSame( 5, $s->getStat( 'period' )->grab( 'months' )->grab( '1' ) );
	}

	public function test_grab_appends_each_descended_key_to_path() {
		$s = new StatsModel();
		$this->setPrivate( $s, 'stats', array(
			'period' => array( 'months' => array( '1' => 5 ) ),
		) );

		$s->getStat( 'period' )->grab( 'months' );
		$this->assertSame( array( 'period', 'months' ), $this->getPrivate( $s, 'path' ) );
	}

	/*
	 * checkInt — pure coercion
	 */

	public function test_checkInt_coerces_numeric_strings_to_int() {
		$s = $this->freshModel();
		$this->assertSame( 42, $this->invokePrivate( $s, 'checkInt', array( '42' ) ) );
	}

	public function test_checkInt_leaves_integers_untouched() {
		$s = $this->freshModel();
		$this->assertSame( 7, $this->invokePrivate( $s, 'checkInt', array( 7 ) ) );
	}

	public function test_checkInt_passes_non_numeric_values_through_unchanged() {
		$s = $this->freshModel();
		$this->assertSame( 'not-a-number', $this->invokePrivate( $s, 'checkInt', array( 'not-a-number' ) ) );

		$arr = array( 'x' => 1 );
		$this->assertSame( $arr, $this->invokePrivate( $s, 'checkInt', array( $arr ) ) );
	}
}
