<?php
/**
 * Cross-plugin compatibility: WPML Media Translation.
 *
 * Runs with the REAL WPML Media Translation add-on active on top of
 * sitepress-multilingual-cms (both commercial — zips dropped into
 * tests/partner-plugins/, gitignored; every test here SKIPS without them).
 *
 * Media Translation's effect on SPIO: a translated attachment can point
 * at its OWN physical file instead of sharing the original's. SPIO's
 * same-file filter in MediaLibraryModel::getWPMLDuplicates() must then
 * treat it as an independent image:
 *
 *   - it is NOT a duplicate (no meta propagation, no API dedupe);
 *   - it optimizes independently (its own API call);
 *   - deleting the original must not touch the translation's file;
 *   - the AI requestAlt fan-out must not fire for it.
 *
 * Same-file duplicates (Media Translation's initial state before a
 * translator attaches a different file) must keep behaving exactly as in
 * test-CompatWPML.php even with the add-on's attachment hooks loaded.
 *
 * The icl_translations rows are seeded per test (same approach as
 * test-CompatWPML.php — the setup wizard never ran in this install, so
 * Media Translation's own duplication UI flow is not exercisable here).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;

class CompatWPMLMediaTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! class_exists( 'SitePress' ) || ! defined( 'WPML_MEDIA_VERSION' ) ) {
			$this->markTestSkipped( 'WPML Media Translation is not loaded — drop the sitepress + wpml-media-translation zips into tests/partner-plugins/ and run bin/test.sh --compat.' );
		}

		// DDL auto-commits, so the table must exist BEFORE the test
		// transaction starts in parent::set_up().
		$this->ensureIclTranslationsTable();

		parent::set_up();
	}

	/** Same fallback as CompatWPMLTest — WPML only creates the table once its wizard ran. */
	private function ensureIclTranslationsTable(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}
		$wpdb->query(
			"CREATE TABLE {$table} (
				translation_id bigint unsigned NOT NULL AUTO_INCREMENT,
				element_type varchar(60) NOT NULL DEFAULT 'post_post',
				element_id bigint unsigned NULL,
				trid bigint unsigned NOT NULL,
				language_code varchar(7) NOT NULL,
				source_language_code varchar(7) NULL,
				PRIMARY KEY (translation_id)
			)"
		);
	}

	private function insertTranslationRow( int $element_id, int $trid, string $lang, ?string $source = null ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'icl_translations',
			array(
				'element_type'         => 'post_attachment',
				'element_id'           => $element_id,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => $source,
			)
		);
	}

	/** A second attachment record pointing at the SAME file on disk (pre-translation duplicate). */
	private function createDuplicateAttachment( int $source_id ): int {
		$dup_id = wp_insert_attachment(
			array(
				'post_mime_type' => get_post_mime_type( $source_id ),
				'post_title'     => get_the_title( $source_id ) . ' (translation)',
				'post_status'    => 'inherit',
			),
			get_attached_file( $source_id )
		);
		$this->assertGreaterThan( 0, $dup_id, 'Duplicate attachment must be created.' );
		update_post_meta( $dup_id, '_wp_attachment_metadata', wp_get_attachment_metadata( $source_id ) );
		return $dup_id;
	}

	/**
	 * The Media Translation end state: a translation attachment with its
	 * OWN uploaded file, linked to the original via the same trid.
	 *
	 * @return int The translated attachment's ID.
	 */
	private function createOwnFileTranslation( int $original_id, int $trid, string $lang ): int {
		$translated_id = $this->uploadFixture( 'fixture-small.png' );
		$this->insertTranslationRow( $original_id, $trid, 'en' );
		$this->insertTranslationRow( $translated_id, $trid, $lang, 'en' );

		$this->assertNotSame(
			get_attached_file( $original_id ),
			get_attached_file( $translated_id ),
			'Precondition: the translation must point at its own physical file.'
		);
		return $translated_id;
	}

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	// -------------------------------------------------------------------
	// Coexistence
	// -------------------------------------------------------------------

	public function test_wpml_media_translation_loads_alongside_spio() {
		$this->assertTrue( defined( 'ICL_SITEPRESS_VERSION' ), 'WPML core must be active.' );
		$this->assertTrue( defined( 'WPML_MEDIA_VERSION' ), 'Media Translation version constant must be defined.' );
		$this->assertTrue( \wpSPIO()->env()->plugin_active( 'wpml' ), "SPIO's environment must still detect WPML as active." );
	}

	// -------------------------------------------------------------------
	// Own-file translations are independent images
	// -------------------------------------------------------------------

	public function test_own_file_translation_is_not_a_wpml_duplicate() {
		$id            = $this->uploadFixture( 'fixture-small.jpg' );
		$translated_id = $this->createOwnFileTranslation( $id, 1991, 'de' );

		$duplicates = array_map( 'intval', $this->freshImageModel( $id )->getWPMLDuplicates() );
		$this->assertNotContains( $translated_id, $duplicates, 'A translation with its own file must not be reported as a duplicate.' );

		$reverse = array_map( 'intval', $this->freshImageModel( $translated_id )->getWPMLDuplicates() );
		$this->assertNotContains( $id, $reverse, 'The reverse lookup must not report the original either.' );
	}

	public function test_own_file_translation_optimizes_independently() {
		$id            = $this->uploadFixture( 'fixture-small.jpg' );
		$translated_id = $this->createOwnFileTranslation( $id, 1992, 'de' );

		// Both uploads were auto-enqueued (autoMediaLibrary); drop those so
		// only the explicit optimize below runs — otherwise the queue tick
		// legitimately optimizes the translation too.
		$this->purgeQueueTable();

		$this->optimizeAttachment( $id );

		$this->assertTrue( $this->freshImageModel( $id )->isOptimized(), 'Original must be optimized.' );
		$this->assertFalse(
			$this->freshImageModel( $translated_id )->isOptimized(),
			'Optimizing the original must NOT mark the own-file translation as optimized.'
		);

		// The translation is its own image: optimizing it must produce its
		// own API traffic for its own file.
		$requests_before = count( $this->api->requests );
		$this->optimizeAttachment( $translated_id );

		$this->assertTrue( $this->freshImageModel( $translated_id )->isOptimized(), 'The translation must optimize on its own.' );
		$this->assertGreaterThan(
			$requests_before,
			count( $this->api->requests ),
			'Optimizing the own-file translation must issue its own API request(s).'
		);
	}

	public function test_deleting_original_keeps_own_file_translation_intact() {
		$id            = $this->uploadFixture( 'fixture-small.jpg' );
		$translated_id = $this->createOwnFileTranslation( $id, 1993, 'de' );

		$this->optimizeAttachment( $id );
		$this->optimizeAttachment( $translated_id );

		$translated_file = get_attached_file( $translated_id );
		$this->assertFileExists( $translated_file, 'Precondition: translation file on disk.' );

		// No same-file duplicates → deleting the original removes ITS files.
		// Real WP deletion: core removes the attachment file, SPIO's delete
		// hook cleans its own artifacts (fileDelete is true — no siblings).
		$original_file = get_attached_file( $id );
		wp_delete_attachment( $id, true );

		clearstatcache();
		$this->assertFileDoesNotExist( $original_file, 'Deleting the original (no same-file siblings) must remove its own file.' );
		$this->assertFileExists( $translated_file, "The translation's own file must be untouched." );
		$this->assertTrue(
			$this->freshImageModel( $translated_id )->isOptimized(),
			'The translation must stay optimized after the original is deleted.'
		);
	}

	// -------------------------------------------------------------------
	// AI requestAlt — no fan-out for own-file translations
	// -------------------------------------------------------------------

	/**
	 * The WPML AI fan-out (addWpmlAiItemsToQueue) is keyed off
	 * getWPMLDuplicates(); an own-file translation is not a duplicate, so
	 * requestAlt on the original must queue ONLY the original — and must
	 * not trip the pin-#42 duplicate-active skip either.
	 */
	public function test_requestalt_does_not_fan_out_to_own_file_translation() {
		$id            = $this->uploadFixture( 'fixture-small.jpg' );
		$translated_id = $this->createOwnFileTranslation( $id, 1994, 'de' );

		$this->purgeQueueTable();

		( new QueueController() )->addItemToQueue(
			$this->freshImageModel( $id ),
			array( 'action' => 'requestAlt' )
		);

		global $wpdb;
		$queued = array_map( 'intval', (array) $wpdb->get_col( "SELECT item_id FROM `{$wpdb->prefix}shortpixel_queue`" ) );

		$this->assertContains( $id, $queued, 'The original must be queued for requestAlt (no duplicates, no fan-out skip).' );
		$this->assertNotContains( $translated_id, $queued, 'An own-file translation must not be dragged into the AI fan-out.' );
	}

	// -------------------------------------------------------------------
	// Same-file duplicates keep working with the add-on's hooks loaded
	// -------------------------------------------------------------------

	public function test_same_file_duplicate_still_propagates_with_media_translation_active() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );

		$trid = 1995;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );

		$this->optimizeAttachment( $id );

		$this->assertTrue( $this->freshImageModel( $id )->isOptimized(), 'Main attachment must be optimized.' );
		$this->assertTrue(
			$this->freshImageModel( $dup_id )->isOptimized(),
			'Same-file duplicate propagation must keep working with Media Translation active.'
		);
	}
}
