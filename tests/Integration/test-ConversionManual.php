<?php
/**
 * Integration tests: conversion + content-URL replacement with
 * autoMediaLibrary OFF (manual optimize triggers conversion).
 *
 * The wp_generate_attachment_metadata hook that runs
 * AdminController::handleImageUploadHook is registered by
 * shortpixel-plugin.php ONLY when env()->is_autoprocess is true
 * (autoMediaLibrary == 1). To exercise the OFF state we mirror the
 * pattern established by OptimizePipelineTest::test_autoMediaLibrary_off_upload_not_queued
 * — settings flag flipped, env flag flipped, hook removed for the
 * duration of the upload, then rewired in tear_down so we do not
 * pollute sibling tests.
 *
 * Matrix is intentionally narrower than test-ConversionReplacement (auto
 * ON): two formats × the richest content shapes. Cross-format coverage
 * lives with the auto ON tests; the interesting thing HERE is the
 * upload-does-nothing-then-manual-optimize-does-everything transition.
 *
 * Formats picked:
 *   - PNG (local GD converter through the queue path — the queue path
 *     is the only meaningful path anyway, since with the upload hook
 *     removed both auto-mode paths are equivalent here)
 *   - HEIC (representative for ApiConverter)
 *
 * @package Shortpixel_Image_Optimiser
 */

class ConversionManualTest extends SPIO_IntegrationTestCase {

	/** @var array Filters to remove in tear_down. */
	private $tempFilters = array();

	/** @var bool Whether tear_down should re-add the upload hook. */
	private $hookWasRemoved = false;

	public function tear_down() {
		foreach ( $this->tempFilters as $entry ) {
			remove_filter( $entry[0], $entry[1], isset( $entry[2] ) ? $entry[2] : 10 );
		}
		$this->tempFilters = array();

		$this->resetReplacerModuleSingletons();

		// Restore the plugin's normal auto-process state and hook so the
		// next test's baseline (autoMediaLibrary=1) is honoured end-to-end.
		if ( true === $this->hookWasRemoved ) {
			\wpSPIO()->settings()->autoMediaLibrary = 1;
			\wpSPIO()->env()->is_autoprocess        = true;
			$admin = \ShortPixel\Controller\AdminController::getInstance();
			// Re-add only if not currently registered (idempotent).
			if ( false === has_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleImageUploadHook' ) ) ) {
				add_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleImageUploadHook' ), 5, 2 );
			}
			$this->hookWasRemoved = false;
		}

		// Backup files live outside the DB transaction — sweep the tree.
		if ( is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
		}

		parent::tear_down();
	}

