<?php
/**
 * Tests for ShortPixel\Model\MultiSettingsModel.
 *
 * Focuses on the divergence from the per-site SettingsModel:
 *   - the constructor's model-schema merge for `disable_site_settings_page`
 *     and `network_settings_override_enabled`
 *   - the load() override reading from `get_site_option('spio_wpmu')`
 *   - the save() override writing back to the same key
 *   - a separate singleton pool from SettingsModel
 *   - the network-scoped read path: __get (stored value or model default,
 *     no per-site fallback), isset() (storage check, not schema check) and
 *     isNetworkOverrideEnabled() reading the stored toggle
 *
 * The parent's SettingsModel behaviour (magic __get/__set, sanitisation,
 * shutdown persistence) is covered by test-SettingsModel and is not
 * re-asserted here.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\MultiSettingsModel;
use ShortPixel\Model\SettingsModel;

class MultiSettingsModelTest extends WP_UnitTestCase {

	/**
	 * Network option name used for persistence.
	 */
	private const OPTION_NAME = 'spio_wpmu';

	public function set_up() {
		parent::set_up();
		delete_site_option( self::OPTION_NAME );
		$this->resetSingleton();
	}

	public function tear_down() {
		delete_site_option( self::OPTION_NAME );
		$this->resetSingleton();
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( MultiSettingsModel::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	private function freshModel(): MultiSettingsModel {
		$ref = new ReflectionClass( MultiSettingsModel::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function setPrivate( MultiSettingsModel $m, string $prop, $value ): void {
		// Walk the inheritance chain — some props ($updated, $model) live on the parent.
		$ref = new ReflectionClass( MultiSettingsModel::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	private function getPrivate( MultiSettingsModel $m, string $prop ) {
		$ref = new ReflectionClass( MultiSettingsModel::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function invokePrivate( MultiSettingsModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( MultiSettingsModel::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Method $method not found on any ancestor" );
		$r = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	/*
	 * Singleton — independent pool, distinct instance shape
	 */

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = MultiSettingsModel::getInstance();
		$b = MultiSettingsModel::getInstance();
		$this->assertInstanceOf( MultiSettingsModel::class, $a );
		$this->assertSame( $a, $b );
	}

	public function test_getInstance_is_separate_from_SettingsModel_singleton() {
		$multi = MultiSettingsModel::getInstance();
		$single = SettingsModel::getInstance();

		$this->assertInstanceOf( MultiSettingsModel::class, $multi );
		$this->assertNotSame( $multi, $single );
	}

	/*
	 * Constructor — merges the network-only field into $model
	 */

	public function test_constructor_adds_disable_site_settings_page_to_model_schema() {
		$m     = MultiSettingsModel::getInstance();
		$model = $this->getPrivate( $m, 'model' );

		$this->assertArrayHasKey( 'disable_site_settings_page', $model );
		$this->assertSame( 'boolean', $model['disable_site_settings_page']['s'] );
		$this->assertFalse( $model['disable_site_settings_page']['default'] );
	}

	public function test_constructor_adds_network_override_toggle_to_model_schema() {
		$m     = MultiSettingsModel::getInstance();
		$model = $this->getPrivate( $m, 'model' );

		$this->assertArrayHasKey( 'network_settings_override_enabled', $model );
		$this->assertSame( 'boolean', $model['network_settings_override_enabled']['s'] );
		$this->assertFalse( $model['network_settings_override_enabled']['default'] );
	}

	public function test_constructor_preserves_inherited_parent_model_fields() {
		$m     = MultiSettingsModel::getInstance();
		$model = $this->getPrivate( $m, 'model' );

		// A representative sample of parent-declared fields — the union merge
		// must not drop these.
		$this->assertArrayHasKey( 'compressionType', $model );
		$this->assertArrayHasKey( 'backupImages', $model );
		$this->assertArrayHasKey( 'createWebp', $model );
	}

	/*
	 * load() — reads from the site option, defends against non-array values
	 */

	public function test_load_reads_existing_site_option_into_settings() {
		update_site_option( self::OPTION_NAME, array( 'foo' => 'bar', 'x' => 1 ) );

		$m = $this->freshModel();
		$this->invokePrivate( $m, 'load' );

		$this->assertSame( array( 'foo' => 'bar', 'x' => 1 ), $this->getPrivate( $m, 'settings' ) );
	}

	public function test_load_falls_back_to_empty_array_when_option_is_missing() {
		delete_site_option( self::OPTION_NAME );

		$m = $this->freshModel();
		$this->invokePrivate( $m, 'load' );

		$this->assertSame( array(), $this->getPrivate( $m, 'settings' ) );
	}

	public function test_load_defends_against_non_array_stored_value() {
		update_site_option( self::OPTION_NAME, 'this-should-not-be-a-string' );

		$m = $this->freshModel();
		$this->invokePrivate( $m, 'load' );

		$this->assertSame( array(), $this->getPrivate( $m, 'settings' ) );
	}

	/*
	 * save() — writes to the site option and clears the dirty flag
	 */

	public function test_save_writes_current_settings_to_site_option() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'settings', array( 'foo' => 'bar' ) );

		$this->invokePrivate( $m, 'save' );

		$this->assertSame( array( 'foo' => 'bar' ), get_site_option( self::OPTION_NAME ) );
	}

	public function test_save_clears_the_updated_dirty_flag() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'settings', array() );
		$this->setPrivate( $m, 'updated', true );

		$this->invokePrivate( $m, 'save' );

		$this->assertFalse( $this->getPrivate( $m, 'updated' ) );
	}

	/*
	 * Network-scoped read path — __get / isset() / isNetworkOverrideEnabled()
	 */

	public function test_get_returns_stored_network_value_sanitized() {
		update_site_option( self::OPTION_NAME, array( 'createWebp' => 1 ) );

		$m = MultiSettingsModel::getInstance();

		$this->assertTrue( $m->createWebp, 'Stored network value must be returned (sanitized to boolean).' );
	}

	public function test_get_returns_model_default_when_not_stored_at_network_level() {
		$m = MultiSettingsModel::getInstance();

		$this->assertFalse( $m->network_settings_override_enabled );
		$this->assertFalse( $m->disable_site_settings_page );
	}

	public function test_isset_reports_storage_not_schema() {
		update_site_option( self::OPTION_NAME, array( 'createWebp' => true ) );

		$m = MultiSettingsModel::getInstance();

		$this->assertTrue( $m->isset( 'createWebp' ), 'isset() must be true for a stored key.' );
		$this->assertFalse(
			$m->isset( 'compressionType' ),
			'isset() must be false for a key that is only declared in the model schema but never stored.'
		);
	}

	public function test_isNetworkOverrideEnabled_defaults_to_false() {
		$m = MultiSettingsModel::getInstance();

		$this->assertFalse( $m->isNetworkOverrideEnabled() );
	}

	public function test_isNetworkOverrideEnabled_reads_stored_toggle() {
		update_site_option( self::OPTION_NAME, array( 'network_settings_override_enabled' => true ) );

		$m = MultiSettingsModel::getInstance();

		$this->assertTrue( $m->isNetworkOverrideEnabled() );
	}

	public function test_site_settingsmodel_network_override_is_disabled_on_single_site() {
		// The unit suite runs single-site: the site model must never consult
		// a network model there.
		$this->assertFalse( SettingsModel::getInstance()->isNetworkOverrideEnabled() );
	}

	public function test_save_then_load_roundtrip_via_the_site_option() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'settings', array( 'processThumbnails' => true, 'excludeSizes' => array( 'medium' ) ) );

		$this->invokePrivate( $m, 'save' );

		// Freshly-constructed instance — must re-hydrate the same payload.
		$m2 = $this->freshModel();
		$this->invokePrivate( $m2, 'load' );

		$this->assertSame(
			array( 'processThumbnails' => true, 'excludeSizes' => array( 'medium' ) ),
			$this->getPrivate( $m2, 'settings' )
		);
	}
}
