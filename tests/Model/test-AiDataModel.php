<?php
/**
 * Tests for ShortPixel\Model\AiDataModel.
 *
 * Covers the pure-logic surface plus a real DB roundtrip against the
 * `shortpixel_aipostmeta` table (created by InstallHelper::activatePlugin
 * during the test harness bootstrap). Each test cleans up after itself.
 *
 * Several tests are pinned to the *intended* contract of methods that
 * currently ship with real bugs — those tests are named
 * `*_pinned_for_deferred_fix` and will FAIL until the corresponding fixes
 * land (see `project_deferred_image_folder_bugs.md` for details):
 *
 *   - Bug A: `updateRecord` INSERT captures `wpdb->insert()`'s rows-affected
 *            return instead of `wpdb->insert_id` → wrong PK stored.
 *   - Bug B: `onDelete` flushes cache with `$this->id` (PK) instead of
 *            `$this->attach_id` (the actual cache key).
 *   - Bug C: `getMostRecent` checks `false === $attach_id` after
 *            `wpdb->get_var()`, which returns null (not false) on empty.
 *   - Bug E: Constructor only maps type='media' → TYPE_MEDIA; passing
 *            'custom' silently leaves `type` null and the fetch produces 0 rows.
 *
 * Skipped at the unit level (integration territory):
 *   - `getOptimizeData` full flow → depends on `wpSPIO()->filesystem()->
 *     getMediaImage()` returning a hydrated ImageModel with `hasOriginal`
 *     / `getOriginalFile` / `getFileName` / `getExtension`. Constructing
 *     a media item + convertMeta family requires attachment fixtures.
 *   - `handleNewData` end-to-end → wires WordPress post + attachment meta +
 *     SPIO DB together.
 *   - `updateWPPost`, `updateWpMeta`, `setCurrentData`, `getConnectedPostTitle`
 *     → all wp_* calls against real attachments; covered by integration.
 *   - `isExtensionIncluded` → calls `getMediaImage()`; needs an attachment
 *     with a real file extension.
 *   - `revert` → orchestrator over updateWPPost + updateWpMeta.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AiDataModel;
use ShortPixel\Helper\InstallHelper;

class AiDataModelTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Ensure the AI post-meta table is present (harmless if already installed).
		InstallHelper::checkTables();

		// Empty the AI table and the static in-memory cache so tests stay isolated.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_aipostmeta' );
		$this->flushStaticCache();
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_aipostmeta' );
		$this->flushStaticCache();

		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function flushStaticCache(): void {
		$ref = new ReflectionClass( AiDataModel::class );
		$p   = $ref->getProperty( 'models' );
		$p->setAccessible( true );
		$p->setValue( null, array() );
	}

	private function getPrivate( AiDataModel $m, string $prop ) {
		$ref = new ReflectionClass( AiDataModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function setPrivate( AiDataModel $m, string $prop, $value ): void {
		$ref = new ReflectionClass( AiDataModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	private function invokePrivate( AiDataModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( AiDataModel::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	private function freshModel(): AiDataModel {
		$ref = new ReflectionClass( AiDataModel::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function tableName(): string {
		global $wpdb;
		return $wpdb->prefix . 'shortpixel_aipostmeta';
	}

	private function makeAttachmentId(): int {
		return self::factory()->post->create( array( 'post_type' => 'attachment' ) );
	}

	/*
	 * Constants — sanity pins
	 */

	public function test_type_constants_have_expected_values() {
		$this->assertSame( 1, AiDataModel::TYPE_MEDIA );
		$this->assertSame( 2, AiDataModel::TYPE_CUSTOM );
	}

	public function test_ai_status_constants_have_expected_values() {
		$this->assertSame( 0, AiDataModel::AI_STATUS_NOTHING );
		$this->assertSame( 1, AiDataModel::AI_STATUS_GENERATED );
	}

	public function test_processable_status_constants_have_expected_values() {
		$this->assertSame( 0, AiDataModel::P_PROCESSABLE );
		$this->assertSame( 1, AiDataModel::P_ALREADYDONE );
		$this->assertSame( 2, AiDataModel::P_EXIFAI );
		$this->assertSame( 3, AiDataModel::P_EXTENSION );
		$this->assertSame( 4, AiDataModel::P_NOJOB );
		$this->assertSame( 5, AiDataModel::P_NOFIELDS );
	}

	public function test_field_status_constants_have_expected_values() {
		$this->assertSame( 1, AiDataModel::F_STATUS_OK );
		$this->assertSame( -3, AiDataModel::F_STATUS_EXCLUDESETTING );
		$this->assertSame( -4, AiDataModel::F_STATUS_PREVENTOVERRIDE );
	}

	/*
	 * checkRowData (private) — JSON parsing
	 */

	public function test_checkRowData_returns_decoded_array_for_valid_json() {
		$out = $this->invokePrivate( $this->freshModel(), 'checkRowData', array( '{"alt":"hello","caption":"world"}' ) );

		$this->assertSame( array( 'alt' => 'hello', 'caption' => 'world' ), $out );
	}

	public function test_checkRowData_returns_empty_array_for_invalid_json() {
		$out = $this->invokePrivate( $this->freshModel(), 'checkRowData', array( 'not-json{{' ) );

		$this->assertSame( array(), $out );
	}

	public function test_checkRowData_returns_empty_array_for_empty_string() {
		$out = $this->invokePrivate( $this->freshModel(), 'checkRowData', array( '' ) );

		$this->assertSame( array(), $out );
	}

	/*
	 * Trivial getters
	 */

	public function test_getStatus_returns_the_stored_status_value() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'status', AiDataModel::AI_STATUS_GENERATED );

		$this->assertSame( AiDataModel::AI_STATUS_GENERATED, $m->getStatus() );
	}

	public function test_getAttachId_returns_the_stored_attach_id() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'attach_id', 42 );

		$this->assertSame( 42, $m->getAttachId() );
	}

	public function test_getGeneratedData_returns_the_generated_field_map() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'generated', array( 'alt' => 'my alt', 'caption' => 'my caption' ) );

		$this->assertSame(
			array( 'alt' => 'my alt', 'caption' => 'my caption' ),
			$m->getGeneratedData()
		);
	}

	public function test_getOriginalData_returns_the_original_field_map() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'original', array( 'alt' => 'orig alt', 'caption' => 'orig cap' ) );

		$this->assertSame(
			array( 'alt' => 'orig alt', 'caption' => 'orig cap' ),
			$m->getOriginalData()
		);
	}

	/*
	 * mapWPVars (private) — filter callback
	 */

	public function test_mapWPVars_returns_true_for_wp_supported_fields() {
		$m = $this->freshModel();
		foreach ( array( 'alt', 'caption', 'description' ) as $key ) {
			$this->assertTrue(
				$this->invokePrivate( $m, 'mapWPVars', array( $key ) ),
				"'$key' should be considered a WP-supported field"
			);
		}
	}

	public function test_mapWPVars_returns_false_for_other_fields() {
		$m = $this->freshModel();
		foreach ( array( 'post_title', 'filebase', 'filename', 'unknown' ) as $key ) {
			$this->assertFalse(
				$this->invokePrivate( $m, 'mapWPVars', array( $key ) ),
				"'$key' should NOT be considered a WP-supported field"
			);
		}
	}

	/*
	 * getProcessableReason — pure switch table
	 */

	public function test_getProcessableReason_returns_the_raw_status_when_returnStatus_true() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'processable_status', AiDataModel::P_EXIFAI );

		$this->assertSame( AiDataModel::P_EXIFAI, $m->getProcessableReason( true ) );
	}

	public function test_getProcessableReason_returns_translated_string_for_each_known_status() {
		$m = $this->freshModel();

		$expectations = array(
			AiDataModel::P_PROCESSABLE => 'processable',
			AiDataModel::P_ALREADYDONE => 'generated data',
			AiDataModel::P_EXIFAI      => 'Exif',
			AiDataModel::P_EXTENSION   => 'not supported',
			AiDataModel::P_NOJOB       => 'No fields',
		);

		foreach ( $expectations as $status => $needle ) {
			$this->setPrivate( $m, 'processable_status', $status );
			$this->assertStringContainsString(
				$needle,
				$m->getProcessableReason(),
				"Status $status should mention '$needle'"
			);
		}
	}

	public function test_getProcessableReason_returns_unknown_status_string_for_unmapped_code() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'processable_status', 999 );

		$this->assertStringContainsString( 'unknown', $m->getProcessableReason() );
	}

	/*
	 * isSomeThingGenerated — pure logic
	 */

	public function test_isSomeThingGenerated_false_when_no_record_exists() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'has_record', false );

		$this->assertFalse( $m->isSomeThingGenerated() );
	}

	public function test_isSomeThingGenerated_false_when_record_exists_but_generated_is_empty() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'has_record', true );
		$this->setPrivate( $m, 'generated', array( 'alt' => null, 'caption' => null, 'description' => null ) );

		$this->assertFalse( $m->isSomeThingGenerated() );
	}

	public function test_isSomeThingGenerated_true_when_at_least_one_generated_field_is_non_empty() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'has_record', true );
		$this->setPrivate( $m, 'generated', array( 'alt' => 'x', 'caption' => null ) );

		$this->assertTrue( $m->isSomeThingGenerated() );
	}

	/*
	 * isProcessable — early-return when a record already exists
	 */

	public function test_isProcessable_returns_false_and_sets_P_ALREADYDONE_when_record_exists() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'has_record', true );

		$this->assertFalse( $m->isProcessable() );
		$this->assertSame( AiDataModel::P_ALREADYDONE, $m->getProcessableReason( true ) );
	}

	/*
	 * isExifProcessable (private) — currently always true (dead-code branch)
	 */

	public function test_isExifProcessable_currently_returns_true_unconditionally() {
		$this->assertTrue(
			$this->invokePrivate( $this->freshModel(), 'isExifProcessable' )
		);
	}

	/*
	 * Static cache — getModelByAttachment + flushModelCache
	 */

	public function test_getModelByAttachment_returns_the_same_instance_on_second_call() {
		$attach_id = $this->makeAttachmentId();

		$a = AiDataModel::getModelByAttachment( $attach_id );
		$b = AiDataModel::getModelByAttachment( $attach_id );

		$this->assertSame( $a, $b );
	}

	public function test_flushModelCache_removes_the_cached_instance() {
		$attach_id = $this->makeAttachmentId();
		$before    = AiDataModel::getModelByAttachment( $attach_id );

		AiDataModel::flushModelCache( $attach_id );
		$after = AiDataModel::getModelByAttachment( $attach_id );

		$this->assertNotSame( $before, $after );
	}

	public function test_flushModelCache_is_a_noop_for_unknown_attach_id() {
		$attach_id = $this->makeAttachmentId();
		$cached    = AiDataModel::getModelByAttachment( $attach_id );

		// Different id — should not blow up, and the original cache entry stays.
		AiDataModel::flushModelCache( 999999 );

		$this->assertSame( $cached, AiDataModel::getModelByAttachment( $attach_id ) );
	}

	/*
	 * Constructor — attach_id + type mapping
	 */

	public function test_constructor_stores_attach_id() {
		$attach_id = $this->makeAttachmentId();

		$m = new AiDataModel( $attach_id );

		$this->assertSame( $attach_id, $m->getAttachId() );
	}

	public function test_constructor_maps_type_media_string_to_TYPE_MEDIA_constant() {
		$attach_id = $this->makeAttachmentId();

		$m = new AiDataModel( $attach_id, 'media' );

		$this->assertSame( AiDataModel::TYPE_MEDIA, $this->getPrivate( $m, 'type' ) );
	}

	/**
	 * PINNED for deferred fix (Bug E). The constructor only maps
	 * `type='media'` — passing 'custom' silently leaves the `$type`
	 * property null, and the subsequent fetchRecord query returns zero
	 * rows because `%d` coerces null to 0.
	 *
	 * The intended behaviour is that 'custom' maps to TYPE_CUSTOM.
	 * This test will FAIL until the fix lands.
	 */
	public function test_constructor_maps_type_custom_string_to_TYPE_CUSTOM_constant_pinned_for_deferred_fix() {
		$attach_id = $this->makeAttachmentId();

		$m = new AiDataModel( $attach_id, 'custom' );

		$this->assertSame( AiDataModel::TYPE_CUSTOM, $this->getPrivate( $m, 'type' ) );
	}

	public function test_constructor_leaves_has_record_false_when_no_row_exists_for_attachment() {
		$attach_id = $this->makeAttachmentId();

		$m = new AiDataModel( $attach_id );

		$this->assertFalse( $this->getPrivate( $m, 'has_record' ) );
	}

	public function test_constructor_hydrates_original_and_generated_from_existing_row() {
		global $wpdb;
		$attach_id = $this->makeAttachmentId();

		$wpdb->insert( $this->tableName(), array(
			'attach_id'      => $attach_id,
			'status'         => AiDataModel::AI_STATUS_GENERATED,
			'original_data'  => json_encode( array( 'alt' => 'orig' ) ),
			'generated_data' => json_encode( array( 'alt' => 'gen' ) ),
		), array( '%d', '%d', '%s', '%s' ) );

		$m = new AiDataModel( $attach_id );

		$this->assertTrue( $this->getPrivate( $m, 'has_record' ) );
		$this->assertSame( AiDataModel::AI_STATUS_GENERATED, $m->getStatus() );

		$original = $m->getOriginalData();
		$this->assertSame( 'orig', $original['alt'] );

		$generated = $m->getGeneratedData();
		$this->assertSame( 'gen', $generated['alt'] );
	}

	/*
	 * getMostRecent — DB routing + empty-table handling
	 */

	public function test_getMostRecent_returns_model_for_the_last_updated_row() {
		global $wpdb;
		$old = $this->makeAttachmentId();
		$new = $this->makeAttachmentId();

		$wpdb->insert( $this->tableName(), array(
			'attach_id'     => $old,
			'status'        => 1,
			'original_data' => '{}',
			'generated_data' => '{}',
			'tsUpdated'     => '2020-01-01 00:00:00',
		), array( '%d', '%d', '%s', '%s', '%s' ) );

		$wpdb->insert( $this->tableName(), array(
			'attach_id'     => $new,
			'status'        => 1,
			'original_data' => '{}',
			'generated_data' => '{}',
			'tsUpdated'     => '2026-07-07 12:00:00',
		), array( '%d', '%d', '%s', '%s', '%s' ) );

		$model = AiDataModel::getMostRecent();

		$this->assertInstanceOf( AiDataModel::class, $model );
		$this->assertSame( $new, $model->getAttachId() );
	}

	/**
	 * PINNED for deferred fix (Bug C). `wpdb->get_var()` returns `null`
	 * for an empty result set, not `false`. The current check
	 * `if (false === $attach_id)` never fires; execution falls through
	 * to `new AiDataModel(null)`.
	 *
	 * The intended behaviour is to return false for an empty table.
	 * This test will FAIL until the fix lands.
	 */
	public function test_getMostRecent_returns_false_when_the_table_is_empty_pinned_for_deferred_fix() {
		$this->assertFalse( AiDataModel::getMostRecent() );
	}

	/*
	 * updateRecord — INSERT / UPDATE and the PK-capture bug
	 */

	/**
	 * PINNED for deferred fix (Bug A). Currently
	 * `$this->id = $wpdb->insert(...)` — but `wpdb->insert()` returns the
	 * rows-affected count (usually 1), NOT the inserted primary key. To
	 * get the PK, use `$wpdb->insert_id`.
	 *
	 * The intended behaviour is that `$this->id` matches `wpdb->insert_id`
	 * after INSERT. This test will FAIL until the fix lands.
	 */
	public function test_updateRecord_INSERT_captures_the_wpdb_insert_id_pinned_for_deferred_fix() {
		global $wpdb;
		$attach_id = $this->makeAttachmentId();

		$m = new AiDataModel( $attach_id );
		$this->setPrivate( $m, 'generated', array( 'alt' => 'x' ) );
		$this->setPrivate( $m, 'original', array( 'alt' => 'y' ) );
		$this->setPrivate( $m, 'status', AiDataModel::AI_STATUS_GENERATED );

		$this->invokePrivate( $m, 'updateRecord' );

		$expected_pk = (int) $wpdb->get_var( 'SELECT id FROM ' . $this->tableName() . ' WHERE attach_id = ' . $attach_id );

		$this->assertSame( $expected_pk, $this->getPrivate( $m, 'id' ) );
	}

	public function test_updateRecord_INSERT_writes_the_row_with_correct_data() {
		global $wpdb;
		$attach_id = $this->makeAttachmentId();

		$m = new AiDataModel( $attach_id );
		$this->setPrivate( $m, 'generated', array( 'alt' => 'ai-alt' ) );
		$this->setPrivate( $m, 'original', array( 'alt' => 'orig-alt' ) );
		$this->setPrivate( $m, 'status', AiDataModel::AI_STATUS_GENERATED );

		$this->invokePrivate( $m, 'updateRecord' );

		$row = $wpdb->get_row( 'SELECT * FROM ' . $this->tableName() . ' WHERE attach_id = ' . $attach_id );

		$this->assertNotNull( $row );
		$this->assertSame( AiDataModel::AI_STATUS_GENERATED, (int) $row->status );
		$this->assertSame( array( 'alt' => 'orig-alt' ), (array) json_decode( $row->original_data, true ) );
		$this->assertSame( array( 'alt' => 'ai-alt' ), (array) json_decode( $row->generated_data, true ) );
	}

	public function test_updateRecord_flips_has_record_true_after_insert() {
		$attach_id = $this->makeAttachmentId();
		$m         = new AiDataModel( $attach_id );

		$this->assertFalse( $this->getPrivate( $m, 'has_record' ) );

		$this->invokePrivate( $m, 'updateRecord' );

		$this->assertTrue( $this->getPrivate( $m, 'has_record' ) );
	}

	/*
	 * onDelete — DB row deletion + cache eviction
	 */

	public function test_onDelete_removes_the_database_row() {
		global $wpdb;
		$attach_id = $this->makeAttachmentId();

		$wpdb->insert( $this->tableName(), array(
			'attach_id'      => $attach_id,
			'status'         => 1,
			'original_data'  => '{}',
			'generated_data' => '{}',
		), array( '%d', '%d', '%s', '%s' ) );

		$m = new AiDataModel( $attach_id );
		$this->assertTrue( $this->getPrivate( $m, 'has_record' ) );

		$m->onDelete();

		$this->assertFalse( $this->getPrivate( $m, 'has_record' ) );

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->tableName() . ' WHERE attach_id = ' . $attach_id );
		$this->assertSame( 0, $count );
	}

	/**
	 * PINNED for deferred fix (Bug B). The current code calls
	 * `self::flushModelCache($this->id)` — but the cache is keyed by
	 * `attach_id`, not by the primary key. So the intended eviction
	 * misses, and a subsequent `getModelByAttachment` returns the
	 * previously cached (stale) instance instead of a fresh one.
	 *
	 * The intended behaviour is that after onDelete(), a subsequent
	 * getModelByAttachment call returns a fresh instance. This test
	 * will FAIL until the flushModelCache argument is corrected to
	 * `$this->attach_id`.
	 */
	public function test_onDelete_evicts_the_cache_by_attach_id_pinned_for_deferred_fix() {
		$attach_id = $this->makeAttachmentId();

		$before = AiDataModel::getModelByAttachment( $attach_id );
		$before->onDelete();

		$after = AiDataModel::getModelByAttachment( $attach_id );

		$this->assertNotSame( $before, $after );
	}

	/*
	 * migrate — legacy-shape ingestion
	 */

	public function test_migrate_returns_false_for_non_array_input() {
		$this->assertFalse( $this->freshModel()->migrate( 'not-an-array' ) );
	}

	public function test_migrate_populates_original_and_generated_alt_when_currently_null() {
		global $wpdb;
		$attach_id = $this->makeAttachmentId();
		$m         = new AiDataModel( $attach_id );

		$m->migrate( array( 'original_alt' => 'orig', 'result_alt' => 'ai' ) );

		$this->assertSame( 'orig', $this->getPrivate( $m, 'original' )['alt'] );
		$this->assertSame( 'ai', $this->getPrivate( $m, 'generated' )['alt'] );
		$this->assertSame( AiDataModel::AI_STATUS_GENERATED, $m->getStatus() );
	}

	public function test_migrate_does_not_overwrite_pre_existing_alt_values() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'original', array( 'alt' => 'existing-orig' ) );
		$this->setPrivate( $m, 'generated', array( 'alt' => 'existing-ai' ) );

		$m->migrate( array( 'original_alt' => 'new-orig', 'result_alt' => 'new-ai' ) );

		$this->assertSame( 'existing-orig', $this->getPrivate( $m, 'original' )['alt'] );
		$this->assertSame( 'existing-ai', $this->getPrivate( $m, 'generated' )['alt'] );
	}

	/*
	 * checkStoredData — early-return contract (dead scaffold otherwise)
	 */

	public function test_checkStoredData_returns_true_when_no_record_exists() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'has_record', false );

		$this->assertTrue( $m->checkStoredData() );
	}
}
