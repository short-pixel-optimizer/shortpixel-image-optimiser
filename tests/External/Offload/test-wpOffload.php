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
 *     sentinel for the folder-name false-positive
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
 * replaceFiles (the provider-side rename), exercised with stubbed item +
 * provider client:
 *   - pinned: claims "handled" (true) when no provider object matched;
 *   - pinned: provider-client exceptions escape uncaught;
 *   - copy requests follow WP Offload Media's own ACL rule.
 *
 * Regression sentinel: `checkScaledUrl` must not strip `-scaled` from
 * anywhere in the path (a folder named `my-scaled-folder` would lose its
 * segment); the strip is anchored to `-scaled.<ext>` at the end of the
 * path — the folder-name test below guards it.
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
	 * Regression sentinel — a blind `str_replace('-scaled', ...)` matches
	 * the substring **anywhere** in the path, so a folder named
	 * `my-scaled-folder` would lose its `-scaled` segment. The strip is a
	 * `preg_replace` anchored on `-scaled.<ext>` at the end of the path.
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

	/*
	 * replaceFiles() — provider-side rename.
	 *
	 * Exercised with stubs for the as3cf item and the provider client, so
	 * the method's own logic is tested deterministically without a bucket.
	 * (Compat tests cannot pin these facets reliably: as3cf builds an item's
	 * objects lazily from its as3cf_files table, which the WP test framework
	 * may turn into a temporary table mid-run — see
	 * tests/Compat/test-CompatOffloadMedia.php.)
	 */

	/** wpOffload whose item lookup returns $item and whose $as3cf is $as3cf. */
	private function offloadWithStubs( $item, $as3cf ): wpOffload {
		$o = new class() extends wpOffload {
			public $stubItem;
			public $stubItems;
			public $duplicateLookupAfterPrimarySave = false;
			public function __construct() {} // skip init($as3cf)
			protected function getItemById( $id, $create = false ) {
				if ( 4242 !== (int) $id && isset( $this->stubItems[4242] ) && $this->stubItems[4242]->saveCalls > 0 ) {
					$this->duplicateLookupAfterPrimarySave = true;
				}
				return is_array( $this->stubItems ) ? ( $this->stubItems[ $id ] ?? false ) : $this->stubItem;
			}
		};
		if ( is_array( $item ) ) {
			$o->stubItems = $item;
		} else {
			$o->stubItem = $item;
		}
		$this->setPrivate( $o, 'as3cf', $as3cf );
		return $o;
	}

	/**
	 * Minimal stand-in for an as3cf Media_Library_Item served by the
	 * provider. Records what a completed rename writes back (set_objects,
	 * set_path, set_original_path, save).
	 */
	private function stubItem( array $objects, bool $providerServed = true ) {
		return new class( $objects, $providerServed ) {
			private $objects;
			private $providerServed;
			public $savedObjects      = null;
			public $savedPath         = null;
			public $savedOriginalPath = null;
			public $saveCalls         = 0;
			public function __construct( array $objects, bool $providerServed ) {
				$this->objects = $objects;
				$this->providerServed = $providerServed;
			}
			public function set_objects( array $objects ) {
				$this->savedObjects = $objects;
			}
			public function path() {
				return 'wp-content/uploads/2026/09/photo.jpg';
			}
			public function original_path() {
				return 'wp-content/uploads/2026/09/photo.jpg';
			}
			public function set_path( $path ) {
				$this->savedPath = $path;
			}
			public function set_original_path( $path ) {
				$this->savedOriginalPath = $path;
			}
			public function save() {
				$this->saveCalls++;
				return 1;
			}
			public function served_by_provider( $skip_rewrite_check = false ) {
				return $this->providerServed;
			}
			public function objects() {
				return $this->objects;
			}
			public function provider_key( $key ) {
				return 'wp-content/uploads/2026/09/' . $this->objects[ $key ]['source_file'];
			}
			public function region() {
				return 'us-east-1';
			}
			public function bucket() {
				return 'spio-test-bucket';
			}
			/** Same signature as as3cf Item::is_private() (items/item.php). */
			public function is_private( ?string $object_key = null ): bool {
				return ! empty( $this->objects[ $object_key ]['is_private'] );
			}
		};
	}

	/**
	 * Stand-in for the as3cf main object: get_provider_client() hands out a
	 * client whose copy_objects() records the requests and then either
	 * throws $throw or reports no failures.
	 *
	 * Also answers the ACL API WP Offload Media's own upload handler uses
	 * (classes/items/upload-handler.php), so replaceFiles() runs
	 * against these stubs instead of dying on an undefined method:
	 * get_storage_provider()->get_default_acl() / get_private_acl(), and
	 * use_acl_for_intermediate_size() — $useAcl false models a bucket that
	 * does not accept ACLs (Object Ownership "bucket owner enforced" /
	 * Block Public Access).
	 */
	private function stubAs3cf( ?\Throwable $throw, array &$copyRequests, int &$clientCalls, bool $useAcl = true ) {
		$client = new class( $throw, $copyRequests ) {
			private $throw;
			private $requests;
			public function __construct( $throw, &$requests ) {
				$this->throw    = $throw;
				$this->requests = &$requests;
			}
			public function copy_objects( array $requests ) {
				$this->requests = $requests;
				if ( null !== $this->throw ) {
					throw $this->throw;
				}
				return array();
			}
			public function delete_objects( array $args ) {}
		};

		return new class( $client, $clientCalls, $useAcl ) {
			private $client;
			private $calls;
			private $useAcl;
			public function __construct( $client, &$calls, bool $useAcl ) {
				$this->client = $client;
				$this->calls  = &$calls;
				$this->useAcl = $useAcl;
			}
			public function get_provider_client( $region = '', $force = false ) {
				$this->calls++;
				return $this->client;
			}
			public function get_storage_provider() {
				return new class() {
					public function get_default_acl() {
						return 'public-read';
					}
					public function get_private_acl() {
						return 'private';
					}
				};
			}
			public function use_acl_for_intermediate_size( int $attachment_id, string $size, ?string $bucket = null, $as3cf_item = null ): bool {
				return $this->useAcl;
			}
		};
	}

	/** An image model exposing only what replaceFiles() reads. */
	private function stubImageModel( array $duplicates = array() ) {
		$imageModel = new class() {
			public $duplicates = array();
			public function get( $name ) {
				return 'id' === $name ? 4242 : null;
			}
			public function getImageKey( $key ) {
				return 'main';
			}
			public function getWPMLDuplicates() {
				return $this->duplicates;
			}
		};
		$imageModel->duplicates = $duplicates;
		return $imageModel;
	}

	/** Source files as the optimizer hands them over (only names are read). */
	private function sourceFiles(): array {
		return array(
			'main'   => new \ShortPixel\Model\File\FileModel( '/tmp/spio-offload-test/photo.jpg' ),
			'medium' => new \ShortPixel\Model\File\FileModel( '/tmp/spio-offload-test/photo-300x225.jpg' ),
		);
	}

	/**
	 * Pins a known defect: "handled" claimed when no provider object matched.
	 * When none of the item's objects corresponds to a source file,
	 * $keyRenames stays empty and replaceFiles() returns true ("no rename
	 * needed"). OptimizeAiController::replaceFiles() treats true as "the
	 * offloader renamed the files" and skips its own local rename.
	 *
	 * Flip when: an unmatched item answers false, so SPIO keeps handling the
	 * local files itself.
	 */
	public function test_replaceFiles_claims_handled_when_no_provider_object_matches_pinned_for_deferred_fix() {
		$requests = array();
		$calls    = 0;
		$o        = $this->offloadWithStubs(
			$this->stubItem( array( '__as3cf_primary' => array( 'source_file' => 'unrelated-image.jpg', 'is_private' => false ) ) ),
			$this->stubAs3cf( null, $requests, $calls )
		);

		$result = $o->replaceFiles( false, $this->sourceFiles(), $this->stubImageModel(), 'renamed-photo' );

		// SENTINEL: nothing matched, so the provider was never contacted.
		$this->assertSame( 0, $calls, 'Sentinel: no provider client may be requested when no object matched.' );
		$this->assertSame( array(), $requests, 'Sentinel: no copy request was built.' );

		$this->assertTrue(
			$result,
			'Fixed? replaceFiles() no longer claims to have handled a rename it could not match — flip this pin.'
		);
	}

	/**
	 * Pins a known defect: provider exceptions escape.
	 * With matching objects, replaceFiles() builds one copy request per
	 * object and calls copy_objects(). Nothing catches an exception from the
	 * client (the unconfigured Null_Provider throws "Failed to instantiate
	 * the provider client"; a real client with bad credentials can too), so
	 * the rename request crashes.
	 *
	 * Flip when: the exception is caught (rename refused cleanly).
	 */
	public function test_replaceFiles_lets_provider_exceptions_escape_pinned_for_deferred_fix() {
		$requests = array();
		$calls    = 0;
		$failure  = new \Exception( 'Failed to instantiate the provider client.' );
		$o        = $this->offloadWithStubs(
			$this->stubItem(
				array(
					'__as3cf_primary' => array( 'source_file' => 'photo.jpg', 'is_private' => false ),
					'medium'          => array( 'source_file' => 'photo-300x225.jpg', 'is_private' => true ),
				)
			),
			$this->stubAs3cf( $failure, $requests, $calls )
		);

		$thrown = null;
		try {
			$o->replaceFiles( false, $this->sourceFiles(), $this->stubImageModel(), 'renamed-photo' );
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		// SENTINEL: the rename really reached copy_objects() with the right keys.
		$this->assertSame( 1, $calls, 'Sentinel: the provider client must have been requested once.' );
		$this->assertCount( 2, $requests, 'Sentinel: one copy request per matched object.' );
		$keys = array_column( $requests, 'Key' );
		$this->assertContains( 'wp-content/uploads/2026/09/renamed-photo.jpg', $keys, 'Sentinel: the main object is copied to the new key.' );
		$this->assertContains( 'wp-content/uploads/2026/09/renamed-photo-300x225.jpg', $keys, 'Sentinel: thumbnails follow the new base.' );

		// THE PIN.
		$this->assertSame(
			$failure,
			$thrown,
			'Fixed? A provider failure no longer escapes replaceFiles() — flip this pin.'
		);
	}

	/*
	 * ACL of rename copies.
	 *
	 * S3 CopyObject does NOT carry the source object's ACL over (the copy gets
	 * the bucket default, i.e. private on an ACL-enabled bucket), and
	 * 'MetadataDirective' => 'COPY' preserves metadata, not permissions. So
	 * SOME ACL must be sent, or renamed public images return 403 on classic
	 * public-ACL setups. A hardcoded 'public-read' would ignore the rule WP
	 * Offload Media applies to its own uploads
	 * (classes/items/upload-handler.php, 3.4.2):
	 *
	 *     $acl = $item->is_private($key) ? $provider->get_private_acl()
	 *                                    : $provider->get_default_acl();
	 *     only if $as3cf->use_acl_for_intermediate_size($id, $key, $bucket, $item)
	 *
	 * The copy requests follow that rule; one test per consequence.
	 */

	/**
	 * A PRIVATE object must keep the private ACL when it is copied to its
	 * new name; a public one gets the provider's default ACL. Forcing
	 * public-read would make private media readable by everyone.
	 */
	public function test_private_object_keeps_the_private_acl_on_rename() {
		$requests = array();
		$calls    = 0;
		$item     = $this->stubItem(
			array(
				'__as3cf_primary' => array( 'source_file' => 'photo.jpg', 'is_private' => false ),
				'medium'          => array( 'source_file' => 'photo-300x225.jpg', 'is_private' => true ),
			)
		);
		$o = $this->offloadWithStubs( $item, $this->stubAs3cf( null, $requests, $calls, true ) );
		// A completed rename resolves the primary object key via the media class.
		$this->setPrivate( $o, 'itemClassName', SPIO_Test_As3cf_Media_Class_Stub::class );

		$result = $o->replaceFiles( false, $this->sourceFiles(), $this->stubImageModel(), 'renamed-photo' );

		// SENTINELS: the rename really reached copy_objects(), and the medium
		// object really is private while the primary is not.
		$this->assertTrue( $result, 'Sentinel: with a succeeding client the provider rename completes.' );
		$this->assertCount( 2, $requests, 'Sentinel: one copy request per matched object.' );
		$this->assertTrue( $item->is_private( 'medium' ), 'Sentinel: the medium object is private.' );
		$this->assertFalse( $item->is_private( '__as3cf_primary' ), 'Sentinel: the primary object is public.' );

		$byKey = array_column( $requests, 'ACL', 'Key' );
		$this->assertSame(
			'private',
			$byKey['wp-content/uploads/2026/09/renamed-photo-300x225.jpg'] ?? null,
			'The PRIVATE object must be copied with the private ACL, not public-read.'
		);
		$this->assertSame(
			'public-read',
			$byKey['wp-content/uploads/2026/09/renamed-photo.jpg'] ?? null,
			'The public object gets the provider default ACL.'
		);
	}

	/**
	 * No ACL may be sent when the bucket does not accept ACLs (Object
	 * Ownership "bucket owner enforced", the AWS default for new buckets, or
	 * Block Public Access): S3 rejects such a copy, so every rename on those
	 * buckets would fail remotely.
	 * use_acl_for_intermediate_size() is how WP Offload Media knows this.
	 */
	public function test_no_acl_is_sent_to_a_bucket_that_disallows_acls() {
		$requests = array();
		$calls    = 0;
		$as3cf    = $this->stubAs3cf( null, $requests, $calls, false );
		$o        = $this->offloadWithStubs(
			$this->stubItem(
				array(
					'__as3cf_primary' => array( 'source_file' => 'photo.jpg', 'is_private' => false ),
					'medium'          => array( 'source_file' => 'photo-300x225.jpg', 'is_private' => false ),
				)
			),
			$as3cf
		);
		// A completed rename resolves the primary object key via the media class.
		$this->setPrivate( $o, 'itemClassName', SPIO_Test_As3cf_Media_Class_Stub::class );

		$o->replaceFiles( false, $this->sourceFiles(), $this->stubImageModel(), 'renamed-photo' );

		// SENTINELS: copy requests were built, and the bucket really reports
		// that ACLs must not be used.
		$this->assertCount( 2, $requests, 'Sentinel: one copy request per matched object.' );
		$this->assertFalse( $as3cf->use_acl_for_intermediate_size( 4242, '__as3cf_primary' ), 'Sentinel: the stub bucket does not accept ACLs.' );

		foreach ( $requests as $request ) {
			$this->assertArrayNotHasKey(
				'ACL',
				$request,
				'No ACL may be sent to a bucket that does not accept ACLs.'
			);
		}
	}

	/**
	 * Each object record must retain its filename suffix when the provider
	 * rename updates the as3cf item's source_file metadata.
	 */
	public function test_successful_rename_preserves_thumbnail_source_filename_suffix() {
		$requests = array();
		$calls    = 0;
		$item     = $this->stubItem(
			array(
				'__as3cf_primary' => array( 'source_file' => 'photo.jpg', 'is_private' => false ),
				'medium'          => array( 'source_file' => 'photo-300x225.jpg', 'is_private' => false ),
			)
		);
		$o = $this->offloadWithStubs( $item, $this->stubAs3cf( null, $requests, $calls ) );
		$this->setPrivate( $o, 'itemClassName', SPIO_Test_As3cf_Media_Class_Stub::class );

		$result = $o->replaceFiles( false, $this->sourceFiles(), $this->stubImageModel(), 'renamed-photo' );

		// SENTINEL: the rename completed and the BUCKET keys are right.
		$this->assertTrue( $result, 'Sentinel: the stubbed provider rename must complete.' );
		$this->assertSame( 1, $item->saveCalls, 'Sentinel: the item was saved once.' );
		$this->assertContains( 'wp-content/uploads/2026/09/renamed-photo-300x225.jpg', array_column( $requests, 'Key' ), 'Sentinel: the thumbnail object is copied to the correct new key.' );
		$this->assertSame( 'renamed-photo.jpg', $item->savedObjects['__as3cf_primary']['source_file'] ?? null, 'Sentinel: the main object is recorded correctly.' );

		$this->assertSame(
			'renamed-photo-300x225.jpg',
			$item->savedObjects['medium']['source_file'] ?? null,
			'Regression: the medium object must retain its size suffix.'
		);
	}

	public function test_wpml_duplicate_offload_item_metadata_tracks_shared_rename() {
		$requests = array();
		$calls    = 0;
		$objects  = array(
			'__as3cf_primary' => array( 'source_file' => 'photo.jpg', 'is_private' => false ),
			'medium'          => array( 'source_file' => 'photo-300x225.jpg', 'is_private' => false ),
		);
		$primary   = $this->stubItem( $objects );
		$duplicate = $this->stubItem( $objects, false );
		$o         = $this->offloadWithStubs(
			array( 4242 => $primary, 4243 => $duplicate ),
			$this->stubAs3cf( null, $requests, $calls )
		);
		$this->setPrivate( $o, 'itemClassName', SPIO_Test_As3cf_Media_Class_Stub::class );

		$result = $o->replaceFiles(
			false,
			$this->sourceFiles(),
			$this->stubImageModel( array( 4243 ) ),
			'renamed-photo'
		);

		$this->assertTrue( $result, 'The provider rename must complete.' );
		$this->assertCount( 2, $requests, 'The shared remote objects must be copied only once.' );
		$this->assertSame( 1, $primary->saveCalls, 'The main attachment item must be saved.' );
		$this->assertSame( 1, $duplicate->saveCalls, 'The WPML duplicate item must also be saved.' );
		$this->assertFalse( $o->duplicateLookupAfterPrimarySave, 'WPML items must be resolved before the primary as3cf item is changed.' );
		$this->assertSame( 'renamed-photo.jpg', $duplicate->savedObjects['__as3cf_primary']['source_file'] ?? null );
		$this->assertSame( 'renamed-photo-300x225.jpg', $duplicate->savedObjects['medium']['source_file'] ?? null );
		$this->assertSame( 'wp-content/uploads/2026/09/renamed-photo.jpg', $duplicate->savedPath );
	}
}

/**
 * Stand-in for the as3cf media-library item class name that
 * wpOffload::getMediaClass() returns when handlers are off; replaceFiles()
 * only calls its static primary_object_key().
 */
if ( ! class_exists( 'SPIO_Test_As3cf_Media_Class_Stub', false ) ) {
	final class SPIO_Test_As3cf_Media_Class_Stub {
		public static function primary_object_key(): string {
			return '__as3cf_primary';
		}
	}
}
