<?php
/**
 * Tests for ShortPixel\External\Offload\wpOffload.
 *
 * Focus areas:
 *   - isActive — two-flag AND logic (active && offloading)
 *   - preventOffload / preventOffloadOff — mutation on static $offloadPrevented
 *   - preventUpdateMetaData — in-list cancels, otherwise passes through
 *   - sourceCache (private) — three shapes (uncached read, write, cached read)
 *     plus scheme normalisation
 *   - checkScaledUrl — the `-scaled` stripper, plus a regression
 *     sentinel for the folder-name false-positive (fixed in a7a0f8f9)
 *
 * Skipped at the unit level (integration territory — need an as3cf
 * instance, WordPress attachments, or the SPIO filesystem):
 *   - __construct / getInstance / init      → need as3cf
 *   - getMediaClass                         → depends on as3cf handler API
 *   - getItemById                           → as3cf item lookup
 *   - checkIfOffloaded / getSourceIDByURL  → as3cf `get_item_source_by_remote_url`
 *   - getLocalPathByURL                    → as3cf item + WP upload base
 *   - image_upload / image_restore / image_converted / remove_remote
 *                                          → WP attachment metadata + as3cf
 *   - preventInitialUploadHandler          → QuotaController + settings + as3cf
 *   - updateOriginalPath                   → as3cf item mutation
 *   - getWebpPaths / add_webp_paths        → filesystem probes
 *   - fixWebpRemotePath                    → SPIO filesystem + as3cf
 *   - returnOriginalFile                   → get_attached_file with a real attachment
 *
 * Regression sentinel: `checkScaledUrl` used to strip `-scaled` from
 * anywhere in the path (a folder named `my-scaled-folder` lost its
 * segment). Fixed in a7a0f8f9 by anchoring the strip to `-scaled.<ext>`
 * at the end of the path — the folder-name test below pins the fix.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\External\Offload\wpOffload;

class wpOffloadTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Reset all static caches so per-test state doesn't leak.
		$this->setStatic( 'offloadPrevented', array() );
		$this->setStatic( 'sources', array() );
		$this->setStatic( 'paths', array() );
	}

	public function tear_down() {
		$this->setStatic( 'offloadPrevented', array() );
		$this->setStatic( 'sources', array() );
		$this->setStatic( 'paths', array() );

		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function getPrivate( wpOffload $o, string $prop ) {
		$ref = new ReflectionClass( wpOffload::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $o );
	}

	private function setPrivate( wpOffload $o, string $prop, $value ): void {
		$ref = new ReflectionClass( wpOffload::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $o, $value );
	}

	private function getStatic( string $prop ) {
		$ref = new ReflectionClass( wpOffload::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( null );
	}

	private function setStatic( string $prop, $value ): void {
		$ref = new ReflectionClass( wpOffload::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( null, $value );
	}

	private function invokePrivate( wpOffload $o, string $method, array $args = array() ) {
		$ref = new ReflectionClass( wpOffload::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $o, ...$args );
	}

	/**
	 * Build a wpOffload without running the constructor (which would
	 * invoke init($as3cf) — we don't have a real as3cf in unit tests).
	 */
	private function freshOffload(): wpOffload {
		$ref = new ReflectionClass( wpOffload::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/*
	 * isActive — two-flag AND logic
	 */

	public function test_isActive_returns_true_when_both_active_and_offloading_are_true() {
		$o = $this->freshOffload();
		$this->setPrivate( $o, 'active', true );
		$this->setPrivate( $o, 'offloading', true );

		$this->assertTrue( $o->isActive() );
	}

	public function test_isActive_returns_false_when_active_is_false() {
		$o = $this->freshOffload();
		$this->setPrivate( $o, 'active', false );
		$this->setPrivate( $o, 'offloading', true );

		$this->assertFalse( $o->isActive() );
	}

	public function test_isActive_returns_false_when_offloading_is_false() {
		$o = $this->freshOffload();
		$this->setPrivate( $o, 'active', true );
		$this->setPrivate( $o, 'offloading', false );

		$this->assertFalse( $o->isActive() );
	}

	/*
	 * preventOffload / preventOffloadOff — mutation on static $offloadPrevented
	 */

	public function test_preventOffload_adds_the_attach_id_to_the_prevent_list() {
		$o = $this->freshOffload();

		$o->preventOffload( 42 );

		$prevented = $this->getStatic( 'offloadPrevented' );
		$this->assertArrayHasKey( 42, $prevented );
		$this->assertTrue( $prevented[42] );
	}

	public function test_preventOffloadOff_removes_the_attach_id_from_the_prevent_list() {
		$o = $this->freshOffload();
		$this->setStatic( 'offloadPrevented', array( 42 => true, 43 => true ) );

		$o->preventOffloadOff( 42 );

		$prevented = $this->getStatic( 'offloadPrevented' );
		$this->assertArrayNotHasKey( 42, $prevented );
		// Sentinel: only the requested id was removed — other entries survive.
		$this->assertArrayHasKey( 43, $prevented );
	}

	/*
	 * preventUpdateMetaData — decision on incoming bool + prevent list
	 */

	public function test_preventUpdateMetaData_returns_true_when_attach_id_is_on_the_prevent_list() {
		$o = $this->freshOffload();
		$this->setStatic( 'offloadPrevented', array( 100 => true ) );

		$result = $o->preventUpdateMetaData( false, array(), 100, null );

		// Contract: returning true cancels as3cf's metadata update.
		$this->assertTrue( $result );
	}

	public function test_preventUpdateMetaData_passes_through_the_incoming_bool_when_attach_id_is_not_on_the_list() {
		$o = $this->freshOffload();
		$this->setStatic( 'offloadPrevented', array() );

		$this->assertFalse( $o->preventUpdateMetaData( false, array(), 100, null ) );
		$this->assertTrue( $o->preventUpdateMetaData( true, array(), 100, null ) );
	}

	/*
	 * sourceCache — three shapes + scheme normalisation
	 */

	public function test_sourceCache_read_returns_null_when_url_is_not_cached() {
		$o = $this->freshOffload();

		$result = $this->invokePrivate( $o, 'sourceCache', array( 'https://bucket.test/unknown.jpg' ) );

		$this->assertNull( $result );
		// Sentinel: null must be strictly distinct from false, which is a
		// valid cached "confirmed not offloaded" value.
		$this->assertNotSame( false, $result );
	}

	public function test_sourceCache_write_stores_the_source_id_and_returns_it() {
		$o = $this->freshOffload();

		$written = $this->invokePrivate(
			$o,
			'sourceCache',
			array( 'https://bucket.test/photo.jpg', 42 )
		);

		$this->assertSame( 42, $written );

		// Follow-up read returns the same value.
		$read = $this->invokePrivate( $o, 'sourceCache', array( 'https://bucket.test/photo.jpg' ) );
		$this->assertSame( 42, $read );
	}

	public function test_sourceCache_normalises_the_scheme_before_lookup() {
		$o = $this->freshOffload();

		// Write with https, read with http — should hit the same slot
		// because the scheme is stripped before lookup.
		$this->invokePrivate( $o, 'sourceCache', array( 'https://bucket.test/photo.jpg', 77 ) );
		$result = $this->invokePrivate( $o, 'sourceCache', array( 'http://bucket.test/photo.jpg' ) );

		$this->assertSame( 77, $result );
	}

	/*
	 * checkScaledUrl — strip `-scaled` + folder-name regression sentinel
	 */

	public function test_checkScaledUrl_strips_scaled_before_extension_in_a_typical_path() {
		$o = $this->freshOffload();

		$this->assertSame(
			'/wp-content/uploads/2024/06/photo.jpg',
			$o->checkScaledUrl( '/wp-content/uploads/2024/06/photo-scaled.jpg', 123 )
		);
	}

	/**
	 * Regression sentinel for a7a0f8f9 — `checkScaledUrl` used to do a
	 * blind `str_replace('-scaled', ...)` that matched the substring
	 * **anywhere** in the path, so a folder named `my-scaled-folder`
	 * lost its `-scaled` segment. The strip is now a `preg_replace`
	 * anchored on `-scaled.<ext>` at the end of the path.
	 */
	public function test_checkScaledUrl_does_not_strip_scaled_from_folder_names() {
		$o = $this->freshOffload();

		$input = '/wp-content/uploads/2024/06/my-scaled-folder/photo.jpg';

		$this->assertSame(
			$input,
			$o->checkScaledUrl( $input, 123 ),
			'checkScaledUrl stripped `-scaled` from a folder name — the strip must be anchored to `-scaled.<ext>` at the end of the basename'
		);
	}
}
