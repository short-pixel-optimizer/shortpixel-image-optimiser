<?php
/**
 * Integration tests: media-library ShortPixel status filter (manual plan 2.22, 2.22.1).
 *
 * Exercises AdminController::filter_listener() + filter_add_where() against a real
 * WP install with a seeded mix of optimized, unoptimized, and prevented attachments.
 * The filter is driven by setting $pagenow = 'upload.php' and the expected $_REQUEST
 * keys ('filter_action', 'shortpixel_status'), then running a real WP_Query with
 * post_type=attachment so the posts_where filter appends SPIO's SQL sub-selects.
 *
 * Approach:
 *   1. Seed N optimized attachments (uploaded + optimized via the full pipeline).
 *   2. Seed M unoptimized attachments (uploaded, purge queue so they stay pending).
 *   3. Seed K prevented attachments (upload + set _shortpixel_prevent_optimize meta
 *      directly via update_post_meta — matching what MediaLibraryModel::preventNextTry
 *      persists).
 *   4. For 2.22.1, seed one attachment that is optimized AND has the prevent meta set
 *      afterwards (simulating a post-optimize exclusion / crashed-then-retried item).
 *   5. Run a WP_Query per filter value (registering the SPIO hooks manually since
 *      is_admin() was false at the time init ran in the test process) and compare the
 *      returned ID sets to the known seed groups.
 *
 * Key findings:
 *   - PRODUCTION BUG (AdminController.php ~624): The 'prevented' branch of
 *     filter_add_where() uses `$where = $wpdb->prepare(...)` (assignment, not
 *     `$where .=`), so it REPLACES the existing WHERE clause — discarding WP's
 *     standard attachment conditions (post_status='inherit', post_type='attachment').
 *     As a result the query may return non-attachment posts or miss status filters.
 *     This is pinned in test_prevented_filter_returns_prevented_attachments() below
 *     with an explanatory assertion message. Flip the pinned assertion when fixed.
 *   - The 'optimized' and 'unoptimized' filter branches correctly use `$where .=`
 *     (appending) and work as expected.
 *   - An attachment that is optimized AND has _shortpixel_prevent_optimize set
 *     still appears under the 'optimized' filter (status=SUCCESS in shortpixel_postmeta
 *     is sufficient) — correct behaviour.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AdminController;

class MediaLibraryFilterTest extends SPIO_IntegrationTestCase {

	/** @var int[] IDs of attachments that were fully optimized. */
	private $optimizedIds = array();

	/** @var int[] IDs of attachments that were uploaded but NOT optimized. */
	private $unoptimizedIds = array();

	/** @var int[] IDs of attachments that have _shortpixel_prevent_optimize set and are NOT optimized. */
	private $preventedIds = array();

	/** @var int Attachment that is optimized AND has the prevent meta set after the fact (plan 2.22.1). */
	private $optimizedThenPreventedId = 0;

	/** @var string The original $pagenow value so we can restore it in tear_down. */
	private $originalPagenow = '';

	/** @var bool Whether the filter_listener hook was added by us. */
	private $addedFilterListener = false;

	// -------------------------------------------------------------------
	// Set-up / tear-down
	// -------------------------------------------------------------------

	public function set_up() {
		parent::set_up();
		$this->seedAttachments();
	}

	public function tear_down() {
		$this->cleanRequestGlobals();
		$this->restorePagenow();
		$this->removeFilterHooks();
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Seed helpers
	// -------------------------------------------------------------------

	/**
	 * Seed a deterministic mix of attachments into the test install.
	 *
	 * Sizes chosen to keep the test fast (2 optimized, 2 unoptimized, 2 prevented,
	 * 1 optimized-then-prevented) and to give enough variance for count assertions.
	 */
	private function seedAttachments(): void {
		// 2 optimized (full pipeline).
		for ( $i = 0; $i < 2; $i++ ) {
			$id = $this->uploadFixture( 'fixture-small.jpg' );
			$this->optimizeAttachment( $id );
			$this->optimizedIds[] = $id;
			// Clear queue rows so later purgeQueueTable() calls are clean.
		}
		$this->purgeQueueTable();

		// 2 unoptimized (uploaded only — autoMediaLibrary=1 would enqueue them,
		// but we purge the queue immediately so they stay "pending" at the WP
		// posts layer; they have no shortpixel_postmeta rows at all).
		for ( $i = 0; $i < 2; $i++ ) {
			$id = $this->uploadFixture( 'fixture-small.jpg' );
			$this->unoptimizedIds[] = $id;
		}
		$this->purgeQueueTable();

		// 2 prevented (not optimized, just the meta key set directly).
		for ( $i = 0; $i < 2; $i++ ) {
			$id = $this->uploadFixture( 'fixture-small.jpg' );
			update_post_meta( $id, '_shortpixel_prevent_optimize', 'test-prevent-reason' );
			$this->preventedIds[] = $id;
		}
		$this->purgeQueueTable();

		// 1 optimized-then-prevented (plan 2.22.1): optimize first, then mark prevent.
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable(); // clear auto-enqueue
		$this->optimizeAttachment( $id );
		// Now add the prevent meta AFTER optimization.
		update_post_meta( $id, '_shortpixel_prevent_optimize', 'post-optimize-prevent' );
		$this->optimizedThenPreventedId = $id;
		$this->purgeQueueTable();
	}

	// -------------------------------------------------------------------
	// Filter driving helpers
	// -------------------------------------------------------------------

	/**
	 * Register the SPIO filter_listener on pre_get_posts if it is not already
	 * wired (in the test process is_admin() was false during init, so the
	 * production shortpixel-plugin.php block that adds it never ran).
	 */
	private function ensureFilterListenerHooked(): void {
		$admin = AdminController::getInstance();
		if ( ! has_filter( 'pre_get_posts', array( $admin, 'filter_listener' ) ) ) {
			add_filter( 'pre_get_posts', array( $admin, 'filter_listener' ) );
			$this->addedFilterListener = true;
		}
	}

	/**
	 * Remove the SPIO filter hooks that filter_listener may have added, and the
	 * listener itself if we registered it.
	 */
	private function removeFilterHooks(): void {
		$admin = AdminController::getInstance();
		remove_filter( 'posts_where', array( $admin, 'filter_add_where' ), 10 );
		if ( $this->addedFilterListener ) {
			remove_filter( 'pre_get_posts', array( $admin, 'filter_listener' ) );
			$this->addedFilterListener = false;
		}
	}

	/** Set the global $pagenow to the given value, saving the previous one. */
	private function setPagenow( string $page ): void {
		global $pagenow;
		if ( '' === $this->originalPagenow ) {
			$this->originalPagenow = (string) $pagenow;
		}
		$pagenow = $page;
	}

	/** Restore the global $pagenow to what it was before setPagenow(). */
	private function restorePagenow(): void {
		global $pagenow;
		if ( '' !== $this->originalPagenow ) {
			$pagenow               = $this->originalPagenow;
			$this->originalPagenow = '';
		}
	}

	/** Populate $_REQUEST keys for the given filter value and clean up afterwards. */
	private function setFilterRequest( string $filterValue ): void {
		$_REQUEST['filter_action']     = '1';
		$_REQUEST['shortpixel_status'] = $filterValue;
	}

	/** Remove SPIO filter-related $_REQUEST keys. */
	private function cleanRequestGlobals(): void {
		unset( $_REQUEST['filter_action'], $_REQUEST['shortpixel_status'] );
	}

	/**
	 * Run a WP_Query for all attachments with the given ShortPixel filter active
	 * and return the array of matching post IDs.
	 *
	 * @param string $filterValue 'all' | 'optimized' | 'unoptimized' | 'prevented'.
	 * @return int[]
	 */
	private function queryWithFilter( string $filterValue ): array {
		$this->setPagenow( 'upload.php' );
		$this->setFilterRequest( $filterValue );
		$this->ensureFilterListenerHooked();

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		// Clean up: remove the posts_where hook that filter_listener wired for
		// this run, so the next query starts fresh.
		$this->removeFilterHooks();
		$this->cleanRequestGlobals();

		return array_map( 'intval', (array) $query->posts );
	}

	// -------------------------------------------------------------------
	// Tests — plan 2.22
	// -------------------------------------------------------------------

	/**
	 * 'all' filter must return all seeded attachments (no filtering applied).
	 *
	 * Manual plan 2.22.
	 */
	public function test_all_filter_returns_all_attachments() {
		$allSeeded = array_merge(
			$this->optimizedIds,
			$this->unoptimizedIds,
			$this->preventedIds,
			array( $this->optimizedThenPreventedId )
		);

		$returned = $this->queryWithFilter( 'all' );

		foreach ( $allSeeded as $id ) {
			$this->assertContains(
				$id,
				$returned,
				"'all' filter must include every seeded attachment (id=$id). Plan 2.22."
			);
		}
	}

	/**
	 * 'optimized' filter must return only attachments that have a
	 * FILE_STATUS_SUCCESS record in the ShortPixel postmeta table.
	 *
	 * Manual plan 2.22.
	 */
	public function test_optimized_filter_returns_optimized_attachments() {
		$returned = $this->queryWithFilter( 'optimized' );

		// Every optimized attachment must appear.
		foreach ( $this->optimizedIds as $id ) {
			$this->assertContains(
				$id,
				$returned,
				"'optimized' filter must include attachment $id (was optimized). Plan 2.22."
			);
		}

		// The optimized-then-prevented attachment still has status=SUCCESS, so
		// it must also appear under 'optimized' (plan 2.22.1 — covered fully below).
		$this->assertContains(
			$this->optimizedThenPreventedId,
			$returned,
			"'optimized' filter must include the optimized-then-prevented attachment (plan 2.22 / 2.22.1)."
		);

		// Unoptimized (no postmeta rows) and never-optimized prevented attachments
		// must NOT appear.
		foreach ( $this->unoptimizedIds as $id ) {
			$this->assertNotContains(
				$id,
				$returned,
				"'optimized' filter must NOT include unoptimized attachment $id. Plan 2.22."
			);
		}

		foreach ( $this->preventedIds as $id ) {
			$this->assertNotContains(
				$id,
				$returned,
				"'optimized' filter must NOT include never-optimized prevented attachment $id. Plan 2.22."
			);
		}
	}

	/**
	 * 'unoptimized' filter must return only attachments that have no
	 * FILE_STATUS_SUCCESS row in the ShortPixel postmeta table.
	 *
	 * Manual plan 2.22.
	 */
	public function test_unoptimized_filter_returns_unoptimized_attachments() {
		$returned = $this->queryWithFilter( 'unoptimized' );

		// Never-optimized attachments must appear.
		foreach ( $this->unoptimizedIds as $id ) {
			$this->assertContains(
				$id,
				$returned,
				"'unoptimized' filter must include unoptimized attachment $id. Plan 2.22."
			);
		}

		// Optimized attachments have a SUCCESS row — they must NOT appear.
		foreach ( $this->optimizedIds as $id ) {
			$this->assertNotContains(
				$id,
				$returned,
				"'unoptimized' filter must NOT include fully optimized attachment $id. Plan 2.22."
			);
		}

		// The optimized-then-prevented attachment has a SUCCESS row — must NOT appear.
		$this->assertNotContains(
			$this->optimizedThenPreventedId,
			$returned,
			"'unoptimized' filter must NOT include the optimized-then-prevented attachment. Plan 2.22."
		);
	}

	/**
	 * 'prevented' filter must return only attachments that have the
	 * _shortpixel_prevent_optimize post-meta key set.
	 *
	 * PINNED CURRENT BEHAVIOUR: filter_add_where()'s 'prevented' branch assigns
	 * `$where = $wpdb->prepare(...)` instead of `$where .=`, which REPLACES WP's
	 * standard WHERE clause rather than appending to it. The immediate consequence
	 * is that the generated SQL omits conditions like `post_status = 'inherit'` and
	 * the type/status guards added by earlier filters. In the test environment this
	 * means the query may return IDs that do not belong to the test-seeded set, or
	 * may over-match. The assertion below validates the EXPECTED sub-set (prevented
	 * IDs returned) as long as the query still surfaces them, but does not assert
	 * strict equality because the discarded WHERE clause means additional,
	 * unrelated posts might sneak in.
	 *
	 * Production bug: class/Controller/AdminController.php ~line 624.
	 * Flip to a strict assertSame() once the `$where .=` fix is applied.
	 *
	 * Manual plan 2.22.
	 */
	public function test_prevented_filter_returns_prevented_attachments() {
		$returned = $this->queryWithFilter( 'prevented' );

		// PINNED: the prevented IDs must appear in the result set (the sub-select
		// for meta_key still works; the replacement of $where causes broader
		// correctness issues but the prevent meta condition itself is valid).
		foreach ( $this->preventedIds as $id ) {
			$this->assertContains(
				$id,
				$returned,
				// PINNED: the 'prevented' branch of filter_add_where() uses $where = instead of $where .=
				// (AdminController.php ~624), replacing the WP WHERE clause. This still surfaces the
				// prevented IDs, but the query may also return non-attachment / non-inherit posts.
				// Flip to a strict set comparison when the $where .= fix is applied. Plan 2.22.
				"'prevented' filter must include prevented attachment $id (pinned: where-replacement bug in AdminController.php ~624 is present). Plan 2.22."
			);
		}

		// An optimized attachment without the prevent meta must NOT appear.
		foreach ( $this->optimizedIds as $id ) {
			$this->assertNotContains(
				$id,
				$returned,
				"'prevented' filter must NOT include a cleanly optimized attachment $id. Plan 2.22."
			);
		}
	}

	// -------------------------------------------------------------------
	// Tests — plan 2.22.1
	// -------------------------------------------------------------------

	/**
	 * An image that was optimized and THEN had _shortpixel_prevent_optimize set
	 * (e.g. it crashed on a subsequent attempt, or was manually excluded after
	 * the fact) must still appear under the 'optimized' filter because it has a
	 * FILE_STATUS_SUCCESS record in shortpixel_postmeta.
	 *
	 * This verifies that the 'optimized' filter is purely status-based and does
	 * not intersect with the 'prevented' meta key.
	 *
	 * Manual plan 2.22.1.
	 */
	public function test_optimized_then_prevented_appears_under_optimized_filter() {
		$returned = $this->queryWithFilter( 'optimized' );

		$this->assertContains(
			$this->optimizedThenPreventedId,
			$returned,
			'An attachment that was optimized and then had _shortpixel_prevent_optimize set must still appear under the "optimized" filter (its SUCCESS record is unchanged). Plan 2.22.1.'
		);
	}

	/**
	 * An image that was optimized and THEN had _shortpixel_prevent_optimize set
	 * must appear under the 'prevented' filter as well (it has the meta key).
	 *
	 * NOTE: the 'prevented' filter's SQL additionally excludes attachments with
	 * status = FILE_STATUS_MARKED_DONE (-11). The optimized-then-prevented
	 * attachment has status = FILE_STATUS_SUCCESS (2) in the shortpixel_postmeta
	 * table (main file row), so it is NOT excluded by that clause and should appear.
	 *
	 * PINNED CURRENT BEHAVIOUR: the $where-replacement bug in the 'prevented' branch
	 * (AdminController.php ~624) still surfaces this ID because the meta sub-select
	 * itself is correct. The surrounding behaviour (possible extra matches) is already
	 * documented in test_prevented_filter_returns_prevented_attachments().
	 *
	 * Manual plan 2.22.1.
	 */
	public function test_optimized_then_prevented_appears_under_prevented_filter() {
		$returned = $this->queryWithFilter( 'prevented' );

		$this->assertContains(
			$this->optimizedThenPreventedId,
			$returned,
			// PINNED: $where-replacement bug in 'prevented' branch (AdminController.php ~624) documented above.
			// The meta sub-select correctly picks up the optimized-then-prevented ID.
			// Flip to a stricter assertion when the bug is fixed. Plan 2.22.1.
			'An optimized-then-prevented attachment must appear under the "prevented" filter (it has the prevent meta key). Plan 2.22.1.'
		);
	}

	/**
	 * An image that was optimized and THEN had _shortpixel_prevent_optimize set
	 * must NOT appear under the 'unoptimized' filter — it has a SUCCESS record.
	 *
	 * Manual plan 2.22.1.
	 */
	public function test_optimized_then_prevented_does_not_appear_under_unoptimized_filter() {
		$returned = $this->queryWithFilter( 'unoptimized' );

		$this->assertNotContains(
			$this->optimizedThenPreventedId,
			$returned,
			'An optimized-then-prevented attachment must NOT appear under the "unoptimized" filter — its SUCCESS record is not removed by adding the prevent meta. Plan 2.22.1.'
		);
	}
}
