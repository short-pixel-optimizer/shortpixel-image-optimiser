<?php
/**
 * Integration tests: version-upgrade migration (the deferred Wave-3 item).
 *
 * A plugin UPDATE never fires the activation hook — SPIO instead detects
 * version drift on every admin_init: check_plugin_version()
 * (shortpixel-plugin.php) compares SHORTPIXEL_IMAGE_OPTIMISER_VERSION
 * against settings()->currentVersion and, on mismatch, re-runs
 * InstallHelper::activatePlugin() (dbDelta table/column upgrades, index
 * checks, stale-notice cleanup, ShortQ queue-table install) and stamps the
 * new version. This suite drives that protected method via reflection and
 * verifies each migration effect.
 *
 * DDL pattern (same as test-ActivationLifecycle.php): the WP test
 * framework rewrites CREATE/DROP TABLE into TEMPORARY variants — those
 * query filters are removed per-test, and DDL auto-commits so tear_down()
 * re-runs checkTables() to leave a healthy install behind.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Helper\InstallHelper;
use ShortPixel\Notices\NoticeController;

class VersionUpgradeTest extends SPIO_IntegrationTestCase {

	private const STALE_VERSION = '6.0.0';

	/** Feature notices that activatePlugin() must clear via resetOldNotices(). */
	private const STALE_FEATURE_NOTICES = array(
		'MSG_FEATURE_SMARTCROP',
		'MSG_FEATURE_HEIC',
		'MSG_AVIF_ERROR',
	);

	public function set_up() {
		parent::set_up();

		// activatePlugin() → deactivatePlugin() → htaccess writers need admin context.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Real DDL, not the test framework's TEMPORARY-table rewrites.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down() {
		// DDL auto-commits — restore a healthy install for the next test.
		InstallHelper::checkTables();
		$this->resetNoticeStatics();

		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/** Reflection-invoke the protected admin_init version check. */
	private function runVersionCheck(): void {
		$plugin = \wpSPIO();
		$method = new ReflectionMethod( get_class( $plugin ), 'check_plugin_version' );
		$method->setAccessible( true );
		$method->invoke( $plugin );
	}

	/** Mark the install as running an older plugin version. */
	private function markInstallStale(): void {
		\wpSPIO()->settings()->currentVersion = self::STALE_VERSION;
	}

	/** Drop the notice option + static notice state (shared across tests). */
	private function resetNoticeStatics(): void {
		delete_option( 'ShortPixel-notices' );

		$ref = new ReflectionClass( NoticeController::class );
		foreach ( array(
			'instance'   => null,
			'notices'    => array(),
			'newNotices' => array(),
		) as $name => $empty ) {
			if ( $ref->hasProperty( $name ) ) {
				$prop = $ref->getProperty( $name );
				$prop->setAccessible( true );
				$prop->setValue( null, $empty );
			}
		}
	}

	private function seedPersistentNotice( string $key ): void {
		$notice = NoticeController::addNormal( 'seed ' . $key );
		NoticeController::makePersistent( $notice, $key );
	}

	private function columnExists( string $rawTable, string $column ): bool {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SHOW COLUMNS FROM ' . $wpdb->prefix . $rawTable . ' LIKE %s',
				$column
			)
		);
		return null !== $row;
	}

	private function indexExists( string $rawTable, string $indexName ): bool {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SHOW INDEX FROM ' . $wpdb->prefix . $rawTable . ' WHERE Key_name = %s',
				$indexName
			)
		);
		return null !== $row;
	}

	// -------------------------------------------------------------------
	// Version-drift detection
	// -------------------------------------------------------------------

	public function test_version_drift_reruns_activation_and_stamps_new_version() {
		global $wpdb;
		$this->markInstallStale();
		// Sentinel: a missing plugin table can only come back via activatePlugin().
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'shortpixel_aipostmeta' );
		$this->assertFalse( InstallHelper::checkTableExists( 'shortpixel_aipostmeta' ), 'precondition: table dropped' );

		$this->runVersionCheck();

		$this->assertTrue(
			InstallHelper::checkTableExists( 'shortpixel_aipostmeta' ),
			'A version mismatch on admin_init must re-run activation and recreate missing plugin tables.'
		);
		$this->assertSame(
			SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
			\wpSPIO()->settings()->currentVersion,
			'After the upgrade pass the running version must be stamped into settings.'
		);
	}

	public function test_matching_version_skips_the_activation_pass() {
		global $wpdb;
		\wpSPIO()->settings()->currentVersion = SHORTPIXEL_IMAGE_OPTIMISER_VERSION;
		// Same sentinel as above: if activation ran, this table would reappear.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'shortpixel_aipostmeta' );

		$this->runVersionCheck();

		$this->assertFalse(
			InstallHelper::checkTableExists( 'shortpixel_aipostmeta' ),
			'With no version drift, admin_init must NOT run the (expensive) activation pass.'
		);
	}

	// -------------------------------------------------------------------
	// Schema migrations (dbDelta + index check)
	// -------------------------------------------------------------------

	public function test_upgrade_restores_a_column_added_in_a_newer_schema() {
		global $wpdb;
		// Simulate an old-version schema: extra_info was added in a later release.
		$wpdb->query( 'ALTER TABLE ' . $wpdb->prefix . 'shortpixel_postmeta DROP COLUMN extra_info' );
		$this->assertFalse( $this->columnExists( 'shortpixel_postmeta', 'extra_info' ), 'precondition: column dropped' );

		$this->markInstallStale();
		$this->runVersionCheck();

		$this->assertTrue(
			$this->columnExists( 'shortpixel_postmeta', 'extra_info' ),
			'dbDelta in the upgrade pass must add columns that newer schema versions define.'
		);
	}

	public function test_upgrade_recreates_a_missing_index() {
		global $wpdb;
		$wpdb->query( 'DROP INDEX attach_id ON ' . $wpdb->prefix . 'shortpixel_postmeta' );
		$this->assertFalse( $this->indexExists( 'shortpixel_postmeta', 'attach_id' ), 'precondition: index dropped' );

		$this->markInstallStale();
		$this->runVersionCheck();

		$this->assertTrue(
			$this->indexExists( 'shortpixel_postmeta', 'attach_id' ),
			'checkIndexes() in the upgrade pass must recreate missing plugin indexes.'
		);
	}

	public function test_upgrade_reinstalls_a_missing_queue_table() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'shortpixel_queue' );

		$this->markInstallStale();
		$this->runVersionCheck();

		$this->assertTrue(
			InstallHelper::checkTableExists( 'shortpixel_queue' ),
			'The upgrade pass must run the ShortQ install and recreate the queue table.'
		);
	}

	// -------------------------------------------------------------------
	// Data preservation
	// -------------------------------------------------------------------

	public function test_upgrade_preserves_existing_optimization_rows() {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_postmeta';
		$wpdb->insert(
			$table,
			array(
				'attach_id' => 424242,
				'parent'    => 0,
				'image_type'=> 0,
				'size'      => 'full',
				'status'    => 2,
			)
		);
		$this->assertNotFalse( $wpdb->insert_id, 'precondition: seeded a postmeta row' );

		$this->markInstallStale();
		$this->runVersionCheck();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE attach_id = %d", 424242 )
		);
		$this->assertSame( 1, $count, 'An upgrade (dbDelta) must never destroy existing optimization data.' );
	}

	// -------------------------------------------------------------------
	// Stale-notice cleanup
	// -------------------------------------------------------------------

	public function test_upgrade_clears_stale_feature_notices() {
		$this->resetNoticeStatics();
		foreach ( self::STALE_FEATURE_NOTICES as $key ) {
			$this->seedPersistentNotice( $key );
		}
		// Unrelated persistent notices must survive the cleanup.
		$this->seedPersistentNotice( 'MSG_UNRELATED_MARKER' );

		$this->markInstallStale();
		$this->runVersionCheck();

		foreach ( self::STALE_FEATURE_NOTICES as $key ) {
			$this->assertFalse(
				NoticeController::getInstance()->getNoticeByID( $key ),
				"The upgrade pass (resetOldNotices) must remove the stale $key notice."
			);
		}
		$this->assertIsObject(
			NoticeController::getInstance()->getNoticeByID( 'MSG_UNRELATED_MARKER' ),
			'resetOldNotices() must only remove its own stale feature notices.'
		);
	}
}
