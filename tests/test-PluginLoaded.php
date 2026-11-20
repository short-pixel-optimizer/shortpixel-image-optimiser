<?php
/**
 * Smoke tests confirming the plugin bootstraps inside the WordPress test suite.
 *
 * These are intentionally light — they verify the CI toolchain (WP test lib +
 * plugin autoloader) is wired up correctly before adding heavier integration
 * tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

class PluginLoadedTest extends WP_UnitTestCase {

	public function test_plugin_bootstrap_function_exists() {
		$this->assertTrue( function_exists( 'wpSPIO' ), 'wpSPIO() bootstrap function should be defined once the plugin is loaded.' );
	}

	public function test_plugin_constants_are_defined() {
		$this->assertTrue( defined( 'SHORTPIXEL_PLUGIN_FILE' ) );
		$this->assertTrue( defined( 'SHORTPIXEL_PLUGIN_DIR' ) );
		$this->assertTrue( defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ) );
	}

	/** Value of a "Name: value" header line of readme.txt. */
	private function readmeHeader( string $name ): string {
		$readme = (string) file_get_contents( dirname( SHORTPIXEL_PLUGIN_FILE ) . '/readme.txt' );
		$this->assertSame( 1, preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(.+)$/m', $readme, $match ), "readme.txt has a '$name' header." );
		return trim( $match[1] );
	}

	/**
	 * The release version is written in three places that have to agree:
	 * the plugin header, the version constant and the readme's stable tag.
	 * The readme also needs a changelog entry for that version.
	 */
	public function test_version_is_the_same_in_the_header_the_constant_and_the_readme() {
		$data = get_file_data( SHORTPIXEL_PLUGIN_FILE, array( 'Version' => 'Version' ) );

		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', $data['Version'], 'Sentinel: the header has a version.' );
		$this->assertSame( $data['Version'], SHORTPIXEL_IMAGE_OPTIMISER_VERSION );
		$this->assertSame( $data['Version'], $this->readmeHeader( 'Stable tag' ) );

		$readme = (string) file_get_contents( dirname( SHORTPIXEL_PLUGIN_FILE ) . '/readme.txt' );
		$this->assertStringContainsString( "\n= " . $data['Version'] . " =\n", $readme, 'The readme has a changelog entry for the current version.' );
	}

	/**
	 * WordPress reads the minimum WordPress and PHP versions from the plugin
	 * header and refuses to install, activate or update the plugin below
	 * them. They have to match what the readme tells wordpress.org.
	 */
	public function test_minimum_wordpress_and_php_versions_are_declared_in_the_plugin_header() {
		$data = get_file_data(
			SHORTPIXEL_PLUGIN_FILE,
			array(
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			)
		);

		$this->assertNotSame( '', $data['RequiresWP'], 'The header declares a minimum WordPress version.' );
		$this->assertNotSame( '', $data['RequiresPHP'], 'The header declares a minimum PHP version.' );

		// Same floor as the readme. "5.9" and "5.9.0" are the same version, but
		// version_compare() does not see them as equal, so both get three parts.
		$threeParts = static function ( string $version ): string {
			return implode( '.', array_slice( array_pad( explode( '.', $version ), 3, '0' ), 0, 3 ) );
		};
		$this->assertSame( $threeParts( $this->readmeHeader( 'Requires at least' ) ), $threeParts( $data['RequiresWP'] ), 'Header and readme name the same minimum WordPress version.' );
		$this->assertSame( $threeParts( $this->readmeHeader( 'Requires PHP' ) ), $threeParts( $data['RequiresPHP'] ), 'Header and readme name the same minimum PHP version.' );
	}

	/**
	 * WordPress core's own version checks accept the plugin on the versions
	 * the tests run on.
	 *
	 * Runs the two checks validate_plugin_requirements() is made of on the
	 * plugin file's header. validate_plugin_requirements() itself cannot be
	 * used here: it reads the file below WP_PLUGIN_DIR, and the plugin under
	 * test lives outside it, so it would read no header and always pass.
	 */
	public function test_wordpress_accepts_the_declared_requirements_here() {
		$data = get_file_data(
			SHORTPIXEL_PLUGIN_FILE,
			array(
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			)
		);

		// SENTINEL: the header was read.
		$this->assertNotSame( '', $data['RequiresWP'], 'Sentinel: the header declares a minimum WordPress version.' );
		$this->assertNotSame( '', $data['RequiresPHP'], 'Sentinel: the header declares a minimum PHP version.' );

		$this->assertTrue( is_wp_version_compatible( $data['RequiresWP'] ), 'WordPress ' . get_bloginfo( 'version' ) . ' meets "Requires at least: ' . $data['RequiresWP'] . '".' );
		$this->assertTrue( is_php_version_compatible( $data['RequiresPHP'] ), 'PHP ' . PHP_VERSION . ' meets "Requires PHP: ' . $data['RequiresPHP'] . '".' );
	}

	public function test_autoloader_resolves_namespaced_class() {
		$this->assertTrue( class_exists( 'ShortPixel\\Helper\\UtilHelper' ), 'PSR-4 autoloader should resolve ShortPixel\\Helper\\UtilHelper.' );
	}
}
