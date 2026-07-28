<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Model\Image\ImageModel;

/**
 * Ability: shortpixel/update-settings
 *
 * Updates plugin settings from a strict whitelist with per-key validation.
 * Keys outside the whitelist are rejected and reported back. Sensitive
 * fields (API key, credentials, CDN domain, internal state) can never be
 * written through this ability
 *
 * @package ShortPixel\Controller\Abilities
 */
class UpdateSettingsAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: settings (object with whitelisted keys)
	 * @return array Updated keys, skipped keys with reasons
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		$input = isset( $args['settings'] ) ? $args['settings'] : null;

		if ( is_object( $input ) ) {
			$input = (array) $input;
		}

		if ( ! is_array( $input ) || 0 === count( $input ) ) {
			return [
				'error'   => true,
				'message' => 'Provide a "settings" object with at least one key. Allowed keys: ' . implode( ', ', array_keys( self::getWritableSpec() ) ),
			];
		}

		$spec     = self::getWritableSpec();
		$settings = \wpSPIO()->settings();

		$updated = [];
		$skipped = [];

		foreach ( $input as $key => $value ) {

			if ( ! isset( $spec[ $key ] ) ) {
				$skipped[ $key ] = 'Key is not writable through this ability';
				continue;
			}

			$validated = self::validate( $key, $value, $spec[ $key ] );

			if ( false === $validated['valid'] ) {
				$skipped[ $key ] = $validated['reason'];
				continue;
			}

			// SettingsModel magic __set sanitizes and persists on shutdown
			$settings->$key = $validated['value'];
			$updated[ $key ] = $validated['value'];
		}

		return [
			'error'   => false,
			'updated' => $updated,
			'skipped' => $skipped,
			'message' => sprintf( '%d setting(s) updated, %d skipped', count( $updated ), count( $skipped ) ),
		];
	}

	/**
	 * Validation spec for every writable setting.
	 *
	 * Types: 'boolean', 'integer' (with min/max), 'enum' (with values map
	 * where key = accepted input, value = stored value)
	 *
	 * @return array<string, array>
	 */
	public static function getWritableSpec()
	{
		return [
			'compressionType' => [
				'type'   => 'enum',
				'values' => [
					'lossless' => ImageModel::COMPRESSION_LOSSLESS,
					'lossy'    => ImageModel::COMPRESSION_LOSSY,
					'glossy'   => ImageModel::COMPRESSION_GLOSSY,
				],
			],
			'processThumbnails'   => [ 'type' => 'boolean' ],
			'backupImages'        => [ 'type' => 'boolean' ],
			'useSmartcrop'        => [ 'type' => 'boolean' ],
			'createWebp'          => [ 'type' => 'boolean' ],
			'createAvif'          => [ 'type' => 'boolean' ],
			'optimizePdfs'        => [ 'type' => 'boolean' ],
			'optimizeRetina'      => [ 'type' => 'boolean' ],
			'optimizeUnlisted'    => [ 'type' => 'boolean' ],
			'CMYKtoRGBconversion' => [ 'type' => 'boolean' ],
			'autoMediaLibrary'    => [ 'type' => 'boolean' ],
			'useCDN'              => [ 'type' => 'boolean' ],
			'resizeImages'        => [ 'type' => 'boolean' ],
			'resizeWidth'         => [ 'type' => 'integer', 'min' => 0, 'max' => 10000 ],
			'resizeHeight'        => [ 'type' => 'integer', 'min' => 0, 'max' => 10000 ],
			'resizeType'          => [
				'type'   => 'enum',
				'values' => [ 'outer' => 'outer', 'inner' => 'inner' ],
			],
			'png2jpg'             => [ 'type' => 'integer', 'min' => 0, 'max' => 2 ],
			'exif'                => [ 'type' => 'integer', 'min' => 0, 'max' => 1 ],
			'enable_ai'           => [ 'type' => 'boolean' ],
			'autoAI'              => [ 'type' => 'boolean' ],
			'ai_gen_alt'          => [ 'type' => 'boolean' ],
			'ai_gen_caption'      => [ 'type' => 'boolean' ],
			'ai_gen_description'  => [ 'type' => 'boolean' ],
		];
	}

	/**
	 * Validate a single value against its spec entry
	 *
	 * @param string $key   Setting name (for error messages)
	 * @param mixed  $value Raw input value
	 * @param array  $spec  Spec entry (type + constraints)
	 * @return array{valid: bool, value?: mixed, reason?: string}
	 */
	private static function validate( $key, $value, $spec )
	{
		switch ( $spec['type'] ) {

			case 'boolean':
				if ( ! is_bool( $value ) && ! in_array( $value, [ 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					return [ 'valid' => false, 'reason' => 'Expected a boolean value' ];
				}
				$bool = is_bool( $value ) ? $value : in_array( $value, [ 1, '1', 'true' ], true );
				return [ 'valid' => true, 'value' => $bool ];

			case 'integer':
				if ( ! is_numeric( $value ) ) {
					return [ 'valid' => false, 'reason' => 'Expected an integer value' ];
				}
				$int = (int) $value;
				if ( isset( $spec['min'] ) && $int < $spec['min'] ) {
					return [ 'valid' => false, 'reason' => sprintf( 'Value must be at least %d', $spec['min'] ) ];
				}
				if ( isset( $spec['max'] ) && $int > $spec['max'] ) {
					return [ 'valid' => false, 'reason' => sprintf( 'Value must be at most %d', $spec['max'] ) ];
				}
				return [ 'valid' => true, 'value' => $int ];

			case 'enum':
				$lookup = is_string( $value ) ? strtolower( $value ) : $value;
				if ( ! isset( $spec['values'][ $lookup ] ) ) {
					return [ 'valid' => false, 'reason' => 'Allowed values: ' . implode( ', ', array_keys( $spec['values'] ) ) ];
				}
				return [ 'valid' => true, 'value' => $spec['values'][ $lookup ] ];
		}

		return [ 'valid' => false, 'reason' => 'Unknown validation type' ];
	}
}
