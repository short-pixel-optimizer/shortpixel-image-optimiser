<?php
/**
 * Tests for ShortPixel\Model\File\DirectoryOtherMediaModel.
 *
 * Covers the pure-logic surface (loadFolder hydration, get/set,
 * isRemoved, timestampToDB/DBtoTimestamp, cache hit path for getStats)
 * plus DB roundtrips against `shortpixel_folders` and `shortpixel_meta`
 * (installed by InstallHelper::activatePlugin during the test harness
 * bootstrap; also insured via `InstallHelper::checkTables()` at set_up).
 *
 * One test is pinned to the intended contract of a method that ships
 * with a real bug (see `project_deferred_image_folder_bugs.md`):
 *
 *   - `save()` INSERT branch captures `$wpdb->insert()`'s rows-affected
 *     return instead of the actual PK via `$wpdb->insert_id`. The `id`
 *     ends up self-healed by the follow-up `loadFolderByPath()`, but the
 *     return value of `save()` is the rows-affected count (usually 1),
 *     not the inserted primary key.
 *
 * Skipped at the unit level (integration territory):
 *   - refreshFolder → walks the filesystem + queue integration
 *   - updateFileContentChange → recurseLastChangeFile walks disk
 *   - checkDirectory → OtherMediaController + rootDir + backup dir
 *   - addImages → filesystem controller + QueueController
 *   - recurseLastChangeFile → real FS walk
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\File\DirectoryOtherMediaModel;
use ShortPixel\Helper\InstallHelper;

class DirectoryOtherMediaModelTest extends WP_UnitTestCase {

	/** @var string Sandbox directory used by tests that need a real path for the parent DirectoryModel. */
	private $sandbox;

	public function set_up() {
		parent::set_up();

		InstallHelper::checkTables();

		$this->sandbox = sys_get_temp_dir() . '/spio-dirotherm-' . uniqid() . '/';
		mkdir( $this->sandbox, 0755, true );

		$this->cleanTables();
		$this->resetStaticCache();
	}

	public function tear_down() {
		$this->cleanTables();
		$this->resetStaticCache();

		if ( is_dir( $this->sandbox ) ) {
			@rmdir( $this->sandbox );
		}

		parent::tear_down();
	}

	private function cleanTables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_folders' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_meta' );
	}

	private function resetStaticCache(): void {
		$ref = new ReflectionClass( DirectoryOtherMediaModel::class );
		$p   = $ref->getProperty( 'stats' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/*
	 * Reflection helpers
	 */

	private function getPrivate( DirectoryOtherMediaModel $m, string $prop ) {
		$ref = new ReflectionClass( DirectoryOtherMediaModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function setPrivate( DirectoryOtherMediaModel $m, string $prop, $value ): void {
		$ref = new ReflectionClass( DirectoryOtherMediaModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	private function invokePrivate( DirectoryOtherMediaModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( DirectoryOtherMediaModel::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	private function freshModel(): DirectoryOtherMediaModel {
		$ref = new ReflectionClass( DirectoryOtherMediaModel::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function foldersTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'shortpixel_folders';
	}

	private function metaTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'shortpixel_meta';
	}

	/*
	 * Constants
	 */

	public function test_directory_status_constants_have_expected_values() {
		$this->assertSame( -1, DirectoryOtherMediaModel::DIRECTORY_STATUS_REMOVED );
		$this->assertSame( 0, DirectoryOtherMediaModel::DIRECTORY_STATUS_NORMAL );
		$this->assertSame( 1, DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN );
	}

	/*
	 * get() / set() — declared-property accessor pair
	 */

	public function test_get_returns_declared_property_value() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'status', 5 );

		$this->assertSame( 5, $m->get( 'status' ) );
	}

	public function test_get_returns_null_for_unknown_property() {
		$this->assertNull( $this->freshModel()->get( 'not_a_real_field' ) );
	}

	public function test_set_writes_to_declared_property_and_returns_true() {
		$m = $this->freshModel();
		$this->assertTrue( $m->set( 'status', 7 ) );
		$this->assertSame( 7, $this->getPrivate( $m, 'status' ) );
	}

	public function test_set_returns_false_for_unknown_property() {
		$this->assertFalse( $this->freshModel()->set( 'not_a_real_field', 'x' ) );
	}

	/*
	 * isRemoved()
	 */

	public function test_isRemoved_true_when_is_removed_flag_is_set() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'is_removed', true );

		$this->assertTrue( $m->isRemoved() );
	}

	public function test_isRemoved_false_by_default() {
		$this->assertFalse( $this->freshModel()->isRemoved() );
	}

	/*
	 * timestampToDB / DBtoTimestamp (private)
	 */

	public function test_timestampToDB_formats_a_positive_timestamp_correctly() {
		$m = $this->freshModel();
		$out = $this->invokePrivate( $m, 'timestampToDB', array( 1704067200 ) ); // 2024-01-01 00:00:00 UTC

		$this->assertSame( date( 'Y-m-d H:i:s', 1704067200 ), $out );
	}

	public function test_timestampToDB_substitutes_now_for_zero_input() {
		$m      = $this->freshModel();
		$before = time();
		$out    = $this->invokePrivate( $m, 'timestampToDB', array( 0 ) );
		$after  = time();

		$outTs = strtotime( $out );
		$this->assertGreaterThanOrEqual( $before, $outTs );
		$this->assertLessThanOrEqual( $after, $outTs );
	}

	public function test_DBtoTimestamp_returns_now_for_null_input() {
		$m      = $this->freshModel();
		$before = time();
		$out    = $this->invokePrivate( $m, 'DBtoTimestamp', array( null ) );
		$after  = time();

		$this->assertGreaterThanOrEqual( $before, $out );
		$this->assertLessThanOrEqual( $after, $out );
	}

	public function test_DBtoTimestamp_parses_a_valid_date_string() {
		$m   = $this->freshModel();
		$out = $this->invokePrivate( $m, 'DBtoTimestamp', array( '2024-01-01 00:00:00' ) );

		$this->assertSame( strtotime( '2024-01-01 00:00:00' ), $out );
	}

	/*
	 * loadFolder (private) — hydration from a DB row
	 */

	public function test_loadFolder_populates_every_field_from_the_row_object() {
		$m = $this->freshModel();

		$row = (object) array(
			'id'         => 42,
			'name'       => 'My Folder',
			'status'     => DirectoryOtherMediaModel::DIRECTORY_STATUS_NORMAL,
			'file_count' => 12,
			'ts_updated' => '2024-01-02 03:04:05',
			'ts_created' => '2024-01-01 00:00:00',
			'ts_checked' => '2024-01-03 06:07:08',
			'path'       => '/some/path',
		);

		$this->invokePrivate( $m, 'loadFolder', array( $row ) );

		$this->assertSame( 42, $this->getPrivate( $m, 'id' ) );
		$this->assertSame( 'My Folder', $this->getPrivate( $m, 'name' ) );
		$this->assertSame( 12, $this->getPrivate( $m, 'fileCount' ) );
		$this->assertTrue( $this->getPrivate( $m, 'in_db' ) );
		$this->assertSame( strtotime( '2024-01-02 03:04:05' ), $this->getPrivate( $m, 'updated' ) );
		$this->assertSame( strtotime( '2024-01-01 00:00:00' ), $this->getPrivate( $m, 'created' ) );
		$this->assertSame( strtotime( '2024-01-03 06:07:08' ), $this->getPrivate( $m, 'checked' ) );
	}

	public function test_loadFolder_falls_back_to_basename_when_stored_name_is_empty() {
		$m = $this->freshModel();

		$row = (object) array(
			'id'     => 1,
			'name'   => '', // empty
			'status' => 0,
			'path'   => '/a/b/my-folder-name',
		);

		$this->invokePrivate( $m, 'loadFolder', array( $row ) );

		$this->assertSame( 'my-folder-name', $this->getPrivate( $m, 'name' ) );
	}

	public function test_loadFolder_marks_is_removed_true_when_status_is_minus_one() {
		$m = $this->freshModel();

		$row = (object) array(
			'id'     => 1,
			'name'   => 'x',
			'status' => -1,
			'path'   => '/x',
		);

		$this->invokePrivate( $m, 'loadFolder', array( $row ) );

		$this->assertTrue( $this->getPrivate( $m, 'is_removed' ) );
	}

	public function test_loadFolder_marks_is_nextgen_true_when_status_is_nextgen_constant() {
		$m = $this->freshModel();

		$row = (object) array(
			'id'     => 1,
			'name'   => 'x',
			'status' => DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN,
			'path'   => '/x',
		);

		$this->invokePrivate( $m, 'loadFolder', array( $row ) );

		$this->assertTrue( $this->getPrivate( $m, 'is_nextgen' ) );
	}

	public function test_loadFolder_falls_back_to_now_when_timestamp_properties_are_missing() {
		$m = $this->freshModel();

		$row = (object) array(
			'id'     => 1,
			'name'   => 'x',
			'status' => 0,
			'path'   => '/x',
		);

		$before = time();
		$this->invokePrivate( $m, 'loadFolder', array( $row ) );
		$after = time();

		$this->assertGreaterThanOrEqual( $before, $this->getPrivate( $m, 'updated' ) );
		$this->assertLessThanOrEqual( $after, $this->getPrivate( $m, 'updated' ) );
	}

	public function test_loadFolder_leaves_in_db_false_when_id_is_zero() {
		$m = $this->freshModel();

		$row = (object) array(
			'id'     => 0,
			'name'   => 'x',
			'status' => 0,
			'path'   => '/x',
		);

		$this->invokePrivate( $m, 'loadFolder', array( $row ) );

		$this->assertFalse( $this->getPrivate( $m, 'in_db' ) );
	}

	/*
	 * getStats — cache-hit path (no DB touched)
	 */

	public function test_getStats_returns_cached_stats_for_this_folder_id() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'id', 42 );

		// Pre-seed the static cache to short-circuit the DB fallback.
		$cached = array(
			42 => array( 'optimized' => 5, 'waiting' => 3, 'total' => 8 ),
			99 => array( 'optimized' => 1, 'waiting' => 1, 'total' => 2 ),
		);
		$ref = new ReflectionClass( DirectoryOtherMediaModel::class );
		$p   = $ref->getProperty( 'stats' );
		$p->setAccessible( true );
		$p->setValue( null, $cached );

		$this->assertSame(
			array( 'optimized' => 5, 'waiting' => 3, 'total' => 8 ),
			$m->getStats()
		);
	}

	/*
	 * getAllStats — grouped query against real DB
	 */

	public function test_getAllStats_aggregates_optimized_waiting_and_total_by_folder_id() {
		global $wpdb;
		$folder_a = 100;
		$folder_b = 200;

		// Seed two folder buckets. status 2 = optimized, 0 = waiting.
		$wpdb->insert( $this->metaTable(), array( 'folder_id' => $folder_a, 'status' => 2, 'path' => '/a1', 'path_md5' => md5( '/a1' ), 'name' => 'a1' ), array( '%d', '%d', '%s', '%s', '%s' ) );
		$wpdb->insert( $this->metaTable(), array( 'folder_id' => $folder_a, 'status' => 2, 'path' => '/a2', 'path_md5' => md5( '/a2' ), 'name' => 'a2' ), array( '%d', '%d', '%s', '%s', '%s' ) );
		$wpdb->insert( $this->metaTable(), array( 'folder_id' => $folder_a, 'status' => 0, 'path' => '/a3', 'path_md5' => md5( '/a3' ), 'name' => 'a3' ), array( '%d', '%d', '%s', '%s', '%s' ) );
		$wpdb->insert( $this->metaTable(), array( 'folder_id' => $folder_b, 'status' => 0, 'path' => '/b1', 'path_md5' => md5( '/b1' ), 'name' => 'b1' ), array( '%d', '%d', '%s', '%s', '%s' ) );

		$stats = DirectoryOtherMediaModel::getAllStats();

		$this->assertSame( 2, $stats[ $folder_a ]['optimized'] );
		$this->assertSame( 1, $stats[ $folder_a ]['waiting'] );
		$this->assertSame( 3, $stats[ $folder_a ]['total'] );

		$this->assertSame( 0, $stats[ $folder_b ]['optimized'] );
		$this->assertSame( 1, $stats[ $folder_b ]['waiting'] );
		$this->assertSame( 1, $stats[ $folder_b ]['total'] );
	}

	public function test_getAllStats_memoises_the_query_across_repeated_calls() {
		$a = DirectoryOtherMediaModel::getAllStats();
		$b = DirectoryOtherMediaModel::getAllStats();

		$this->assertSame( $a, $b );
	}

	/*
	 * Constructor — object-input branch (fast path that skips the DB query)
	 */

	public function test_constructor_with_row_object_input_uses_loadFolder_directly() {
		$row = (object) array(
			'id'         => 55,
			'name'       => 'stored',
			'status'     => DirectoryOtherMediaModel::DIRECTORY_STATUS_NORMAL,
			'file_count' => 3,
			'ts_updated' => '2024-05-05 05:05:05',
			'ts_created' => '2024-01-01 00:00:00',
			'ts_checked' => '2024-05-05 05:05:05',
			'path'       => $this->sandbox,
		);

		$m = new DirectoryOtherMediaModel( $row );

		$this->assertSame( 55, $this->getPrivate( $m, 'id' ) );
		$this->assertSame( 'stored', $this->getPrivate( $m, 'name' ) );
		$this->assertSame( 3, $this->getPrivate( $m, 'fileCount' ) );
		$this->assertTrue( $this->getPrivate( $m, 'in_db' ) );
	}

	public function test_constructor_with_string_path_and_no_matching_row_leaves_id_at_minus_one() {
		$m = new DirectoryOtherMediaModel( $this->sandbox );

		$this->assertSame( -1, $this->getPrivate( $m, 'id' ) );
		$this->assertFalse( $this->getPrivate( $m, 'in_db' ) );
	}

	public function test_constructor_with_string_path_hydrates_from_matching_folder_row() {
		global $wpdb;
		$wpdb->insert( $this->foldersTable(), array(
			'name'        => 'Persisted',
			'status'      => 0,
			'file_count'  => 7,
			'ts_updated'  => '2024-02-02 02:02:02',
			'ts_created'  => '2024-01-01 00:00:00',
			'ts_checked'  => '2024-02-02 02:02:02',
			'path'        => $this->sandbox,
			'path_md5'    => md5( $this->sandbox ),
		), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );

		$expected_id = (int) $wpdb->insert_id;

		$m = new DirectoryOtherMediaModel( $this->sandbox );

		$this->assertSame( $expected_id, $this->getPrivate( $m, 'id' ) );
		$this->assertSame( 'Persisted', $this->getPrivate( $m, 'name' ) );
		$this->assertSame( 7, $this->getPrivate( $m, 'fileCount' ) );
		$this->assertTrue( $this->getPrivate( $m, 'in_db' ) );
	}

	/*
	 * loadFolderByPath (private) — DB lookup
	 */

	public function test_loadFolderByPath_returns_false_when_no_matching_row_exists() {
		$m = new DirectoryOtherMediaModel( $this->sandbox );

		$this->assertFalse( $this->invokePrivate( $m, 'loadFolderByPath', array( '/definitely/not/here' ) ) );
	}

	public function test_loadFolderByPath_returns_true_and_populates_state_on_a_hit() {
		global $wpdb;
		$wpdb->insert( $this->foldersTable(), array(
			'name'       => 'LookedUp',
			'status'     => 0,
			'file_count' => 4,
			'ts_updated' => '2024-03-03 03:03:03',
			'ts_created' => '2024-01-01 00:00:00',
			'ts_checked' => '2024-03-03 03:03:03',
			'path'       => $this->sandbox,
			'path_md5'   => md5( $this->sandbox ),
		), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
		$row_id = (int) $wpdb->insert_id;

		$m = $this->freshModel();

		$this->assertTrue( $this->invokePrivate( $m, 'loadFolderByPath', array( $this->sandbox ) ) );
		$this->assertSame( $row_id, $this->getPrivate( $m, 'id' ) );
		$this->assertSame( 'LookedUp', $this->getPrivate( $m, 'name' ) );
		$this->assertTrue( $this->getPrivate( $m, 'in_db' ) );
	}

	/*
	 * save() — INSERT + UPDATE roundtrip
	 */

	public function test_save_INSERT_creates_a_new_folders_row_with_the_expected_data() {
		global $wpdb;
		$m = new DirectoryOtherMediaModel( $this->sandbox );
		$this->setPrivate( $m, 'name', 'Fresh' );
		$this->setPrivate( $m, 'status', DirectoryOtherMediaModel::DIRECTORY_STATUS_NORMAL );
		$this->setPrivate( $m, 'fileCount', 2 );
		$this->setPrivate( $m, 'updated', 1704067200 );

		$m->save();

		$row = $wpdb->get_row( 'SELECT * FROM ' . $this->foldersTable() . ' WHERE path = "' . esc_sql( $this->sandbox ) . '"' );

		$this->assertNotNull( $row );
		$this->assertSame( 'Fresh', $row->name );
		$this->assertSame( '0', $row->status );
		$this->assertSame( '2', $row->file_count );
	}

	public function test_save_UPDATE_writes_changes_back_to_an_existing_row() {
		global $wpdb;
		$wpdb->insert( $this->foldersTable(), array(
			'name'       => 'Original',
			'status'     => 0,
			'file_count' => 1,
			'ts_updated' => '2024-01-01 00:00:00',
			'ts_created' => '2024-01-01 00:00:00',
			'ts_checked' => '2024-01-01 00:00:00',
			'path'       => $this->sandbox,
			'path_md5'   => md5( $this->sandbox ),
		), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );

		$m = new DirectoryOtherMediaModel( $this->sandbox );
		$this->setPrivate( $m, 'name', 'Updated Name' );
		$this->setPrivate( $m, 'fileCount', 42 );

		$m->save();

		$row = $wpdb->get_row( 'SELECT * FROM ' . $this->foldersTable() . ' WHERE path = "' . esc_sql( $this->sandbox ) . '"' );

		$this->assertSame( 'Updated Name', $row->name );
		$this->assertSame( '42', $row->file_count );
	}

	/**
	 * PINNED for deferred fix. `save()` INSERT branch does
	 * `$this->id = $wpdb->insert(...)` — which returns rows-affected (1),
	 * not the actual PK. The `$this->id` field is later self-healed by
	 * the follow-up `loadFolderByPath()` call, but the value returned by
	 * `save()` itself is the wrong rows-affected count.
	 *
	 * Intended behaviour: `save()` returns a value that matches the
	 * inserted row's actual PK. This test seeds a dummy row first so
	 * PKs are guaranteed to be > 1 — the rows-affected coincidence
	 * (which would mask the bug for the first insert) is defeated.
	 *
	 * This test will FAIL until save() uses `$wpdb->insert_id`.
	 */
	public function test_save_INSERT_returns_the_actual_inserted_id_pinned_for_deferred_fix() {
		global $wpdb;

		// Insert a dummy row first so subsequent PKs are > 1 (defeats the
		// rows-affected coincidence when PK also happens to be 1).
		$wpdb->insert( $this->foldersTable(), array(
			'name'       => 'sentinel',
			'status'     => 0,
			'file_count' => 0,
			'ts_updated' => '2024-01-01 00:00:00',
			'ts_created' => '2024-01-01 00:00:00',
			'ts_checked' => '2024-01-01 00:00:00',
			'path'       => $this->sandbox . '_sentinel',
			'path_md5'   => md5( $this->sandbox . '_sentinel' ),
		), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );

		$m = new DirectoryOtherMediaModel( $this->sandbox );
		$this->setPrivate( $m, 'name', 'RealInsert' );

		$result = $m->save();

		// The freshly-inserted row's actual PK — should be greater than 1
		// because the sentinel row occupies PK 1.
		$expected_pk = (int) $wpdb->get_var( 'SELECT id FROM ' . $this->foldersTable() . ' WHERE path = "' . esc_sql( $this->sandbox ) . '"' );

		$this->assertGreaterThan( 1, $expected_pk, 'test setup issue: sentinel row not written' );
		$this->assertSame( $expected_pk, (int) $result );
	}

	/*
	 * delete() — soft-delete vs hard-delete
	 */

	public function test_delete_hard_deletes_the_folder_row_when_no_meta_rows_remain() {
		global $wpdb;
		$wpdb->insert( $this->foldersTable(), array(
			'name'       => 'ToDelete',
			'status'     => 0,
			'file_count' => 0,
			'ts_updated' => '2024-01-01 00:00:00',
			'ts_created' => '2024-01-01 00:00:00',
			'ts_checked' => '2024-01-01 00:00:00',
			'path'       => $this->sandbox,
			'path_md5'   => md5( $this->sandbox ),
		), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );

		$m = new DirectoryOtherMediaModel( $this->sandbox );

		$m->delete();

		$still_there = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->foldersTable() . ' WHERE path = "' . esc_sql( $this->sandbox ) . '"' );
		$this->assertSame( 0, $still_there );
	}

	public function test_delete_soft_deletes_when_optimized_meta_rows_survive_the_cleanup() {
		global $wpdb;
		$wpdb->insert( $this->foldersTable(), array(
			'name'       => 'HasOptimized',
			'status'     => 0,
			'file_count' => 0,
			'ts_updated' => '2024-01-01 00:00:00',
			'ts_created' => '2024-01-01 00:00:00',
			'ts_checked' => '2024-01-01 00:00:00',
			'path'       => $this->sandbox,
			'path_md5'   => md5( $this->sandbox ),
		), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );

		$folder_id = (int) $wpdb->insert_id;

		// One optimized (status=2) survives; one waiting (status=0) is purged.
		$wpdb->insert( $this->metaTable(), array( 'folder_id' => $folder_id, 'status' => 2, 'path' => '/opt', 'path_md5' => md5( '/opt' ), 'name' => 'opt' ), array( '%d', '%d', '%s', '%s', '%s' ) );
		$wpdb->insert( $this->metaTable(), array( 'folder_id' => $folder_id, 'status' => 0, 'path' => '/wait', 'path_md5' => md5( '/wait' ), 'name' => 'wait' ), array( '%d', '%d', '%s', '%s', '%s' ) );

		$m = new DirectoryOtherMediaModel( $this->sandbox );

		$m->delete();

		// Folder row still exists, but with status = -1 (soft-deleted).
		$row = $wpdb->get_row( 'SELECT status FROM ' . $this->foldersTable() . ' WHERE id = ' . $folder_id );
		$this->assertNotNull( $row );
		$this->assertSame( -1, (int) $row->status );

		// The optimized meta row survives; the waiting one was purged.
		$remaining = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->metaTable() . ' WHERE folder_id = ' . $folder_id );
		$this->assertSame( 1, $remaining );
	}
}
