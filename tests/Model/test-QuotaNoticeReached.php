<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\QuotaNoticeReached.
 *
 * Skipped at the unit level (integration territory):
 *   - Full load() lifecycle → hits NoticeController and unconditionally
 *     calls proposeUpgradePopup() when the notice is active
 *   - checkTrigger → depends on QuotaController::hasQuota() (real quota
 *     response) plus resets two sibling notices
 *   - getMessage → wires StatsController averages, QuotaController payload,
 *     and ApiKeyController key-for-display into a large HTML block
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\QuotaNoticeReached;

class QuotaNoticeReachedTest extends WP_UnitTestCase {

	private function getInherited( $instance, string $prop ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $instance );
	}

	public function test_key_is_MSG_QUOTA_REACHED() {
		$this->assertSame( 'MSG_QUOTA_REACHED', ( new QuotaNoticeReached() )->getKey() );
	}

	public function test_errorLevel_is_error() {
		$this->assertSame( 'error', $this->getInherited( new QuotaNoticeReached(), 'errorLevel' ) );
	}
}
