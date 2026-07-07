<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\QuotaNoticeMonth.
 *
 * Skipped at the unit level (integration territory):
 *   - Full load() lifecycle → hits NoticeController + calls
 *     proposeUpgradePopup() on success
 *   - checkTrigger → depends on QuotaController state + StatsController
 *     history (last 4 months of shortpixel_postmeta counts)
 *   - getMessage → assembles live quota + stats numbers; getMonthAverage
 *     touches StatsController::find which lazily hits DB
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\QuotaNoticeMonth;

class QuotaNoticeMonthTest extends WP_UnitTestCase {

	public function test_key_is_MSG_UPGRADE_MONTH() {
		$this->assertSame( 'MSG_UPGRADE_MONTH', ( new QuotaNoticeMonth() )->getKey() );
	}

	public function test_monthlyUpgradeNeeded_false_when_average_below_threshold() {
		$m = new QuotaNoticeMonth();

		$ref = new ReflectionClass( QuotaNoticeMonth::class );
		$method = $ref->getMethod( 'monthlyUpgradeNeeded' );
		$method->setAccessible( true );

		// Stub QuotaController's payload shape: monthly total 1000, no onetime left.
		$quotaData = (object) array(
			'monthly' => (object) array( 'total' => 1000 ),
			'onetime' => (object) array( 'remaining' => 0 ),
		);

		// The threshold is total + onetime/6 + 20 = 1020. Average of 10 is well below.
		// getMonthAverage reads from StatsController; a low seeded state gives 0.
		\wpSPIO()->settings()->currentStats = array(
			'period' => array( 'months' => array( '1' => 0, '2' => 0, '3' => 0, '4' => 0 ) ),
			'time'   => time(),
		);

		$this->assertFalse( $method->invoke( $m, $quotaData ) );
	}

	public function test_monthlyUpgradeNeeded_false_when_monthly_total_missing_from_quota_data() {
		$m = new QuotaNoticeMonth();

		$ref = new ReflectionClass( QuotaNoticeMonth::class );
		$method = $ref->getMethod( 'monthlyUpgradeNeeded' );
		$method->setAccessible( true );

		$quotaData = (object) array( 'onetime' => (object) array( 'remaining' => 0 ) );

		$this->assertFalse( $method->invoke( $m, $quotaData ) );
	}
}
