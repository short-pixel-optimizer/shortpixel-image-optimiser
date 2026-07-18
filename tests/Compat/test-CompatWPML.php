<?php
/**
 * Cross-plugin compatibility: WPML (Wave 3).
 *
 * Runs with the REAL WPML (sitepress-multilingual-cms) plugin active.
 * WPML is commercial, so bin/test.sh --compat extracts it from a zip
 * dropped into tests/partner-plugins/ (gitignored); without that zip
 * every test here SKIPS.
 *
 * Covers the two SPIO x WPML integration surfaces:
 *
 *   - class/external/wpml.php — the AI alt-text locale shim: its two
 *     filters are only wired when plugin_active('wpml') is true, and
 *     checkParamList() injects the attachment's WPML locale into the
 *     outgoing AI request params.
 *   - MediaLibraryModel::getWPMLDuplicates() — translation duplicates
 *     found via the real icl_translations table (same-trid siblings),
 *     restricted to attachments sharing the same physical file; on
 *     optimize, handleOptimized() propagates meta to every duplicate.
 *
 * The icl_translations rows are seeded per test (WPML only fills them
 * once its setup wizard ran); the table itself is created by WPML's
 * activation or, failing that, by ensureIclTranslationsTable() with
 * the same columns SPIO queries.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;

class CompatWPMLTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! class_exists( 'SitePress' ) ) {
			$this->markTestSkipped( 'WPML is not loaded — drop its zip into tests/partner-plugins/ and run bin/test.sh --compat.' );
		}

		// DDL auto-commits, so the table must exist BEFORE the test
		// transaction starts in parent::set_up().
		$this->ensureIclTranslationsTable();

		parent::set_up();
	}

	/**
	 * WPML normally creates icl_translations during its own setup; if the
	 * activation hook alone didn't (the wizard hasn't run in this install),
	 * create it with the columns SPIO's duplicate query uses.
	 */
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

	/** A second attachment record pointing at the SAME file on disk (a WPML duplicate). */
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

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	// -------------------------------------------------------------------
	// Coexistence + wiring
	// -------------------------------------------------------------------

	public function test_wpml_loads_alongside_spio() {
		$this->assertTrue( defined( 'ICL_SITEPRESS_VERSION' ), 'WPML version constant must be defined.' );
		$this->assertTrue( \wpSPIO()->env()->plugin_active( 'wpml' ), "SPIO's environment must detect WPML as active." );
	}

	public function test_spio_wpml_shim_hooks_are_wired() {
		// Both are gated on plugin_active('wpml') in the WPML shim's
		// constructor — they only exist in this compat run.
		$this->assertNotFalse( has_filter( 'shortpixel/aidatamodel/paramlist', array( $this->spioWpmlInstance(), 'checkParamList' ) ), 'The AI paramlist locale filter must be wired.' );
		$this->assertNotFalse( has_filter( 'shortpixel/ai/success', array( $this->spioWpmlInstance(), 'successHandle' ) ), 'The AI success passthrough filter must be wired.' );
	}

	/** The WPML shim instance registered on the AI paramlist filter. */
	private function spioWpmlInstance() {
		global $wp_filter;
		foreach ( $wp_filter['shortpixel/aidatamodel/paramlist']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \ShortPixel\WPML ) {
					return $callback['function'][0];
				}
			}
		}
		$this->fail( 'No ShortPixel\WPML instance found on the AI paramlist filter.' );
	}

	// -------------------------------------------------------------------
	// AI locale shim behavior
	// -------------------------------------------------------------------

	public function test_ai_paramlist_receives_wpml_locale() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		// This unconfigured WPML install has no language data yet, so
		// answer its own lookup filter the way a configured WPML would.
		remove_all_filters( 'wpml_post_language_details' );
		add_filter(
			'wpml_post_language_details',
			function () {
				return array( 'locale' => 'de_DE', 'language_code' => 'de' );
			}
		);

		$params = apply_filters( 'shortpixel/aidatamodel/paramlist', array(), $id );
		$this->assertSame( 'de_DE', $params['languages'] ?? null, 'The WPML locale must be injected into the AI request params.' );
	}

	public function test_ai_paramlist_unchanged_without_locale() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		// WPML reports no locale (multilingual mode off / "all languages").
		remove_all_filters( 'wpml_post_language_details' );
		add_filter(
			'wpml_post_language_details',
			function () {
				return array( 'locale' => null );
			}
		);

		$params = apply_filters( 'shortpixel/aidatamodel/paramlist', array(), $id );
		$this->assertArrayNotHasKey( 'languages', $params, 'No locale means no languages param.' );
	}

	// -------------------------------------------------------------------
	// Translation duplicates
	// -------------------------------------------------------------------

	public function test_getWPMLDuplicates_finds_same_file_translations_only() {
		$id       = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id   = $this->createDuplicateAttachment( $id );
		$other_id = $this->uploadFixture( 'fixture-small.png' ); // different file on disk

		$trid = 991;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );
		// A translation legitimately linked to a DIFFERENT image — must be
		// filtered out, only same-file siblings share SPIO meta.
		$this->insertTranslationRow( $other_id, $trid, 'fr', 'en' );

		// element_id comes back from wpdb as numeric strings — normalize.
		$duplicates = array_map( 'intval', $this->freshImageModel( $id )->getWPMLDuplicates() );

		$this->assertContains( $dup_id, $duplicates, 'The same-file translation must be reported as a duplicate.' );
		$this->assertNotContains( $other_id, $duplicates, 'A translation pointing at a different file must be filtered out.' );
		$this->assertNotContains( $id, $duplicates, 'The attachment itself must never be in its own duplicate list.' );
	}

	public function test_optimize_propagates_to_wpml_duplicate() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );

		$trid = 992;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );

		$this->optimizeAttachment( $id );

		$this->assertTrue( $this->freshImageModel( $id )->isOptimized(), 'Main attachment must be optimized.' );

		$duplicate = $this->freshImageModel( $dup_id );
		// getParent() returns the DB value, which may be a numeric string.
		$this->assertEquals( $id, $duplicate->getParent(), 'Optimizing must create the duplicate record linking the translation to its parent.' );
		$this->assertTrue( $duplicate->isOptimized(), 'The WPML duplicate must share the optimized state.' );
	}
}
