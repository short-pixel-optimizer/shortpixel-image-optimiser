<?php
/**
 * Logger debug box on multisite (build/shortpixel/log).
 *
 * With `SHORTPIXEL_DEBUG` in the request the logger defers its user check
 * to `wp_loaded` on multisite (msCheckUserLevel()), which also prepares
 * the debug box. The log is one file for the whole network, in the main
 * uploads directory, so the box links to it for network administrators
 * only; the administrator of a single site gets the box without the link.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\ShortPixelLogger\ShortPixelLogger;

class MultisiteDebugBoxTest extends SPIO_IntegrationTestCase {

	/** @var ShortPixelLogger|null The plugin's logger, put back in tear_down(). */
	private $original;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite suite needs a multisite test install — run via bin/test.sh --ms (WP_MULTISITE=1).' );
		}
		parent::set_up();
		$this->original = $this->instance();
	}

	public function tear_down() {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		unset( $_REQUEST['SHORTPIXEL_DEBUG'] );
		$logger = $this->instance();
		if ( $logger ) {
			remove_action( 'wp_loaded', array( $logger, 'msCheckUserLevel' ) );
			remove_action( 'admin_footer', array( $logger, 'loadView' ) );
		}
		$this->instance( $this->original );
		wp_dequeue_script( 'jquery-ui-draggable' );
		parent::tear_down();
	}

	/** Reads, or with an argument replaces, the logger singleton. */
	private function instance() {
		$args = func_get_args();
		return \Closure::bind(
			function () use ( $args ) {
				if ( count( $args ) > 0 ) {
					self::$instance = $args[0];
				}
				return self::$instance;
			},
			null,
			ShortPixelLogger::class
		)();
	}

	private function prop( $logger, $name ) {
		return \Closure::bind(
			function () use ( $name ) {
				return $this->$name;
			},
			$logger,
			ShortPixelLogger::class
		)();
	}

	/**
	 * A logger built for a request carrying SHORTPIXEL_DEBUG, taken through
	 * the deferred user check and given the plugin's log path.
	 */
	private function debugRequestLogger(): ShortPixelLogger {
		$_REQUEST['SHORTPIXEL_DEBUG'] = '4';
		$this->instance( null );
		$logger = ShortPixelLogger::getInstance();

		// SENTINEL: the multisite branch of the constructor was taken.
		$this->assertSame( 10, has_action( 'wp_loaded', array( $logger, 'msCheckUserLevel' ) ), 'Sentinel: the user check is deferred to wp_loaded.' );
		$this->assertFalse( $this->prop( $logger, 'is_manual_request' ), 'Sentinel: not active before wp_loaded.' );

		// The plugin sets the path on init, while the logger is still inactive.
		$logger->setLogPath( SHORTPIXEL_BACKUP_FOLDER . '/shortpixel_log' );
		$logger->msCheckUserLevel();
		return $logger;
	}

	private function boxHtml( ShortPixelLogger $logger ): string {
		ob_start();
		$logger->loadView();
		return (string) ob_get_clean();
	}

	public function test_network_admin_on_a_subsite_gets_the_box_with_a_link_to_the_network_log() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		$blog_id = (int) self::factory()->blog->create( array( 'user_id' => $user_id ) );
		switch_to_blog( $blog_id );
		wp_set_current_user( $user_id );

		$logger = $this->debugRequestLogger();

		$this->assertTrue( $this->prop( $logger, 'is_manual_request' ) );
		$this->assertSame( 10, has_action( 'admin_footer', array( $logger, 'loadView' ) ), 'The box is scheduled from wp_loaded.' );

		$html = $this->boxHtml( $logger );

		// SENTINEL: the log lives above this site's own uploads directory.
		$uploads = wp_get_upload_dir();
		$this->assertStringContainsString( '/sites/' . $blog_id, $uploads['basedir'], 'Sentinel: a subsite uploads directory.' );
		$this->assertStringStartsNotWith( trailingslashit( $uploads['basedir'] ), SHORTPIXEL_BACKUP_FOLDER . '/shortpixel_log', 'Sentinel: the log is outside it.' );

		$this->assertStringContainsString( "<div class='sp_debug_box'>", $html );
		$this->assertMatchesRegularExpression( "#href='https?://[^']+/" . preg_quote( SHORTPIXEL_BACKUP, '#' ) . "/shortpixel_log'#", $html );
		revoke_super_admin( $user_id );
	}

	public function test_site_admin_on_a_subsite_gets_the_box_without_the_link() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$blog_id = (int) self::factory()->blog->create( array( 'user_id' => $user_id ) );
		switch_to_blog( $blog_id );
		wp_set_current_user( $user_id );
		// SENTINELS: an administrator of this site, not of the network.
		$this->assertTrue( current_user_can( 'manage_options' ), 'Sentinel: site administrator.' );
		$this->assertFalse( current_user_can( 'manage_network_options' ), 'Sentinel: not a network administrator.' );

		$logger = $this->debugRequestLogger();
		$this->assertTrue( $this->prop( $logger, 'is_manual_request' ), 'Sentinel: debug mode is on for the site admin.' );

		$html = $this->boxHtml( $logger );

		$this->assertStringContainsString( "<div class='sp_debug_box'>", $html, 'Sentinel: the box is printed.' );
		$this->assertStringNotContainsString( 'Logfile', $html );
		$this->assertStringNotContainsString( 'shortpixel_log', $html );
	}

	public function test_setting_the_log_path_while_inactive_creates_no_file() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$_REQUEST['SHORTPIXEL_DEBUG'] = '4';
		$this->instance( null );
		$logger = ShortPixelLogger::getInstance();
		$path   = get_temp_dir() . 'spio-ms-inactive-' . wp_generate_password( 8, false ) . '.log';

		$logger->setLogPath( $path );
		$logger->msCheckUserLevel();

		$this->assertFalse( $this->prop( $logger, 'is_manual_request' ), 'A subscriber cannot switch debug mode on.' );
		$this->assertFileDoesNotExist( $path );
		$this->assertFalse( has_action( 'admin_footer', array( $logger, 'loadView' ) ) );
	}
}
