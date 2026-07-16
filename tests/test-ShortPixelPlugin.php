<?php
/**
 * Tests for ShortPixel\ShortPixelPlugin.
 *
 * Focus areas:
 *   - Singleton contract (getInstance)
 *   - Path helpers (plugin_url, plugin_path) — trailing-slash + concatenation
 *   - Accessor return types (settings, env, fileSystem)
 *   - fileSystem() returns a fresh instance every call (documented contract)
 *   - get_admin_pages returns the private array as-is
 *   - load_style / load_script — noheader bail, registered enqueue,
 *     unregistered skip, string vs. array input for load_script
 *
 * Skipped at the unit level (integration territory — either pure hook
 * wiring where a unit test would duplicate WP's own registry, or heavy
 * side-effects requiring the full admin context):
 *   - __construct / lowInit / init / admin_init — hook scheduling
 *   - initHooks / ajaxHooks — 25+ add_action calls each
 *   - admin_pages / admin_network_pages — WP menu registration
 *   - admin_scripts / admin_styles — 30+ wp_register_* calls each with localize payloads
 *   - load_admin_scripts — screen-based dispatcher; branches depend on $plugin_page + screen_id
 *   - route — $_REQUEST-driven controller dispatch
 *   - check_plugin_version — triggers InstallHelper::activatePlugin (table migrations)
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\ShortPixelPlugin;
use ShortPixel\Model\SettingsModel;
use ShortPixel\Model\EnvironmentModel;
use ShortPixel\Controller\FileSystemController;

class ShortPixelPluginTest extends WP_UnitTestCase {

	/**
	 * Handles registered inside a test — deregistered in tear_down so
	 * state doesn't leak across test cases.
	 *
	 * @var array{styles: string[], scripts: string[]}
	 */
	private $registered = array( 'styles' => array(), 'scripts' => array() );

	public function tear_down() {
		foreach ( $this->registered['styles'] as $handle ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		foreach ( $this->registered['scripts'] as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		$this->registered = array( 'styles' => array(), 'scripts' => array() );

		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function getPrivate( ShortPixelPlugin $p, string $prop ) {
		$ref = new ReflectionClass( ShortPixelPlugin::class );
		$r   = $ref->getProperty( $prop );
		$r->setAccessible( true );
		return $r->getValue( $p );
	}

	private function setPrivate( ShortPixelPlugin $p, string $prop, $value ): void {
		$ref = new ReflectionClass( ShortPixelPlugin::class );
		$r   = $ref->getProperty( $prop );
		$r->setAccessible( true );
		$r->setValue( $p, $value );
	}

	/**
	 * Build a ShortPixelPlugin instance without running the constructor,
	 * so tests can mutate protected state (`is_noheaders`, `plugin_url`,
	 * `plugin_path`, `admin_pages`) without touching the singleton that
	 * `wpSPIO()` returns.
	 */
	private function freshPlugin(): ShortPixelPlugin {
		$ref = new ReflectionClass( ShortPixelPlugin::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function registerStyle( string $handle ): void {
		wp_register_style( $handle, 'about:blank' );
		$this->registered['styles'][] = $handle;
	}

	private function registerScript( string $handle ): void {
		wp_register_script( $handle, 'about:blank' );
		$this->registered['scripts'][] = $handle;
	}

	/*
	 * getInstance — singleton contract
	 */

	public function test_getInstance_returns_a_ShortPixelPlugin() {
		$this->assertInstanceOf( ShortPixelPlugin::class, ShortPixelPlugin::getInstance() );
	}

	public function test_getInstance_returns_the_same_instance_on_repeated_calls() {
		$a = ShortPixelPlugin::getInstance();
		$b = ShortPixelPlugin::getInstance();

		$this->assertSame( $a, $b );
	}

	/*
	 * settings / env / fileSystem — accessor return types
	 */

	public function test_settings_returns_a_SettingsModel_instance() {
		$this->assertInstanceOf(
			SettingsModel::class,
			ShortPixelPlugin::getInstance()->settings()
		);
	}

	public function test_env_returns_an_EnvironmentModel_instance() {
		$this->assertInstanceOf(
			EnvironmentModel::class,
			ShortPixelPlugin::getInstance()->env()
		);
	}

	public function test_fileSystem_returns_a_FileSystemController_instance() {
		$this->assertInstanceOf(
			FileSystemController::class,
			ShortPixelPlugin::getInstance()->fileSystem()
		);
	}

	public function test_fileSystem_returns_a_fresh_instance_on_every_call() {
		// Documented contract: unlike settings() and env(), fileSystem()
		// hands back `new FileSystemController()` each time — the
		// controller is stateless, so this is fine but must not silently
		// regress into a singleton.
		$p = ShortPixelPlugin::getInstance();

		$a = $p->fileSystem();
		$b = $p->fileSystem();

		$this->assertNotSame( $a, $b );
	}

	/*
	 * plugin_url — trailing-slash guarantee + relative-path append
	 */

	public function test_plugin_url_returns_base_url_with_trailing_slash_when_no_path_is_given() {
		$p = $this->freshPlugin();
		$this->setPrivate( $p, 'plugin_url', 'https://example.test/wp-content/plugins/spio' );

		// trailingslashit should add the missing slash.
		$this->assertSame(
			'https://example.test/wp-content/plugins/spio/',
			$p->plugin_url()
		);
	}

	public function test_plugin_url_does_not_double_a_trailing_slash_that_is_already_present() {
		$p = $this->freshPlugin();
		$this->setPrivate( $p, 'plugin_url', 'https://example.test/wp-content/plugins/spio/' );

		$this->assertSame(
			'https://example.test/wp-content/plugins/spio/',
			$p->plugin_url()
		);
	}

	public function test_plugin_url_appends_a_relative_path_to_the_base() {
		$p = $this->freshPlugin();
		$this->setPrivate( $p, 'plugin_url', 'https://example.test/wp-content/plugins/spio' );

		$this->assertSame(
			'https://example.test/wp-content/plugins/spio/res/js/foo.js',
			$p->plugin_url( 'res/js/foo.js' )
		);
	}

	/*
	 * plugin_path — trailing-slash guarantee + relative-path append
	 */

	public function test_plugin_path_returns_base_path_with_trailing_slash_when_no_path_is_given() {
		$p = $this->freshPlugin();
		$this->setPrivate( $p, 'plugin_path', '/tmp/spio-plugin' );

		$this->assertSame( '/tmp/spio-plugin/', $p->plugin_path() );
	}

	public function test_plugin_path_appends_a_relative_path_to_the_base() {
		$p = $this->freshPlugin();
		$this->setPrivate( $p, 'plugin_path', '/tmp/spio-plugin' );

		$this->assertSame(
			'/tmp/spio-plugin/class/foo.php',
			$p->plugin_path( 'class/foo.php' )
		);
	}

	/*
	 * get_admin_pages — passthrough of the private array
	 */

	public function test_get_admin_pages_returns_the_private_admin_pages_array() {
		$p        = $this->freshPlugin();
		$expected = array( 'settings_page_hook', 'media_page_hook' );
		$this->setPrivate( $p, 'admin_pages', $expected );

		$this->assertSame( $expected, $p->get_admin_pages() );
	}

	/*
	 * load_style — noheader bail, registered enqueue, unregistered skip
	 */

	public function test_load_style_bails_silently_when_is_noheaders_is_true() {
		$this->registerStyle( 'spio-test-style-noheader' );

		$p = $this->freshPlugin();
		$this->setPrivate( $p, 'is_noheaders', true );

		$p->load_style( 'spio-test-style-noheader' );

		$this->assertFalse(
			wp_style_is( 'spio-test-style-noheader', 'enqueued' ),
			'load_style should no-op when is_noheaders is true'
		);
	}

	public function test_load_style_enqueues_a_previously_registered_style() {
		$this->registerStyle( 'spio-test-style-registered' );

		$p = $this->freshPlugin();

		$p->load_style( 'spio-test-style-registered' );

		$this->assertTrue(
			wp_style_is( 'spio-test-style-registered', 'enqueued' )
		);
	}

	public function test_load_style_does_not_enqueue_an_unregistered_handle() {
		$p = $this->freshPlugin();

		$p->load_style( 'spio-test-style-not-registered' );

		$this->assertFalse(
			wp_style_is( 'spio-test-style-not-registered', 'enqueued' )
		);
	}

	/*
	 * load_script — noheader bail, string vs. array input, unregistered skip
	 */

	public function test_load_script_bails_silently_when_is_noheaders_is_true() {
		$this->registerScript( 'spio-test-script-noheader' );

		$p = $this->freshPlugin();
		$this->setPrivate( $p, 'is_noheaders', true );

		$p->load_script( 'spio-test-script-noheader' );

		$this->assertFalse(
			wp_script_is( 'spio-test-script-noheader', 'enqueued' ),
			'load_script should no-op when is_noheaders is true'
		);
	}

	public function test_load_script_accepts_a_single_string_handle_and_enqueues_it() {
		$this->registerScript( 'spio-test-script-single' );

		$p = $this->freshPlugin();

		$p->load_script( 'spio-test-script-single' );

		$this->assertTrue(
			wp_script_is( 'spio-test-script-single', 'enqueued' )
		);
	}

	public function test_load_script_accepts_array_input_and_enqueues_only_the_registered_handles() {
		$this->registerScript( 'spio-test-script-a' );
		$this->registerScript( 'spio-test-script-b' );

		$p = $this->freshPlugin();

		$p->load_script( array(
			'spio-test-script-a',
			'spio-test-script-not-registered',
			'spio-test-script-b',
		) );

		$this->assertTrue( wp_script_is( 'spio-test-script-a', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'spio-test-script-b', 'enqueued' ) );
		// Sentinel: the unregistered handle in the middle of the array
		// must NOT stop the loop from processing the registered handles
		// after it, AND must not itself get enqueued.
		$this->assertFalse( wp_script_is( 'spio-test-script-not-registered', 'enqueued' ) );
	}
}
