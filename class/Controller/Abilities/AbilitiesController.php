<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Integration with the WordPress Abilities API (WP 6.9+).
 *
 * Registers SPIO abilities so they can be consumed via REST and exposed as
 * MCP tools when the site runs the WP MCP Adapter. This controller is a thin
 * registration layer only — the actual logic lives in the existing
 * controllers (QuotaController, StatsController, QueueController, etc).
 *
 * Behaviour on WP < 6.9 (or without the Abilities API feature plugin):
 * the `abilities_api_*` hooks never fire, so this whole controller is a
 * silent no-op. The `function_exists` guards inside the callbacks are a
 * second line of defense only.
 *
 * Registration flow:
 *   1. `abilities_api_categories_init` → registers the `shortpixel` category
 *   2. `abilities_api_init` → registers every ability from getAbilities()
 *
 * Third-parties can prevent registration entirely by returning false on
 * the `shortpixel/abilities/init` filter.
 *
 * @package ShortPixel\Controller\Abilities
 */
class AbilitiesController
{
	/** @var AbilitiesController|null Singleton instance */
	protected static $instance;

	/** @var string Category slug shared by all SPIO abilities */
	const ABILITY_CATEGORY = 'shortpixel';

	/**
	 * Singleton instance
	 *
	 * @return AbilitiesController
	 */
	public static function getInstance()
	{
		if ( is_null( self::$instance ) ) {
			self::$instance = new AbilitiesController();
		}
		return self::$instance;
	}

	/**
	 * Hook the Abilities API registration points. Both hooks are fired by
	 * the Abilities API itself, so nothing runs when the API is absent
	 */
	public function __construct()
	{
		add_action( 'abilities_api_categories_init', [ $this, 'registerCategories' ] );
		add_action( 'abilities_api_init', [ $this, 'registerAbilities' ] );
	}

	/**
	 * Register the shared ability category for all SPIO abilities.
	 * Runs on `abilities_api_categories_init`
	 *
	 * @return void
	 */
	public function registerCategories()
	{
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		\wp_register_ability_category( self::ABILITY_CATEGORY, [
			'label' => __( 'ShortPixel Image Optimizer', 'shortpixel-image-optimiser' ),
		] );
	}

	/**
	 * Register all SPIO abilities. Runs on `abilities_api_init`.
	 *
	 * Can be short-circuited by third parties via the
	 * `shortpixel/abilities/init` filter (return false to disable)
	 *
	 * @return void
	 */
	public function registerAbilities()
	{
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		/* Filter to prevent SPIO from registering abilities. Return false to disable */
		if ( false === apply_filters( 'shortpixel/abilities/init', true ) ) {
			return;
		}

		foreach ( $this->getAbilities() as $name => $args ) {
			$result = \wp_register_ability( $name, $args );

			if ( false === $result ) {
				Log::addWarn( 'Ability registration failed: ' . $name );
			}
		}
	}

	/**
	 * Return the ability definitions to register, keyed by ability name.
	 *
	 * Each entry is the args array passed to `wp_register_ability()`
	 *
	 * @return array<string, array>
	 */
	protected function getAbilities()
	{
		$meta = $this->getDefaultMeta();

		$abilities = [];

		// --- Read-only abilities for now (to see if i'm on right path adn i'll introduce actions abilities later if i see everyting looks good)---

		$abilities['shortpixel/get-stats'] = [
			'title'               => __( 'Get Optimization Stats', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns image optimization statistics: totals, compression averages, and images remaining to optimize', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetStatsAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'args'                => [],
			'meta'                => $meta,
		];

		$abilities['shortpixel/get-quota'] = [
			'title'               => __( 'Get Account Quota', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns ShortPixel account quota: monthly credits, one-time credits, AI credits, and whether quota is exceeded', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetQuotaAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'args'                => [],
			'meta'                => $meta,
		];

		$abilities['shortpixel/get-settings'] = [
			'title'               => __( 'Get Plugin Settings', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns the current ShortPixel plugin settings (whitelisted subset, API key is never exposed)', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetSettingsAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanManage' ],
			'args'                => [],
			'meta'                => $meta,
		];

		$abilities['shortpixel/get-media-status'] = [
			'title'               => __( 'Get Image Optimization Status', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns the optimization status of a single image by ID: compression ratio, bytes saved, backup state', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetMediaStatusAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'args'                => [
				'id' => [
					'type'        => 'integer',
					'description' => __( 'The attachment ID (media library) or custom media ID', 'shortpixel-image-optimiser' ),
					'required'    => true,
				],
				'type' => [
					'type'        => 'string',
					'description' => __( 'Image type: "media" for Media Library or "custom" for Custom Media', 'shortpixel-image-optimiser' ),
					'default'     => 'media',
					'enum'        => [ 'media', 'custom' ],
				],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/get-queue-status'] = [
			'title'               => __( 'Get Queue Status', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns the current state of the optimization queues: items in queue, in process, done, and whether each queue is running', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetQueueStatusAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'args'                => [],
			'meta'                => $meta,
		];

		return $abilities;
	}

	/**
	 * Shared meta for abilities that should be discoverable via REST and
	 * exposed as MCP tools by the WP MCP Adapter
	 *
	 * @return array
	 */
	protected function getDefaultMeta()
	{
		return [
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
		];
	}

	/**
	 * Permission callback for settings-level abilities (read/write settings)
	 *
	 * @return bool
	 */
	public function userCanManage()
	{
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for optimization-level abilities (stats, quota,
	 * optimize, restore). Same capability as the SPIO bulk/media pages
	 *
	 * @return bool
	 */
	public function userCanOptimize()
	{
		return current_user_can( 'edit_others_posts' );
	}

} // class
