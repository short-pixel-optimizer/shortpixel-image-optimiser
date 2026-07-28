<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\StatsController;

/**
 * Ability: shortpixel/get-stats
 *
 * Returns optimization statistics: total images, optimized count,
 * thumbnails, average compression, and images remaining to optimize
 *
 * @package ShortPixel\Controller\Abilities
 */
class GetStatsAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input arguments (none required for this ability)
	 * @return array Stats data
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		$statsController = StatsController::getInstance();

		$mediaItems          = $statsController->find( 'media', 'items' );
		$mediaImages         = $statsController->find( 'media', 'images' );
		$mediaThumbs         = $statsController->find( 'media', 'thumbs' );
		$mediaItemsTotal     = $statsController->find( 'media', 'itemsTotal' );
		$mediaThumbsTotal    = $statsController->find( 'media', 'thumbsTotal' );

		$customItems         = $statsController->find( 'custom', 'items' );
		$customImages        = $statsController->find( 'custom', 'images' );
		$customItemsTotal    = $statsController->find( 'custom', 'itemsTotal' );

		$totalImages         = $statsController->find( 'total', 'images' );
		$totalItemsTotal     = $statsController->find( 'total', 'itemsTotal' );
		$totalThumbsTotal    = $statsController->find( 'total', 'thumbsTotal' );

		$averageCompression  = $statsController->getAverageCompression();
		$toOptimize          = $statsController->totalImagesToOptimize();
		$thumbsToOptimize    = $statsController->thumbNailsToOptimize();

		return [
			'media_library' => [
				'items_optimized'    => (int) $mediaItems,
				'images_optimized'   => (int) $mediaImages,
				'thumbs_optimized'   => (int) $mediaThumbs,
				'items_total'        => (int) $mediaItemsTotal,
				'thumbs_total'       => (int) $mediaThumbsTotal,
			],
			'custom_media' => [
				'items_optimized'    => (int) $customItems,
				'images_optimized'   => (int) $customImages,
				'items_total'        => (int) $customItemsTotal,
			],
			'totals' => [
				'images_optimized'   => (int) $totalImages,
				'items_total'        => (int) $totalItemsTotal,
				'thumbs_total'       => (int) $totalThumbsTotal,
				'to_optimize'        => max( 0, (int) $toOptimize ),
				'thumbs_to_optimize' => max( 0, (int) $thumbsToOptimize ),
			],
			'average_compression_percent' => (float) $averageCompression,
		];
	}
}
