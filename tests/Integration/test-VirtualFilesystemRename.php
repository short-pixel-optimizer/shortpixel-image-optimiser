<?php
/**
 * Virtual-filesystem offloaders vs the file-rename engine.
 *
 * Covers class/external/offload/Offloader.php (checkVirtualLoaders) and
 * class/external/offload/virtual-filesystem.php against the shared rename
 * engine OptimizeAiController::replaceFiles() (the single code path behind
 * both the AI filename rename and the manual "Change Filename" action).
 *
 * S3-Uploads by Human Made is GitHub-only (no wp.org zip), and SPIO's
 * detection is a bare class_exists('\S3_Uploads\Plugin') — so a class
 * alias is a faithful stand-in for the dispatcher/adapter wiring. The
 * VirtualFileSystem adapter treats EVERY file as VIRTUAL_STATELESS for
 * this offloader (virtual-filesystem.php:76-79).
 *
 * ORDER DEPENDENCY (deliberate, documented): checkVirtualLoaders() probes
 * \S3_Uploads\Plugin BEFORE INFINITE_UPLOADS_VERSION, and both the class
 * alias and the constant are process-global once created. The
 * InfiniteUploads detection test therefore runs FIRST in this file and
 * self-skips defensively if the S3 alias already exists.
 *
 * RENAME DESYNC — VIRTUAL FILESYSTEMS — BUG #70 (open, HIGH; same
 * family as #68/wp-offload): neither Offloader,
 * VirtualFileSystem, nor InfiniteUploads contains ANY rename handling,
 * and replaceFiles() fires no hook a virtual adapter could answer. On a
 * stateless install (no local files — the normal S3-Uploads state) every
 * FileModel::move() fails silently (copy() returns false on a missing
 * source, result discarded — bug #52), yet the DB/metadata rewrite still
 * runs and replaceFiles() returns true: the attachment then references a
 * filename that exists neither locally nor on the remote bucket. Pinned
 * below.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\External\Offload\Offloader;
use ShortPixel\Model\File\FileModel;
use ShortPixel\Model\Queue\QueueItem;

class VirtualFilesystemRenameTest extends SPIO_IntegrationTestCase {

	/** @var array Offloader static/instance state captured in set_up, restored in tear_down. */
	private $savedOffloaderState = array();

	public function set_up() {
		parent::set_up();
		$this->savedOffloaderState = $this->captureOffloaderState();
	}

	public function tear_down() {
		// Remove any filters a VirtualFileSystem adapter registered during
		// the test, then restore the Offloader singleton state so other
		// test files see the pristine (no-offloader) dispatcher.
		$instance = $this->getOffloadInstance();
		if ( is_object( $instance ) && $instance instanceof \ShortPixel\External\Offload\VirtualFileSystem ) {
			remove_filter( 'shortpixel/image/urltopath', array( $instance, 'checkIfOffloaded' ), 10 );
			remove_filter( 'shortpixel/file/virtual/translate', array( $instance, 'getLocalPathByURL' ) );
			remove_filter( 'shortpixel/file/virtual/heavy_features', array( $instance, 'extraFeatures' ), 10 );
		}
		$this->restoreOffloaderState( $this->savedOffloaderState );

		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Offloader singleton plumbing (private statics via reflection)
	// -------------------------------------------------------------------

	private function offloaderRef(): ReflectionClass {
		return new ReflectionClass( Offloader::class );
	}

	private function getOffloadInstance() {
		$prop = $this->offloaderRef()->getProperty( 'offload_instance' );
		$prop->setAccessible( true );
		return $prop->getValue();
	}

	private function captureOffloaderState(): array {
		$ref  = $this->offloaderRef();
		$out  = array();
		foreach ( array( 'instance', 'offload_instance' ) as $name ) {
			$prop = $ref->getProperty( $name );
			$prop->setAccessible( true );
			$out[ $name ] = $prop->getValue();
		}
		$namProp = $ref->getProperty( 'offloadName' );
		$namProp->setAccessible( true );
		$out['offloadName'] = is_object( $out['instance'] ) ? $namProp->getValue( $out['instance'] ) : null;
		return $out;
	}

	private function restoreOffloaderState( array $state ): void {
		$ref = $this->offloaderRef();
		foreach ( array( 'instance', 'offload_instance' ) as $name ) {
			$prop = $ref->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( null, $state[ $name ] );
		}
		if ( is_object( $state['instance'] ) ) {
			$namProp = $ref->getProperty( 'offloadName' );
			$namProp->setAccessible( true );
			$namProp->setValue( $state['instance'], $state['offloadName'] );
		}
	}

	/**
	 * Re-run the plugins_loaded detection on a FRESH dispatcher instance
	 * (without re-firing the hook globally) and return it.
	 */
	private function rebootOffloader(): Offloader {
		$ref = $this->offloaderRef();
		foreach ( array( 'instance', 'offload_instance' ) as $name ) {
			$prop = $ref->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
		// getInstance() re-registers the two add_action hooks; harmless in
		// tests (plugins_loaded/as3cf_init won't re-fire this process).
		$offloader = Offloader::getInstance();
		$offloader->load();
		return $offloader;
	}

	/** Run the shared rename engine exactly like AjaxController::replaceFileName does (:1409-1413). */
	private function renameAttachment( int $attachment_id, string $new_base ): bool {
		$this->resetPluginSingletons();
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueItem  = new QueueItem( array( 'imageModel' => $imageModel ) );

		return $queueItem->getApiController( 'requestAlt' )->ajax_replaceFile( $queueItem, $new_base );
	}

	// -------------------------------------------------------------------
	// InfiniteUploads — stub-level detection (MUST run before the S3
	// alias exists; see file docblock for the order dependency)
	// -------------------------------------------------------------------

	public function test_infinite_uploads_constant_is_detected_by_the_dispatcher() {
		if ( class_exists( '\S3_Uploads\Plugin' ) ) {
			$this->markTestSkipped( 'S3-Uploads alias already defined in this process — detection order would shadow InfiniteUploads.' );
		}
		if ( ! defined( 'INFINITE_UPLOADS_VERSION' ) ) {
			define( 'INFINITE_UPLOADS_VERSION', '2.0-test' );
		}

		$offloader = $this->rebootOffloader();

		$this->assertSame(
			'infinite-uploads',
			$offloader->getOffloadName(),
			'The dispatcher must detect InfiniteUploads via its version constant.'
		);
	}

	// -------------------------------------------------------------------
	// S3-Uploads (Human Made) — dispatcher + adapter wiring
	// -------------------------------------------------------------------

	private function activateS3Uploads(): Offloader {
		if ( ! class_exists( '\S3_Uploads\Plugin' ) ) {
			class_alias( \stdClass::class, 'S3_Uploads\Plugin' );
		}
		return $this->rebootOffloader();
	}

	public function test_s3_uploads_class_is_detected_and_virtual_adapter_boots() {
		$offloader = $this->activateS3Uploads();

		$this->assertSame(
			's3-uploads-human',
			$offloader->getOffloadName(),
			'The dispatcher must detect S3-Uploads via class_exists(\\S3_Uploads\\Plugin).'
		);

		$instance = $this->getOffloadInstance();
		$this->assertInstanceOf(
			\ShortPixel\External\Offload\VirtualFileSystem::class,
			$instance,
			'load() must wrap the s3-uploads-human offloader in a VirtualFileSystem adapter.'
		);

		// The adapter must answer the three virtual-filesystem filters.
		$this->assertSame(
			FileModel::$VIRTUAL_STATELESS,
			apply_filters( 'shortpixel/image/urltopath', false, 'http://example.com/wp-content/uploads/foo.jpg', '' ),
			'For s3-uploads-human EVERY urltopath probe must answer VIRTUAL_STATELESS (virtual-filesystem.php:76-79).'
		);
		$this->assertFalse(
			apply_filters( 'shortpixel/file/virtual/heavy_features', true ),
			'Heavy features (unlisted scan, retina) must be hard-disabled on virtual filesystems.'
		);
		$this->assertSame(
			'/some/path.jpg',
			apply_filters( 'shortpixel/file/virtual/translate', '/some/path.jpg' ),
			'The base adapter translate filter is a passthrough.'
		);
	}

	// -------------------------------------------------------------------
	// PIN — rename on a stateless install rewrites the DB while nothing
	// moves, and no virtual adapter is ever informed
	// -------------------------------------------------------------------

	/**
	 * PIN #70 (see file docblock):
	 * the normal S3-Uploads state is "no local files". The rename engine
	 * finds no source file to move (every FileModel::move() fails
	 * silently), no hook informs the adapter, yet the DB/metadata rewrite
	 * still runs and success is reported — the attachment now references
	 * a filename that exists nowhere.
	 *
	 * Flip when: replaceFiles() detects a virtual/offloaded source and
	 * either refuses the rename or delegates it to the offload handler.
	 */
	public function test_pin70_stateless_rename_rewrites_db_while_no_file_moves_pinned_for_deferred_fix() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$dir      = trailingslashit( dirname( $old_file ) );

		// Simulate the stateless remote state: no local copies at all.
		$meta = wp_get_attachment_metadata( $id );
		@unlink( $old_file );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			@unlink( $dir . $size['file'] );
		}
		$this->assertFileDoesNotExist( $old_file, 'Precondition: local main file removed (stateless install).' );

		$this->activateS3Uploads();

		$new_base = 'vfs-rename-' . wp_generate_password( 6, false );
		$result   = $this->renameAttachment( $id, $new_base );

		// THE PIN: success is reported although nothing could be moved and
		// the virtual adapter was never consulted (no rename hook exists).
		$this->assertTrue(
			$result,
			'PIN #70: fixed? replaceFiles() now refuses/handles a rename on a virtual filesystem — flip this pin.'
		);
		$this->assertStringContainsString(
			$new_base,
			get_attached_file( $id ),
			'PIN #70: _wp_attached_file was rewritten despite no file moving.'
		);
		$this->assertFileDoesNotExist(
			$dir . $new_base . '.jpg',
			'PIN #70: no local file was created under the new name — and no remote rename happened either (no handler exists).'
		);
	}
}
