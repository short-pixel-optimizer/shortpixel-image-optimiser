<?php
/**
 * Tests for ShortPixel\External\Offload\VirtualFileSystem.
 *
 * Focus areas:
 *   - Passthrough / constant-return methods (getLocalPathByURL,
 *     extraFeatures, isActive)
 *   - Sentinel test pinning the intended (bug-free) contract of
 *     checkIfOffloaded — see the `_pinned_for_deferred_fix` note below
 *
 * Skipped at the unit level (integration territory):
 *   - __construct → calls listen() which registers three add_filter hooks
 *   - listen     → pure hook registration; asserting hooks were added
 *                  would duplicate WordPress's own registry
 *
 * One test is pinned to the intended contract of a method that ships
 * with a real bug (see project_deferred_root_bugs.md):
 *
 *   - `checkIfOffloaded` uses `=` (assignment) instead of `==` in the
 *     first branch: `if ($this->offloadName = 's3-uploads-human')`.
 *     The assignment silently rewrites `$this->offloadName` on every
 *     call. Intended: leave the property alone.
 *
 * The `*_pinned_for_deferred_fix` test will FAIL until the `=` is
 * changed to `==` (or `===`).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\External\Offload\VirtualFileSystem;

class VirtualFileSystemTest extends WP_UnitTestCase {

	/*
	 * Reflection helpers
	 */

	private function getPrivate( VirtualFileSystem $v, string $prop ) {
		$ref = new ReflectionClass( VirtualFileSystem::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $v );
	}

	private function setPrivate( VirtualFileSystem $v, string $prop, $value ): void {
		$ref = new ReflectionClass( VirtualFileSystem::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $v, $value );
	}

	/**
	 * Build a VirtualFileSystem without running the constructor (which
	 * would register the three add_filter hooks via listen()).
	 */
	private function freshVirtualFileSystem(): VirtualFileSystem {
		$ref = new ReflectionClass( VirtualFileSystem::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/*
	 * getLocalPathByURL — base implementation is a passthrough
	 */

	public function test_getLocalPathByURL_returns_the_path_unchanged() {
		$v = $this->freshVirtualFileSystem();

		$this->assertSame(
			'https://bucket.example.test/2024/06/photo.jpg',
			$v->getLocalPathByURL( 'https://bucket.example.test/2024/06/photo.jpg' )
		);
	}

	/*
	 * extraFeatures — hard-disables the expensive scan features
	 */

	public function test_extraFeatures_returns_false() {
		$v = $this->freshVirtualFileSystem();

		$result = $v->extraFeatures();

		$this->assertFalse( $result );
		// Sentinel: assertFalse alone can slip past a null-returning method.
		// Assert strict type so a null regression fails loudly.
		$this->assertIsBool( $result );
	}

	/*
	 * isActive — always true for virtual filesystems (no off-state)
	 */

	public function test_isActive_returns_true() {
		$v = $this->freshVirtualFileSystem();

		$this->assertTrue( $v->isActive() );
	}

	/*
	 * checkIfOffloaded — pinned sentinel for the `=` vs `==` bug
	 */

	/**
	 * PINNED for deferred fix — `checkIfOffloaded` at
	 * virtual-filesystem.php uses `=` (assignment) instead of `==` in
	 * `if ($this->offloadName = 's3-uploads-human')`. The assignment
	 * silently rewrites `$this->offloadName` on every call regardless of
	 * the offloader's actual identity.
	 *
	 * Intended behaviour: `$this->offloadName` should be treated as
	 * read-only inside a comparison — the property must not be mutated
	 * by a lookup call.
	 *
	 * This test will FAIL until the `=` is changed to `==` (or `===`).
	 *
	 * Sentinel principle #4 from feedback_pinned_test_sentinels.md:
	 * make ID-like fields distinct so the wrong branch has a visible
	 * consequence. Here we seed `stack` (any non-target value) and check
	 * it's unchanged after the call.
	 */
	public function test_checkIfOffloaded_does_not_mutate_offloadName_pinned_for_deferred_fix() {
		$v = $this->freshVirtualFileSystem();
		$this->setPrivate( $v, 'offloadName', 'stack' );

		$v->checkIfOffloaded( false, '/nonexistent/spio-test-file.jpg', '/nonexistent/spio-test-file.jpg' );

		$this->assertSame(
			'stack',
			$this->getPrivate( $v, 'offloadName' ),
			'checkIfOffloaded silently rewrote offloadName — the `=` in the first branch is an assignment, not a comparison'
		);
	}

	/**
	 * Companion happy-path assertion: when offloadName IS
	 * `s3-uploads-human`, checkIfOffloaded should still identify the file
	 * as stateless. Pinned alongside the mutation sentinel above so the
	 * fix ("change `=` to `==`") can be verified end-to-end.
	 */
	public function test_checkIfOffloaded_returns_VIRTUAL_STATELESS_for_s3_uploads_human_pinned_for_deferred_fix() {
		$v = $this->freshVirtualFileSystem();
		$this->setPrivate( $v, 'offloadName', 's3-uploads-human' );

		$result = $v->checkIfOffloaded( false, '/nonexistent/spio-test-file.jpg', '/nonexistent/spio-test-file.jpg' );

		$this->assertSame( \ShortPixel\Model\File\FileModel::$VIRTUAL_STATELESS, $result );
	}
}