	private function addTempFilter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $cb, $priority, $args );
		$this->tempFilters[] = array( $hook, $cb, $priority );
	}

	/** Null the private static $instance on Replacer + every Modules\* class. */
	private function resetReplacerModuleSingletons(): void {
		$classes = array(
			\ShortPixel\Replacer\Replacer::class,
			\ShortPixel\Replacer\Modules\Elementor::class,
			\ShortPixel\Replacer\Modules\WpBakery::class,
			\ShortPixel\Replacer\Modules\YoastSeo::class,
			\ShortPixel\Replacer\Modules\Breakdance::class,
		);
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$ref = new ReflectionClass( $class );
			if ( $ref->hasProperty( 'instance' ) ) {
				$prop = $ref->getProperty( 'instance' );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		}
	}

	/**
	 * Fully disable auto-processing: flip both flags AND remove the
	 * upload-metadata hook so uploads made afterwards do NOT auto-enqueue.
	 */
	private function disableAutoProcessing(): void {
		\wpSPIO()->settings()->autoMediaLibrary = 0;
		\wpSPIO()->env()->is_autoprocess        = false;

		$admin = \ShortPixel\Controller\AdminController::getInstance();
		remove_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleImageUploadHook' ), 5 );

		$this->hookWasRemoved = true;
	}

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/**
	 * Seed the richest content-carrier subset for the auto-OFF matrix:
	 *   - Classic publish, Gutenberg draft, scheduled (future), page
	 *   - Plain string postmeta + serialized-array postmeta
	 */
	private function seedContent( int $attachment_id ): array {
		$url = wp_get_attachment_url( $attachment_id );

		$classic   = '<p><img src="' . esc_url( $url ) . '" alt="classic-manual" /></p>';
		$gutenberg = '<!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img class="wp-image-' . $attachment_id . '" src="' . esc_url( $url ) . '" alt="gb-manual" /></figure><!-- /wp:image -->';

		$posts = array(
			'publish' => self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => $classic ) ),
			'draft'   => self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => $gutenberg ) ),
			'future'  => self::factory()->post->create( array(
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_content'  => $classic,
			) ),
			'page'    => self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $classic ) ),
		);

		$meta_carrier = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_post_meta( $meta_carrier, '_spio_manual_string', $url );
		add_post_meta( $meta_carrier, '_spio_manual_array', array( 'main' => $url, 'nested' => array( 'src' => $url ) ) );

		return array(
			'posts'  => $posts,
			'meta'   => array(
				'string' => array( 'post' => $meta_carrier, 'key' => '_spio_manual_string' ),
				'array'  => array( 'post' => $meta_carrier, 'key' => '_spio_manual_array' ),
			),
			'raw'    => array( 'url' => $url ),
		);
	}

	private function assertPreConversionContains( array $seeded, string $extension ): void {
		foreach ( $seeded['posts'] as $label => $post_id ) {
			clean_post_cache( $post_id );
			$this->assertStringContainsString(
				'.' . $extension,
				get_post( $post_id )->post_content,
				"Sentinel: pre-conversion .$extension must be present in the $label carrier."
			);
		}
		foreach ( $seeded['meta'] as $label => $entry ) {
			wp_cache_delete( $entry['post'], 'post_meta' );
			$value = get_post_meta( $entry['post'], $entry['key'], true );
			$flat  = is_string( $value ) ? $value : wp_json_encode( $value );
			$this->assertStringContainsString(
				'.' . $extension,
				$flat,
				"Sentinel: pre-conversion .$extension must be present in the $label meta carrier."
			);
		}
	}

	private function assertPostConversionReplaced( array $seeded, string $old_extension ): void {
		foreach ( $seeded['posts'] as $label => $post_id ) {
			clean_post_cache( $post_id );
			$content = get_post( $post_id )->post_content;
			$this->assertStringNotContainsString( '.' . $old_extension, $content, "$label carrier must not still reference .$old_extension." );
			$this->assertStringContainsString( '.jpg', $content, "$label carrier must reference .jpg." );
		}
		foreach ( $seeded['meta'] as $label => $entry ) {
			wp_cache_delete( $entry['post'], 'post_meta' );
			$value = get_post_meta( $entry['post'], $entry['key'], true );
			$flat  = is_string( $value ) ? $value : wp_json_encode( $value );
			$this->assertStringNotContainsString( '.' . $old_extension, $flat, "$label meta must not still reference .$old_extension." );
			$this->assertStringContainsString( '.jpg', $flat, "$label meta must reference .jpg." );
		}
	}

	// -------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------

	/**
	 * PNG: with auto OFF, the upload must NOT convert and must NOT
	 * enqueue anything. Content pre-seeded with the .png URL survives
	 * intact until the operator triggers a manual optimize, at which
	 * point PNGConverter::filterQueue runs (png2jpg queue action) and
	 * the replacer rewrites every content carrier to .jpg.
	 */
	public function test_png_manual_optimize_converts_and_rewrites_content() {
		$this->disableAutoProcessing();
		\wpSPIO()->settings()->png2jpg = 1;
		$this->resetPluginSingletons();
		// resetPluginSingletons() reloads SettingsModel from DB;
		// re-flip the in-memory settings AFTER the reset so the queue
		// sees them.
		\wpSPIO()->settings()->png2jpg          = 1;
		\wpSPIO()->settings()->autoMediaLibrary = 0;
		\wpSPIO()->env()->is_autoprocess        = false;

		// fixture-small.png shrinks when reencoded to JPG. fixture-large.png
		// is already so heavily PNG-optimized that the JPG round-trip is
		// LARGER and PNGConverter::checkFileSizeMargin silently rejects
		// (ERROR_RESULTLARGER) — vacuous for a conversion assertion.
		$id = $this->uploadFixture( 'fixture-small.png' );

		// Auto-OFF sanity: no queue work, main file is still .png.
		$this->assertFalse(
			$this->queueHasWork(),
			'With autoMediaLibrary=0 + upload hook removed, upload must NOT auto-enqueue.'
		);
		$this->assertSame(
			'png',
			strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ),
			'With auto OFF, the upload MUST NOT convert the PNG at upload time.'
		);
		$image = $this->freshImageModel( $id );
		$this->assertFalse(
			$image->isOptimized(),
			'With auto OFF, a freshly-uploaded PNG must not be optimized.'
		);

		$seeded = $this->seedContent( $id );
		$this->assertPreConversionContains( $seeded, 'png' );

		// Now the operator manually kicks off optimization.
		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ),
			'Manual optimize must convert the PNG through the queue path.'
		);

		$this->assertPostConversionReplaced( $seeded, 'png' );

		$reloaded = $this->freshImageModel( $id );
		$this->assertTrue( $reloaded->isOptimized(), 'After manual optimize the converted JPG must be marked optimized.' );
	}

	/**
	 * HEIC via ApiConverter, manual mode — DOCUMENTS CURRENT BEHAVIOR.
	 *
	 * Production quirk: in manual mode a HEIC uploaded with the upload
	 * hook removed carries the raw `.heic` URL in wp_get_attachment_url
	 * (no placeholder). If the operator embeds that URL in content and
	 * THEN triggers a manual optimize, the ApiConverter pipeline runs:
	 *   1. addItemToQueue → newOptimizeAction → filterQueue →
	 *      prepareQueue writes a placeholder .jpg and flips
	 *      convertMeta->hasPlaceHolder true — so from this point on
	 *      wp_get_attachment_url returns .jpg
	 *   2. Queue processes convert_api → handleConverted
	 *   3. handleConverted::setupReplacer() sets source_url from the
	 *      current imageModel path; then inside the hasPlaceHolder
	 *      branch OVERWRITES source_url to the placeholder .jpg URL
	 *      (class/Model/Converter/ApiConverter.php:274). No
	 *      setTarget() call is made on the fresh Replacer instance, so
	 *      target_url stays null and Replacer::replace() short-circuits
	 *      (see class/../replacer2/src/Replacer.php:129).
	 *   4. The main file becomes a real .jpg on disk, but the pre-
	 *      existing .heic URL in post_content is NEVER rewritten.
	 *
	 * This test pins that behavior — filed as bug #63 (2026-09-09).
	 * When Bas fixes the target-url wiring in ApiConverter::
	 * handleConverted (or moves the runReplacer=true call somewhere
	 * that has both source and target set), the
	 * `assertStringNotContainsString('.heic', …)` assertions below will
	 * start firing loudly and this test must flip into a proper
	 * "content is rewritten" assertion.
	 */
	public function test_heic_manual_optimize_converts_file_but_leaves_heic_urls_in_content_pinned_for_deferred_fix() {
		$this->disableAutoProcessing();

		$id = $this->uploadFixture( 'fixture-large.heic' );

		$this->assertFalse(
			$this->queueHasWork(),
			'With autoMediaLibrary=0 + upload hook removed, HEIC upload must NOT auto-enqueue.'
		);
		$this->assertSame(
			'heic',
			strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ),
			'Auto OFF: HEIC upload must remain heic on disk pre-optimize.'
		);
		$image = $this->freshImageModel( $id );
		$this->assertFalse(
			$image->isOptimized(),
			'Auto OFF: a freshly-uploaded HEIC must not be optimized yet.'
		);

		$seeded = $this->seedContent( $id );
		$this->assertPreConversionContains( $seeded, 'heic' );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ),
			'Manual optimize must convert the HEIC to JPG through ApiConverter (file layer works).'
		);

		// PIN: the pre-existing .heic URL in content is NOT rewritten to
		// .jpg — the ApiConverter Replacer instance never receives a
		// target URL, so replace() short-circuits.
		foreach ( $seeded['posts'] as $label => $post_id ) {
			clean_post_cache( $post_id );
			$content = get_post( $post_id )->post_content;
			$this->assertStringContainsString(
				'.heic',
				$content,
				"CURRENT BEHAVIOR: manual HEIC optimize leaves .heic in $label post_content (ApiConverter Replacer target_url never set)."
			);
			$this->assertStringNotContainsString(
				'.jpg',
				$content,
				"CURRENT BEHAVIOR: no .jpg has been substituted in $label post_content."
			);
		}
		foreach ( $seeded['meta'] as $label => $entry ) {
			wp_cache_delete( $entry['post'], 'post_meta' );
			$value = get_post_meta( $entry['post'], $entry['key'], true );
			$flat  = is_string( $value ) ? $value : wp_json_encode( $value );
			$this->assertStringContainsString(
				'.heic',
				$flat,
				"CURRENT BEHAVIOR: manual HEIC optimize leaves .heic in $label meta."
			);
			$this->assertStringNotContainsString(
				'.jpg',
				$flat,
				"CURRENT BEHAVIOR: no .jpg has been substituted in $label meta."
			);
		}

		$reloaded = $this->freshImageModel( $id );
		$this->assertTrue( $reloaded->isOptimized(), 'After manual optimize the converted JPG must be marked optimized.' );
	}
}
