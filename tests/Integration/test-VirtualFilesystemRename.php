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
 * alias of the empty user-defined SPIO_Test_S3_Uploads_Plugin_Stub
 * (bottom of this file) is a faithful stand-in for the dispatcher/adapter
 * wiring on every supported PHP version. The
 * VirtualFileSystem adapter treats EVERY file as VIRTUAL_STATELESS for
 * this offloader (virtual-filesystem.php:76-79).
 *
 * ORDER DEPENDENCY (deliberate, documented): checkVirtualLoaders() probes
 * \S3_Uploads\Plugin BEFORE INFINITE_UPLOADS_VERSION, and both the class
 * alias and the constant are process-global once created. The
 * InfiniteUploads detection test therefore runs FIRST in this file and
 * self-skips defensively if the S3 alias already exists.
 *
 * RENAME ON VIRTUAL FILESYSTEMS — BUG #70 (FIXED by refusal in 0db02498,
 * regression-covered below): renaming is only supported for WP Offload
 * Media. OptimizeAiController::isVirtualSupported() answers true only when
 * no offloader or `wp-offload` is active, so for S3-Uploads,
 * InfiniteUploads and Bitpoke Stack:
 *   - replaceFiles() returns false up front for a virtual image ("Offloaded
 *     item not supported for renaming") — nothing is copied, no metadata
 *     or content is rewritten;
 *   - the "Change Filename" field is not rendered on the edit screen
 *     (part-aitext.php only renders it when `is_renameable` is true).
 * Previously every copy() failed silently on a stateless install while the
 * DB rewrite still ran and true was returned.
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
			// Alias a USER-DEFINED stub (declared at the bottom of this file),
			// never an internal class: class_alias() only accepts internal
			// classes such as \stdClass from PHP 8.3. PHP 7.4 refuses with a
			// warning and returns false (the class never exists, so the
			// dispatcher fell through to InfiniteUploads — CI PHP 7.4 failure,
			// 2026-09-16), and PHP 8.0-8.2 throw a fatal ValueError.
			class_alias( SPIO_Test_S3_Uploads_Plugin_Stub::class, 'S3_Uploads\Plugin' );
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
	 * REGRESSION #70 (flipped from pin70, 2026-09-18; fixed by refusal in
	 * 0db02498): on the normal S3-Uploads state ("no local files") the
	 * rename is now refused — replaceFiles() returns false and leaves the
	 * database untouched, instead of rewriting _wp_attached_file to a
	 * filename that exists nowhere and reporting success.
	 */
	public function test_regression70_stateless_rename_is_refused_and_leaves_the_db_untouched() {
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

		$offloader = $this->activateS3Uploads();
		// SENTINEL: the unsupported virtual offloader is really the active one.
		$this->assertSame( 's3-uploads-human', $offloader->getOffloadName(), 'Sentinel: S3-Uploads must be the detected offloader.' );

		$raw_attached_before = (string) get_post_meta( $id, '_wp_attached_file', true );

		$new_base = 'vfs-rename-' . wp_generate_password( 6, false );
		$result   = $this->renameAttachment( $id, $new_base );

		$this->assertFalse(
			$result,
			'REGRESSION #70: a rename on an unsupported virtual filesystem must be refused.'
		);
		$this->assertSame(
			$raw_attached_before,
			(string) get_post_meta( $id, '_wp_attached_file', true ),
			'REGRESSION #70: _wp_attached_file must be left untouched by a refused rename.'
		);
		$this->assertStringNotContainsString(
			$new_base,
			(string) ( wp_get_attachment_metadata( $id )['file'] ?? '' ),
			'REGRESSION #70: the attachment metadata must not carry the new base.'
		);
		$this->assertFileDoesNotExist(
			$dir . $new_base . '.jpg',
			'REGRESSION #70: no file may appear under the new name.'
		);
	}
}

/**
 * Empty stand-in for Human Made S3-Uploads' `\S3_Uploads\Plugin`, aliased by
 * VirtualFilesystemRenameTest::activateS3Uploads(). It must be a USER-DEFINED
 * class: class_alias() rejects internal classes (e.g. \stdClass) before
 * PHP 8.3.
 */
if ( ! class_exists( 'SPIO_Test_S3_Uploads_Plugin_Stub', false ) ) {
	final class SPIO_Test_S3_Uploads_Plugin_Stub {}
}
