<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Helper\UiHelper;

/**
 * Ability: shortpixel/get-settings
 *
 * Returns the current plugin settings (whitelisted subset only).
 * The API key is never exposed — only a boolean indicating whether
 * the key is verified
 *
 * @package ShortPixel\Controller\Abilities
 */
class GetSettingsAbility
{
	/**
	 * Settings keys safe to expose via the ability. Sensitive fields
	 * (API key, auth credentials, internal counters) are excluded
	 */
	const WHITELISTED_KEYS = [
		'compressionType',
		'resizeWidth',
		'resizeHeight',
		'processThumbnails',
		'useSmartcrop',
		'backupImages',
		'resizeImages',
		'resizeType',
		'png2jpg',
		'CMYKtoRGBconversion',
		'createWebp',
		'createAvif',
		'deliverWebp',
		'optimizeRetina',
		'optimizeUnlisted',
		'optimizePdfs',
		'excludePatterns',
		'autoMediaLibrary',
		'excludeSizes',
		'useCDN',
		'CDNDomain',
		'exif',
		'enable_ai',
		'autoAI',
		'autoAIBulk',
		'aiPreserve',
		'ai_gen_alt',
		'ai_gen_caption',
		'ai_gen_description',
		'ai_gen_post_title',
		'ai_gen_filename',
		'ai_language',
	];

	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input arguments (none required for this ability)
	 * @return array Settings data
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		$settings = \wpSPIO()->settings();
		$keyController = ApiKeyController::getInstance();

		$result = [
			'api_key_verified' => $keyController->keyIsVerified(),
		];

		foreach ( self::WHITELISTED_KEYS as $key ) {
			$value = $settings->$key;

			// Normalize compression type to human-readable string
			if ( $key === 'compressionType' ) {
				$result['compression_type_label'] = self::compressionLabel( $value );
			}

			$result[ $key ] = $value;
		}

		return $result;
	}

	/**
	 * Convert compression type integer to readable label
	 *
	 * @param int $type Compression type (0=lossless, 1=lossy, 2=glossy)
	 * @return string Human-readable label
	 */
	private static function compressionLabel( $type )
	{
		$map = [
			0 => 'lossless',
			1 => 'lossy',
			2 => 'glossy',
		];
		return $map[ (int) $type ] ?? 'unknown';
	}
}
