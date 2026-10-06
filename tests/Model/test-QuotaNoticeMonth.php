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

	/**
	 * getMonthAverage() must divide the SUM by the active-month count; with
	 * wrong operator precedence only month 4 is divided, so 4 active months
	 * of 100 images each show an "average per month" of 325 instead of 100.
	 */
	public function test_getMonthAverage_divides_the_sum_of_all_active_months() {
		\wpSPIO()->settings()->currentStats = array(
			'period' => array( 'months' => array( '1' => 100, '2' => 100, '3' => 100, '4' => 100 ) ),
			'time'   => time(),
		);
		// Fresh StatsController, so it reads the seeded stats.
		$prop = ( new ReflectionClass( \ShortPixel\Controller\StatsController::class ) )->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$stats = \ShortPixel\Controller\StatsController::getInstance();
		$this->assertEquals( 100, $stats->find( 'period', 'months', 4 ), 'Sentinel: the seeded month-4 count is read.' );

		$method = ( new ReflectionClass( QuotaNoticeMonth::class ) )->getMethod( 'getMonthAverage' );
		$method->setAccessible( true );
		$this->assertEquals( 100, $method->invoke( new QuotaNoticeMonth() ), 'Average of four active months of 100 must be 100.' );

		$prop->setValue( null, null ); // don't leak the seeded controller into other tests
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
