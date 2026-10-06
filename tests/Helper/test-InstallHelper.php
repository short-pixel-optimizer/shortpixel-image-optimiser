<?php
/**
 * Tests for ShortPixel\Helper\InstallHelper.
 *
 * These exercise the database-schema methods against the WordPress test DB.
 * The full lifecycle methods (activatePlugin, deactivatePlugin, uninstallPlugin,
 * hardUninstall, deactivateConflictingPlugin) touch many controllers, cron jobs,
 * .htaccess files, and superglobals; they are outside the scope of unit tests
 * and should be covered with integration tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Helper\InstallHelper;

class InstallHelperTest extends WP_UnitTestCase {

	/**
	 * Custom tables managed by InstallHelper (without prefix).
	 */
	private const SPIO_TABLES = array(
		'shortpixel_folders',
		'shortpixel_meta',
		'shortpixel_postmeta',
		'shortpixel_aipostmeta',
	);

	protected function drop_spio_tables() {
		global $wpdb;
		foreach ( self::SPIO_TABLES as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	public function set_up() {
		parent::set_up();

		// WP_UnitTestCase's parent::set_up() wires two `query` filters —
		// `_create_temporary_tables` and `_drop_temporary_tables` — that
		// rewrite every `CREATE TABLE` / `DROP TABLE` into the TEMPORARY
		// variant so per-test DDL doesn't leak. TEMPORARY tables are
		// invisible to `SHOW TABLES LIKE` (and to `information_schema.tables`),
		// so `InstallHelper::checkTableExists()` — the very method under
		// test — cannot see them.
		//
		// Since this test class specifically exercises the SHOW-TABLES-based
		// existence probe + the DROP-TABLE path, we detach both filters so
		// CREATE / DROP execute as real DDL. tear_down() drops the real
		// tables so no state leaks into other test classes.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// Now safe to issue a real DROP — cleans up any leftover from a
		// prior test that crashed before its tear_down ran.
		$this->drop_spio_tables();
	}

	public function tear_down() {
		// Filters were removed in set_up(); DROP TABLE runs as real DDL,
		// so the tables created during the test are removed here rather
		// than leaking into the shared MySQL schema.
		$this->drop_spio_tables();
		parent::tear_down();
	}

	/*
	 * checkTableExists
	 */

	public function test_checkTableExists_returns_false_when_missing() {
		$this->assertFalse( InstallHelper::checkTableExists( 'shortpixel_folders' ) );
	}

	public function test_checkTableExists_returns_true_after_checkTables() {
		InstallHelper::checkTables();
		foreach ( self::SPIO_TABLES as $table ) {
			$this->assertTrue(
				InstallHelper::checkTableExists( $table ),
				"Table {$table} should exist after checkTables()."
			);
		}
	}

	public function test_checkTableExists_returns_false_for_unknown_name() {
		$this->assertFalse( InstallHelper::checkTableExists( 'not_a_real_plugin_table_' . uniqid() ) );
	}

	/*
	 * checkTables — end-to-end schema creation
	 */

	public function test_checkTables_creates_all_plugin_tables() {
		global $wpdb;

		InstallHelper::checkTables();

		foreach ( self::SPIO_TABLES as $table ) {
			$full = $wpdb->prefix . $table;
			$row  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			$this->assertSame( $full, $row, "Expected table {$full} to be created." );
		}
	}

	public function test_checkTables_is_idempotent() {
		InstallHelper::checkTables();
		// Second call must not error nor destroy the tables.
		InstallHelper::checkTables();

		foreach ( self::SPIO_TABLES as $table ) {
			$this->assertTrue(
				InstallHelper::checkTableExists( $table ),
				"Table {$table} missing after idempotent checkTables()."
			);
		}
	}

	public function test_checkTables_creates_expected_indexes() {
		global $wpdb;

		InstallHelper::checkTables();

		$expected = array(
			'shortpixel_meta'       => array( 'path' ),
			'shortpixel_folders'    => array( 'path' ),
			'shortpixel_postmeta'   => array( 'attach_id', 'parent', 'size', 'status', 'compression_type' ),
			'shortpixel_aipostmeta' => array( 'attach_id' ),
		);

		foreach ( $expected as $table => $indexes ) {
			$full = $wpdb->prefix . $table;
			foreach ( $indexes as $indexName ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$full} WHERE Key_name = %s", $indexName ) ); // phpcs:ignore WordPress.DB
				$this->assertNotNull( $row, "Index {$indexName} should exist on {$full}." );
			}
		}
	}

	/*
	 * removeTables (private — invoked via reflection)
	 */

	public function test_removeTables_drops_all_plugin_tables_when_present() {
		InstallHelper::checkTables();
		// Sanity check: tables must exist before we try to remove them.
		foreach ( self::SPIO_TABLES as $table ) {
			$this->assertTrue(
				InstallHelper::checkTableExists( $table ),
				"Sanity check failed — {$table} was not created by checkTables()."
			);
		}

		$ref    = new ReflectionClass( InstallHelper::class );
		$method = $ref->getMethod( 'removeTables' );
		$method->setAccessible( true );
		$method->invoke( null );

		foreach ( self::SPIO_TABLES as $table ) {
			$this->assertFalse(
				InstallHelper::checkTableExists( $table ),
				"Table {$table} should have been dropped by removeTables()."
			);
		}
	}

	public function test_removeTables_is_safe_when_tables_are_already_absent() {
		// Tables are dropped in set_up(), so this is the "already absent" state.
		$ref    = new ReflectionClass( InstallHelper::class );
		$method = $ref->getMethod( 'removeTables' );
		$method->setAccessible( true );

		// Must not throw — removeTables() checks existence before issuing DROP.
		$method->invoke( null );

		foreach ( self::SPIO_TABLES as $table ) {
			$this->assertFalse( InstallHelper::checkTableExists( $table ) );
		}
	}

	/*
	 * Private SQL builder methods (invoked via reflection).
	 *
	 * These already run end-to-end via checkTables(), but direct string
	 * assertions catch regressions in the schema definitions (renamed columns,
	 * missing PRIMARY KEY, wrong table prefix) with a much clearer failure
	 * message than "column X missing from table Y".
	 */

	private function invokeSqlBuilder( string $methodName ): string {
		$ref    = new ReflectionClass( InstallHelper::class );
		$method = $ref->getMethod( $methodName );
		$method->setAccessible( true );
		return (string) $method->invoke( null );
	}

	public function test_getFolderTableSQL_defines_folders_schema() {
		global $wpdb;
		$sql = $this->invokeSqlBuilder( 'getFolderTableSQL' );

		$this->assertStringContainsString( 'CREATE TABLE',                                    $sql );
		$this->assertStringContainsString( $wpdb->prefix . 'shortpixel_folders',              $sql );
		$this->assertStringContainsString( 'PRIMARY KEY',                                     $sql );
		foreach ( array( 'path', 'name', 'path_md5', 'file_count', 'status', 'parent' ) as $col ) {
			$this->assertStringContainsString( $col, $sql, "folders SQL missing column {$col}" );
		}
	}

	public function test_getMetaTableSQL_defines_meta_schema() {
		global $wpdb;
		$sql = $this->invokeSqlBuilder( 'getMetaTableSQL' );

		$this->assertStringContainsString( 'CREATE TABLE',                             $sql );
		$this->assertStringContainsString( $wpdb->prefix . 'shortpixel_meta',          $sql );
		$this->assertStringContainsString( 'PRIMARY KEY',                              $sql );
		foreach ( array( 'folder_id', 'compressed_size', 'compression_type', 'keep_exif', 'cmyk2rgb', 'extra_info' ) as $col ) {
			$this->assertStringContainsString( $col, $sql, "meta SQL missing column {$col}" );
		}
	}

	public function test_getPostMetaSQL_defines_postmeta_schema() {
		global $wpdb;
		$sql = $this->invokeSqlBuilder( 'getPostMetaSQL' );

		$this->assertStringContainsString( 'CREATE TABLE',                                    $sql );
		$this->assertStringContainsString( $wpdb->prefix . 'shortpixel_postmeta',             $sql );
		$this->assertStringContainsString( 'PRIMARY KEY',                                     $sql );
		foreach ( array( 'attach_id', 'parent', 'image_type', 'size', 'status', 'compression_type', 'compressed_size', 'original_size' ) as $col ) {
			$this->assertStringContainsString( $col, $sql, "postmeta SQL missing column {$col}" );
		}
	}

	public function test_getAIPostSQL_defines_aipostmeta_schema() {
		global $wpdb;
		$sql = $this->invokeSqlBuilder( 'getAIPostSQL' );

		$this->assertStringContainsString( 'CREATE TABLE',                                     $sql );
		$this->assertStringContainsString( $wpdb->prefix . 'shortpixel_aipostmeta',            $sql );
		$this->assertStringContainsString( 'PRIMARY KEY',                                      $sql );
		foreach ( array( 'post_type', 'attach_id', 'original_data', 'generated_data', 'old_filename', 'new_filename' ) as $col ) {
			$this->assertStringContainsString( $col, $sql, "aipostmeta SQL missing column {$col}" );
		}
	}

	public function test_all_sql_builders_use_current_wpdb_prefix() {
		global $wpdb;
		foreach ( array( 'getFolderTableSQL', 'getMetaTableSQL', 'getPostMetaSQL', 'getAIPostSQL' ) as $method ) {
			$sql = $this->invokeSqlBuilder( $method );
			$this->assertStringContainsString( 'CREATE TABLE ' . $wpdb->prefix, $sql, "{$method} should use the wpdb prefix." );
		}
	}

	public function test_created_tables_have_expected_columns() {
		global $wpdb;

		InstallHelper::checkTables();

		// Spot-check a distinctive column from each table so we know the correct
		// CREATE TABLE ran (rather than some legacy schema left over from a
		// previous run).
		$columnChecks = array(
			'shortpixel_folders'    => 'path_md5',
			'shortpixel_meta'       => 'compressed_size',
			'shortpixel_postmeta'   => 'attach_id',
			'shortpixel_aipostmeta' => 'generated_data',
		);

		foreach ( $columnChecks as $table => $column ) {
			$full    = $wpdb->prefix . $table;
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$full}", 0 ); // phpcs:ignore WordPress.DB
			$this->assertContains( $column, $columns, "Column {$column} missing from {$full}." );
		}
	}
}
