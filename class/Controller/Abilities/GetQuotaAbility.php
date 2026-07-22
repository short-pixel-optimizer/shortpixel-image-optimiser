<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\QuotaController;

/**
 * Ability: shortpixel/get-quota
 *
 * Returns the current ShortPixel account quota information:
 * monthly credits, one-time credits, AI credits, totals, and
 * whether the quota is exceeded
 *
 * @package ShortPixel\Controller\Abilities
 */
class GetQuotaAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input arguments (none required for this ability)
	 * @return array Quota data
	 */
	public static function execute( $args )
	{
		$quotaController = QuotaController::getInstance();
		$quota = $quotaController->getQuota();

		return [
			'quota_exceeded' => ! $quotaController->hasQuota(),
			'unlimited'      => (bool) $quota->unlimited,
			'monthly' => [
				'total'     => (int) $quota->monthly->total,
				'consumed'  => (int) $quota->monthly->consumed,
				'remaining' => (int) $quota->monthly->remaining,
				'renew_days' => (int) $quota->monthly->renew,
			],
			'onetime' => [
				'total'     => (int) $quota->onetime->total,
				'consumed'  => (int) $quota->onetime->consumed,
				'remaining' => (int) $quota->onetime->remaining,
			],
			'ai' => [
				'unlimited' => (bool) $quota->AIUnlimited,
				'total'     => (int) $quota->ai->total,
				'consumed'  => (int) $quota->ai->consumed,
				'remaining' => (int) $quota->ai->remaining,
			],
			'total' => [
				'total'     => (int) $quota->total->total,
				'consumed'  => (int) $quota->total->consumed,
				'remaining' => (int) $quota->total->remaining,
			],
		];
	}
}
