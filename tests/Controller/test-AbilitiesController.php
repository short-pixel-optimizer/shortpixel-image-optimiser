<?php
/**
 * AbilitiesController unit tests (WP Abilities API integration, WP 6.9+).
 *
 * Covers the registration layer added on the mcp branch:
 *  - the ability catalog (getAbilities()): 16 abilities, complete args,
 *    callable execute callbacks, shared 'shortpixel' category, REST/MCP meta;
 *  - the permission model: get-settings/update-settings gate on
 *    manage_options (userCanManage), everything else on edit_others_posts
 *    (userCanOptimize);
 *  - confirm-gates: every destructive/credit-consuming bulk ability requires
 *    'confirm' in its input schema;
 *  - hook wiring on wp_abilities_api_categories_init / wp_abilities_api_init;
 *  - live registration with the real Abilities API (self-skips on WP < 6.9),
 *    including the shortpixel/abilities/init filter short-circuit.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Abilities\AbilitiesController;

class AbilitiesControllerTest extends WP_UnitTestCase {

	/** @var AbilitiesController */
	private $controller;

	public function set_up() {
		parent::set_up();
		$this->controller = AbilitiesController::getInstance();
	}

	public function tear_down() {
		// The Abilities API registries are process-global lazy singletons;
		// reset them so registrations from one test never leak into the next.
		$this->resetAbilitiesRegistries();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Null the WP_Abilities_Registry / WP_Ability_Categories_Registry singletons (reflection — core offers no reset). */
	private function resetAbilitiesRegistries(): void {
		foreach ( array( 'WP_Abilities_Registry', 'WP_Ability_Categories_Registry' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$prop = ( new ReflectionClass( $class ) )->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
	}

	/** All ability names the plugin must expose, in registration order. */
	private function expectedAbilityNames(): array {
		return array(
			'shortpixel/get-stats',
			'shortpixel/get-quota',
			'shortpixel/get-settings',
			'shortpixel/get-media-status',
			'shortpixel/get-queue-status',
			'shortpixel/optimize-media',
			'shortpixel/run-queue',
			'shortpixel/restore-media',
			'shortpixel/bulk-restore',
			'shortpixel/bulk-optimize',
			'shortpixel/get-ai-seo-status',
			'shortpixel/generate-ai-seo',
			'shortpixel/undo-ai-seo',
			'shortpixel/bulk-generate-ai-seo',
			'shortpixel/bulk-undo-ai-seo',
			'shortpixel/update-settings',
		);
	}

	// ------------------------------------------------------------------
	// Catalog shape
	// ------------------------------------------------------------------

	public function test_catalog_contains_exactly_the_expected_abilities() {
		$abilities = $this->controller->getAbilities();

		$this->assertSame(
			$this->expectedAbilityNames(),
			array_keys( $abilities ),
			'The ability catalog must contain exactly the 16 documented abilities'
		);
	}

	public function test_every_ability_has_complete_registration_args() {
		foreach ( $this->controller->getAbilities() as $name => $args ) {
			$this->assertNotEmpty( $args['label'], "$name must have a label" );
			$this->assertNotEmpty( $args['description'], "$name must have a description" );
			$this->assertSame( AbilitiesController::ABILITY_CATEGORY, $args['category'], "$name must use the shared category" );
			$this->assertIsCallable( $args['execute_callback'], "$name execute_callback must be callable" );
			$this->assertIsCallable( $args['permission_callback'], "$name permission_callback must be callable" );
		}
	}

	public function test_every_ability_is_exposed_via_rest_and_mcp() {
		foreach ( $this->controller->getAbilities() as $name => $args ) {
			$this->assertTrue( $args['meta']['show_in_rest'], "$name must set meta.show_in_rest" );
			$this->assertTrue( $args['meta']['mcp']['public'], "$name must set meta.mcp.public" );
		}
	}

	public function test_input_schema_required_fields_exist_in_properties() {
		foreach ( $this->controller->getAbilities() as $name => $args ) {
			if ( ! isset( $args['input_schema']['required'] ) ) {
				continue;
			}
			foreach ( $args['input_schema']['required'] as $required ) {
				$this->assertArrayHasKey(
					$required,
					$args['input_schema']['properties'],
					"$name declares required field '$required' that is missing from properties"
				);
			}
		}
	}

	public function test_destructive_bulk_abilities_require_confirm() {
		$confirmGated = array(
			'shortpixel/bulk-restore',
			'shortpixel/bulk-optimize',
			'shortpixel/bulk-generate-ai-seo',
			'shortpixel/bulk-undo-ai-seo',
		);

		$abilities = $this->controller->getAbilities();

		foreach ( $confirmGated as $name ) {
			$this->assertContains(
				'confirm',
				$abilities[ $name ]['input_schema']['required'],
				"$name is destructive/credit-consuming and must require confirm=true in its schema"
			);
		}
	}

	public function test_single_item_abilities_require_id() {
		$idGated = array(
			'shortpixel/get-media-status',
			'shortpixel/optimize-media',
			'shortpixel/restore-media',
			'shortpixel/get-ai-seo-status',
			'shortpixel/generate-ai-seo',
			'shortpixel/undo-ai-seo',
		);

		$abilities = $this->controller->getAbilities();

		foreach ( $idGated as $name ) {
			$this->assertContains(
				'id',
				$abilities[ $name ]['input_schema']['required'],
				"$name operates on a single item and must require an id in its schema"
			);
		}
	}

	// ------------------------------------------------------------------
	// Permission model
	// ------------------------------------------------------------------

	public function test_settings_and_destructive_bulk_abilities_gate_on_manage_options_all_others_on_edit_others_posts() {
		// bulk-restore + bulk-undo-ai-seo were tightened from userCanOptimize to
		// userCanManage in c83f344d after review: both are site-wide destructive
		// operations (backup restoration, AI metadata wipe) that go well beyond
		// what "edit others' posts" implies. bulk-optimize and bulk-generate-ai-seo
		// stay on userCanOptimize because they only spend credits, they don't
		// destroy stored data.
		$manageOnly = array(
			'shortpixel/get-settings',
			'shortpixel/update-settings',
			'shortpixel/bulk-restore',
			'shortpixel/bulk-undo-ai-seo',
		);

		foreach ( $this->controller->getAbilities() as $name => $args ) {
			$method = $args['permission_callback'][1];

			if ( in_array( $name, $manageOnly, true ) ) {
				$this->assertSame( 'userCanManage', $method, "$name reads/writes settings or does destructive site-wide work and must gate on manage_options" );
			} else {
				$this->assertSame( 'userCanOptimize', $method, "$name must gate on edit_others_posts like the SPIO admin pages" );
			}
		}
	}

	/**
	 * Regression pin for c83f344d: bulk-restore + bulk-undo-ai-seo require
	 * manage_options (admin), not edit_others_posts (editor). A weaker cap on
	 * a destructive site-wide operation was the original bug.
	 */
	public function test_destructive_bulk_abilities_deny_execution_to_editors() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WP Abilities API (WP 6.9+) not available in this WordPress version.' );
		}

		$this->resetAbilitiesRegistries();
		\WP_Abilities_Registry::get_instance();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array( 'shortpixel/bulk-restore', 'shortpixel/bulk-undo-ai-seo' ) as $name ) {
			$ability = \wp_get_ability( $name );
			$this->assertNotNull( $ability, "$name must be registered" );
			$result = $ability->check_permissions();
			$this->assertTrue(
				false === $result || is_wp_error( $result ),
				"$name must reject editors after c83f344d (was accepting them before)"
			);
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( 'shortpixel/bulk-restore', 'shortpixel/bulk-undo-ai-seo' ) as $name ) {
			$ability = \wp_get_ability( $name );
			$this->assertTrue( $ability->check_permissions(), "$name must accept administrators" );
		}
	}

	public function test_administrator_passes_both_permission_callbacks() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( $this->controller->userCanManage() );
		$this->assertTrue( $this->controller->userCanOptimize() );
	}

	public function test_editor_can_optimize_but_not_manage_settings() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse( $this->controller->userCanManage(), 'Editors must not read/write plugin settings' );
		$this->assertTrue( $this->controller->userCanOptimize(), 'Editors (edit_others_posts) may optimize, like on the SPIO admin pages' );
	}

	public function test_author_and_logged_out_users_pass_neither_permission_callback() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( $this->controller->userCanManage() );
		$this->assertFalse( $this->controller->userCanOptimize(), 'Authors lack edit_others_posts and must not drive site-wide optimization' );

		wp_set_current_user( 0 );
		$this->assertFalse( $this->controller->userCanManage() );
		$this->assertFalse( $this->controller->userCanOptimize() );
	}

	// ------------------------------------------------------------------
	// Hook wiring + registration
	// ------------------------------------------------------------------

	public function test_registration_hooks_are_attached_by_the_plugin_boot() {
		$this->assertNotFalse(
			has_action( 'wp_abilities_api_categories_init', array( $this->controller, 'registerCategories' ) ),
			'lowInit() must attach the category registration to wp_abilities_api_categories_init'
		);
		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $this->controller, 'registerAbilities' ) ),
			'lowInit() must attach the ability registration to wp_abilities_api_init'
		);
	}

	public function test_is_api_available_reflects_function_existence() {
		$this->assertSame( function_exists( 'wp_register_ability' ), $this->controller->isApiAvailable() );
	}

	public function test_registration_methods_are_noops_without_the_abilities_api() {
		if ( function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API present — the no-op guard path is not reachable.' );
		}

		// Must not fatal or warn when the API is absent (WP < 6.9).
		$this->controller->registerCategories();
		$this->controller->registerAbilities();

		$this->assertFalse( $this->controller->isApiAvailable() );
		$this->assertFalse( $this->controller->isAbilityRegistered( 'shortpixel/get-stats' ) );
	}

	/**
	 * Registration with the REAL Abilities API.
	 *
	 * wp_register_ability() must run during the `wp_abilities_api_init`
	 * action (else _doing_it_wrong), so the canonical way to trigger our
	 * hooked callbacks is the registry's lazy init:
	 * WP_Abilities_Registry::get_instance() fires the categories hook first,
	 * then wp_abilities_api_init — exactly what a REST/MCP request does.
	 */
	public function test_abilities_register_with_the_real_abilities_api() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WP Abilities API (WP 6.9+) not available in this WordPress version.' );
		}

		// Fresh registries: earlier tests in this process may have already
		// triggered (or short-circuited) the one-shot lazy init.
		$this->resetAbilitiesRegistries();
		\WP_Abilities_Registry::get_instance();

		foreach ( $this->expectedAbilityNames() as $name ) {
			$this->assertTrue(
				$this->controller->isAbilityRegistered( $name ),
				"$name must be registered with the Abilities API"
			);

			$ability = \wp_get_ability( $name );
			$this->assertNotNull( $ability );
			$this->assertSame(
				AbilitiesController::ABILITY_CATEGORY,
				$ability->get_category(),
				"$name must land in the shortpixel category"
			);
		}
	}

	public function test_init_filter_false_prevents_all_registration() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WP Abilities API (WP 6.9+) not available in this WordPress version.' );
		}

		// Looking up an unregistered ability makes core fire _doing_it_wrong
		// (by design since 6.9) — expected here, since nothing registers.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		// Fresh registries so the lazy init re-fires with the filter active.
		$this->resetAbilitiesRegistries();

		add_filter( 'shortpixel/abilities/init', '__return_false' );
		\WP_Abilities_Registry::get_instance();
		remove_filter( 'shortpixel/abilities/init', '__return_false' );

		foreach ( $this->expectedAbilityNames() as $name ) {
			$this->assertFalse(
				$this->controller->isAbilityRegistered( $name ),
				"shortpixel/abilities/init=false must prevent registration of $name"
			);
		}
	}

	public function test_registered_ability_denies_execution_to_users_without_capability() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'WP Abilities API (WP 6.9+) not available in this WordPress version.' );
		}

		$this->resetAbilitiesRegistries();
		\WP_Abilities_Registry::get_instance();

		$ability = \wp_get_ability( 'shortpixel/get-stats' );
		$this->assertNotNull( $ability );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$authorResult = $ability->check_permissions();
		$this->assertTrue(
			false === $authorResult || is_wp_error( $authorResult ),
			'Authors must not pass the get-stats permission check'
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( $ability->check_permissions(), 'Editors must pass the get-stats permission check' );
	}
}
