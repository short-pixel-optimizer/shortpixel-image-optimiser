<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\StatsController as StatsController;
use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\QuotaController as QuotaController;

/**
 * Admin notice suggesting a plan upgrade when the user's monthly image usage
 * is projected to exceed the current plan quota.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class QuotaNoticeMonth extends \ShortPixel\Model\AdminNoticeModel
{
	/** @var string Unique notice key. */
	protected $key = 'MSG_UPGRADE_MONTH';

	/**
	 * Loads the notice and triggers the upgrade popup when the notice is active.
	 *
	 * @return void
	 */
	public function load()
	{
    $bool = parent::load();


	}

	/**
	 * Checks whether the notice should be triggered based on monthly usage projections.
	 * Stores the calculated average and quota data for use in getMessage().
	 *
	 * @return void
	 */
	protected function checkTrigger()
	{
			$quotaController = QuotaController::getInstance();

			if ($quotaController->hasQuota() === false)
				return false;

			$quotaData = $quotaController->getQuota();

			if ($this->monthlyUpgradeNeeded($quotaData) === false)
				return false;

			$this->addData('average', $this->getMonthAverage());
			$this->addData('month_total', $quotaData->monthly->total);
			$this->addData('onetime_remaining', $quotaData->onetime->remaining);

	}

	/**
	 * Builds the HTML message showing quota usage statistics and an upgrade prompt button.
	 *
	 * @return string HTML message string.
	 */
	protected function getMessage()
	{
		$quotaController = QuotaController::getInstance();

		$quotaData = $quotaController->getQuota();
		$average = $this->getMonthAverage(); // $this->getData('average');
		$month_total = $quotaData->monthly->total;// $this->getData('month_total');
		$onetime_remaining = $quotaData->onetime->remaining; //$this->getData('onetime_remaining'); */

		$message = '<p>' . sprintf(__("You add an average of %s %d images and thumbnails %s to your Media Library every month and you have <strong>a plan of %d images/month (and %d one-time images)</strong>.%s"
					. " You may need to upgrade your plan to have all your images optimized.", 'shortpixel-image-optimiser'), '<strong>', $average, '</strong>', $month_total, $onetime_remaining, '<br>') . '</p>';

		$upgradeButton = sprintf('<a href="https://shortpixel.com/ms/af/KZYK08Q28044" target="_blank" class="button button-primary" style="margin-right:10px;" >
							%s </a> ',
				   __('Upgrade to Unlimited', 'shortpixel-image-optimiser'));


		$message .= $upgradeButton;

		return $message;
	}

	/**
	 * Calculates the average number of images added per active month over the last four months.
	 *
	 * @return float Average image count per active month.
	 */
	protected function getMonthAverage() {
			$stats = StatsController::getInstance();

			// Count how many months have some optimized images.
			for($i = 4, $count = 0; $i>=1; $i--) {
					if($count == 0 && $stats->find('period', 'months', $i) == 0)
					{
						continue;
					}
					$count++;

			}
			// Sum last 4 months, and divide by number of active months to get number of avg per active month.
			return ($stats->find('period', 'months', 1) + $stats->find('period', 'months', 2) + $stats->find('period', 'months', 3) + $stats->find('period', 'months', 4) / max(1,$count));
	}

	/**
	 * Determines whether the user's average monthly image count exceeds the plan threshold.
	 *
	 * @param object $quotaData Quota data object containing monthly and one-time credit information.
	 * @return bool True if an upgrade is likely needed, false otherwise.
	 */
	protected function monthlyUpgradeNeeded($quotaData)
	{
			if  (isset($quotaData->monthly->total))
			{
					$monthAvg = $this->getMonthAverage($quotaData);
					// +20 I suspect to not trigger on very low values of monthly use(?)
					$threshold = $quotaData->monthly->total + ($quotaData->onetime->remaining / 6 ) +20;

					if ($monthAvg > $threshold)
					{
							return true;
					}
			}
			return false;
	}
} // class
