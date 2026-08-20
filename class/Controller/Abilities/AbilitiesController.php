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
 * the `wp_abilities_api_*` hooks never fire, so this whole controller is a
 * silent no-op. The `function_exists` guards inside the callbacks are a
 * second line of defense only.
 *
 * Registration flow:
 *   1. `wp_abilities_api_categories_init` → registers the `shortpixel` category
 *   2. `wp_abilities_api_init` → registers every ability from getAbilities()
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
		add_action( 'wp_abilities_api_categories_init', [ $this, 'registerCategories' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'registerAbilities' ] );
	}

	/**
	 * Register the shared ability category for all SPIO abilities.
	 * Runs on `wp_abilities_api_categories_init`
	 *
	 * @return void
	 */
	public function registerCategories()
	{
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		\wp_register_ability_category( self::ABILITY_CATEGORY, [
			'label'       => __( 'ShortPixel Image Optimizer', 'shortpixel-image-optimiser' ),
			'description' => __( 'Abilities for ShortPixel image optimization, quota, settings, and queue control', 'shortpixel-image-optimiser' ),
		] );
	}

	/**
	 * Register all SPIO abilities. Runs on `wp_abilities_api_init`.
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

			if ( null === $result ) {
				Log::addWarn( 'Ability registration failed: ' . $name );
			}
		}
	}

	/**
	 * Whether the WordPress Abilities API is available on this site
	 *
	 * @return bool
	 */
	public function isApiAvailable()
	{
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * Whether a named ability is currently registered with the Abilities API
	 *
	 * @param string $name Ability name (e.g. shortpixel/get-stats)
	 * @return bool
	 */
	public function isAbilityRegistered( $name )
	{
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return false;
		}

		$ability = \wp_get_ability( $name );
		return null !== $ability;
	}

	/**
	 * Return the ability definitions to register, keyed by ability name.
	 *
	 * Each entry is the args array passed to `wp_register_ability()`.
	 * Public so the debug settings page can list the catalog
	 *
	 * @return array<string, array>
	 */
	public function getAbilities()
	{
		$meta = $this->getDefaultMeta();

		$abilities = [];

		// --- Read-only abilities ---

		$abilities['shortpixel/get-stats'] = [
			'label'               => __( 'Get Optimization Stats', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns image optimization statistics: totals, compression averages, and images remaining to optimize', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetStatsAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'meta'                => $meta,
		];

		$abilities['shortpixel/get-quota'] = [
			'label'               => __( 'Get Account Quota', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns ShortPixel account quota: monthly credits, one-time credits, AI credits, and whether quota is exceeded', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetQuotaAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'meta'                => $meta,
		];

		$abilities['shortpixel/get-settings'] = [
			'label'               => __( 'Get Plugin Settings', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns the current ShortPixel plugin settings (whitelisted subset, API key is never exposed)', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetSettingsAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanManage' ],
			'meta'                => $meta,
		];

		$abilities['shortpixel/get-media-status'] = [
			'label'               => __( 'Get Image Optimization Status', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns the optimization status of a single image by ID: compression ratio, bytes saved, backup state', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetMediaStatusAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'The attachment ID (media library) or custom media ID',
					],
					'type' => [
						'type'        => 'string',
						'description' => 'Image type: media or custom',
						'default'     => 'media',
						'enum'        => [ 'media', 'custom' ],
					],
				],
				'required' => [ 'id' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/get-queue-status'] = [
			'label'               => __( 'Get Queue Status', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns the current state of the optimization queues: items in queue, in process, done, and whether each queue is running', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetQueueStatusAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'bulk' => [
						'type'        => 'boolean',
						'description' => 'When true, report the bulk queues instead of the single-item queues',
						'default'     => false,
					],
				],
			],
			'meta'                => $meta,
		];

		// --- Action abilities ---

		$abilities['shortpixel/optimize-media'] = [
			'label'               => __( 'Optimize Image', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Enqueues an image for optimization and processes the queue within a time budget. Optimization is asynchronous: if the job does not finish in one call, use run-queue to continue. Consumes ShortPixel credits', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ OptimizeMediaAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'The attachment ID (media library) or custom media ID',
					],
					'type' => [
						'type'        => 'string',
						'description' => 'Image type: media or custom',
						'default'     => 'media',
						'enum'        => [ 'media', 'custom' ],
					],
					'compression' => [
						'type'        => 'string',
						'description' => 'Compression level. Omit to use the plugin setting',
						'enum'        => [ 'lossy', 'glossy', 'lossless' ],
					],
					'smartcrop' => [
						'type'        => 'boolean',
						'description' => 'Force smart cropping on or off. Omit to use the plugin setting',
					],
					'process' => [
						'type'        => 'boolean',
						'description' => 'Process the queue immediately after enqueueing. Set false to only enqueue',
						'default'     => true,
					],
				],
				'required' => [ 'id' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/run-queue'] = [
			'label'               => __( 'Run Optimization Queue', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Advances the optimization queues by running processing ticks within a time budget. Call repeatedly until the queues report empty. Pass bulk=true when continuing a bulk restore (auto-detected when a bulk is already running). Returns the queue status after the run', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ RunQueueAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'ticks' => [
						'type'        => 'integer',
						'description' => 'Maximum number of processing ticks to run (max 20)',
						'default'     => 10,
					],
					'bulk' => [
						'type'        => 'boolean',
						'description' => 'Drive the bulk queues instead of the single-item queues. When omitted, auto-detects an active bulk or custom operation',
					],
				],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/restore-media'] = [
			'label'               => __( 'Restore Image from Backup', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Restores an optimized image to its original state from the ShortPixel backup. Does not consume credits. Fails when no backup exists', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ RestoreMediaAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'The attachment ID (media library) or custom media ID',
					],
					'type' => [
						'type'        => 'string',
						'description' => 'Image type: media or custom',
						'default'     => 'media',
						'enum'        => [ 'media', 'custom' ],
					],
				],
				'required' => [ 'id' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/bulk-restore'] = [
			'label'               => __( 'Bulk Restore Images', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Starts a bulk restore of all optimized images from backup (de-optimize). Destructive and non-reversible. Requires confirm=true. Does not consume credits. Asynchronous: call run-queue until the queues report empty', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ BulkRestoreAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'confirm' => [
						'type'        => 'boolean',
						'description' => 'Must be true to acknowledge that bulk restore is destructive and non-reversible',
					],
					'queues' => [
						'type'        => 'array',
						'description' => 'Which queues to restore. Defaults to both media and custom',
						'items'       => [
							'type' => 'string',
							'enum' => [ 'media', 'custom' ],
						],
						'default'     => [ 'media', 'custom' ],
					],
					'process' => [
						'type'        => 'boolean',
						'description' => 'Advance prepare/process within this request. Set false to only create the bulk',
						'default'     => true,
					],
				],
				'required' => [ 'confirm' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/bulk-optimize'] = [
			'label'               => __( 'Bulk Optimize Images', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Starts a bulk optimization of all unoptimized images. Requires confirm=true. Consumes ShortPixel credits. Asynchronous: call run-queue until the queues report empty. Statistics cache is reset when the bulk finishes', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ BulkOptimizeAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'confirm' => [
						'type'        => 'boolean',
						'description' => 'Must be true to acknowledge that bulk optimize consumes ShortPixel credits',
					],
					'queues' => [
						'type'        => 'array',
						'description' => 'Which queues to optimize. Defaults to both media and custom',
						'items'       => [
							'type' => 'string',
							'enum' => [ 'media', 'custom' ],
						],
						'default'     => [ 'media', 'custom' ],
					],
					'do_ai' => [
						'type'        => 'boolean',
						'description' => 'Run AI generation during bulk. Omit to use the autoAIBulk plugin setting',
					],
					'process' => [
						'type'        => 'boolean',
						'description' => 'Advance prepare/process within this request. Set false to only create the bulk',
						'default'     => true,
					],
				],
				'required' => [ 'confirm' ],
			],
			'meta' => $meta,
		];

		// --- AI Image SEO abilities (Media Library only) ---

		$abilities['shortpixel/get-ai-seo-status'] = [
			'label'               => __( 'Get AI Image SEO Status', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Returns AI Image SEO status for a Media Library attachment: generation state, processability, and original/generated/current values for alt, caption, description, title and filename. Custom Media is not supported', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GetAiSeoStatusAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'The Media Library attachment ID',
					],
				],
				'required' => [ 'id' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/generate-ai-seo'] = [
			'label'               => __( 'Generate AI Image SEO', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Enqueues AI SEO generation (alt, caption, description, title, optional filename) for a Media Library image and processes the queue within a time budget. Asynchronous: if incomplete, call run-queue. Consumes AI credits. Custom Media is not supported', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ GenerateAiSeoAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'The Media Library attachment ID',
					],
					'process' => [
						'type'        => 'boolean',
						'description' => 'Process the queue immediately after enqueueing. Set false to only enqueue',
						'default'     => true,
					],
					'preview_only' => [
						'type'        => 'boolean',
						'description' => 'Generate a preview without persisting results to the attachment',
						'default'     => false,
					],
					'gen_alt' => [
						'type'        => 'boolean',
						'description' => 'Override: generate ALT text. Omit to use the plugin setting',
					],
					'gen_caption' => [
						'type'        => 'boolean',
						'description' => 'Override: generate caption. Omit to use the plugin setting',
					],
					'gen_description' => [
						'type'        => 'boolean',
						'description' => 'Override: generate description. Omit to use the plugin setting',
					],
					'gen_post_title' => [
						'type'        => 'boolean',
						'description' => 'Override: generate image title. Omit to use the plugin setting',
					],
					'gen_filename' => [
						'type'        => 'boolean',
						'description' => 'Override: rename filename via AI. Omit to use the plugin setting',
					],
				],
				'required' => [ 'id' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/undo-ai-seo'] = [
			'label'               => __( 'Undo AI Image SEO', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Reverts AI-generated SEO metadata (alt, caption, description, title) to the pre-generation values. Does not consume credits. Filename renames are not reversed. Custom Media is not supported', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ UndoAiSeoAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id' => [
						'type'        => 'integer',
						'description' => 'The Media Library attachment ID',
					],
				],
				'required' => [ 'id' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/bulk-generate-ai-seo'] = [
			'label'               => __( 'Bulk Generate AI Image SEO', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Starts a media-only bulk that generates AI SEO for processable attachments (AI only, no image optimize). Requires confirm=true. Consumes AI credits. Asynchronous: call run-queue with bulk=true until queues empty', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ BulkGenerateAiSeoAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'confirm' => [
						'type'        => 'boolean',
						'description' => 'Must be true to acknowledge that bulk AI SEO generation consumes AI credits',
					],
					'process' => [
						'type'        => 'boolean',
						'description' => 'Advance prepare/process within this request. Set false to only create the bulk',
						'default'     => true,
					],
				],
				'required' => [ 'confirm' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/bulk-undo-ai-seo'] = [
			'label'               => __( 'Bulk Undo AI Image SEO', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Starts a media-only bulk that reverts AI SEO metadata for all attachments with generated data. Requires confirm=true. Does not consume credits. Filename renames are not reversed. Asynchronous: call run-queue with bulk=true until queues empty', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ BulkUndoAiSeoAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanOptimize' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'confirm' => [
						'type'        => 'boolean',
						'description' => 'Must be true to acknowledge that bulk undo reverts AI SEO site-wide',
					],
					'process' => [
						'type'        => 'boolean',
						'description' => 'Advance prepare/process within this request. Set false to only create the bulk',
						'default'     => true,
					],
				],
				'required' => [ 'confirm' ],
			],
			'meta' => $meta,
		];

		$abilities['shortpixel/update-settings'] = [
			'label'               => __( 'Update Plugin Settings', 'shortpixel-image-optimiser' ),
			'description'         => __( 'Updates ShortPixel settings from a strict whitelist with validation. Sensitive fields (API key, credentials, CDN domain) can never be written. Returns updated and skipped keys', 'shortpixel-image-optimiser' ),
			'category'            => self::ABILITY_CATEGORY,
			'execute_callback'    => [ UpdateSettingsAbility::class, 'execute' ],
			'permission_callback' => [ $this, 'userCanManage' ],
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'settings' => [
						'type'        => 'object',
						'description' => 'Object with setting keys to update. Allowed: compressionType (lossy/glossy/lossless), processThumbnails, backupImages, useSmartcrop, createWebp, createAvif, optimizePdfs, optimizeRetina, optimizeUnlisted, CMYKtoRGBconversion, autoMediaLibrary, useCDN, resizeImages, resizeWidth, resizeHeight, resizeType (outer/inner), png2jpg (0-2), exif (0-1), enable_ai, autoAI, autoAIBulk, aiPreserve, ai_gen_alt, ai_gen_caption, ai_gen_description, ai_gen_post_title, ai_gen_filename',
					],
				],
				'required' => [ 'settings' ],
			],
			'meta' => $meta,
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
