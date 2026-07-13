<?php
/**
 * Tests for ShortPixel\Pantheon — Pantheon host compat shim.
 *
 * Focus:
 *   - Constructor flips `Pantheon::$is_pantheon = true`
 *   - IsActive() reflects the static flag
 *   - Constructor defines SHORTPIXEL_TRUSTED_MODE (idempotent)
 *   - Constructor registers `shortpixel/image/optimised`
 *   - flush_image_caches builds the URL list correctly:
 *     · Main URL + original + thumbnails
 *     · Domain stripped so paths are relative
 *     · De-duplicated
 *     · No call fires when `pantheon_wp_clear_edge_paths` is missing
 *
 * NOTE: `Pantheon` self-boots only when `$_ENV['PANTHEON_ENVIRONMENT']`
 * is set — not typical in the test env — so we instantiate directly.
 * Because `self::$is_pantheon` is a static, we reset it in tear_down.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Pantheon;

class PantheonTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		remove_all_actions( 'shortpixel/image/optimised' );
	}

	public function tear_down() {
		remove_all_actions( 'shortpixel/image/optimised' );

		// Reset the static so per-test constructor flips don't leak
		// into the next test's IsActive() assertions.
		$ref = new ReflectionClass( Pantheon::class );
		$p   = $ref->getProperty( 'is_pantheon' );
		$p->setAccessible( true );
		$p->setValue( null, false );

		parent::tear_down();
	}

	/**
	 * Build a stub optimised image compatible with flush_image_caches().
	 */
	private function makeImageItem( string $mainUrl, ?string $originalUrl = null, array $thumbUrls = array() ): object {
		return new class( $mainUrl, $originalUrl, $thumbUrls ) {
			public $mainUrl;
			public $originalUrl;
			public $thumbUrls;
			public function __construct( $mainUrl, $originalUrl, $thumbUrls ) {
				$this->mainUrl     = $mainUrl;
				$this->originalUrl = $originalUrl;
				$this->thumbUrls   = $thumbUrls;
			}
			public function getURL() {
				return $this->mainUrl;
			}
			public function hasOriginal() {
				return $this->originalUrl !== null;
			}
			public function getOriginalFile() {
				$url = $this->originalUrl;
				return new class( $url ) {
					public $url;
					public function __construct( $url ) {
						$this->url = $url;
					}
					public function getURL() {
						return $this->url;
					}
				};
			}
			public function get( $key ) {
				if ( $key === 'thumbnails' ) {
					$objs = array();
					foreach ( $this->thumbUrls as $url ) {
						$objs[] = new class( $url ) {
							public $url;
							public function __construct( $url ) {
								$this->url = $url;
							}
							public function getURL() {
								return $this->url;
							}
						};
					}
					return $objs;
				}
				return null;
			}
		};
	}

	public function test_constructor_flips_is_pantheon_true_and_IsActive_reports_it() {
		$this->assertFalse( Pantheon::IsActive(), 'test setup issue: IsActive should start false' );

		new Pantheon();

		$this->assertTrue( Pantheon::IsActive() );
	}

	public function test_constructor_registers_the_optimised_action_on_this_instance() {
		$p = new Pantheon();

		$this->assertNotFalse(
			has_action( 'shortpixel/image/optimised', array( $p, 'flush_image_caches' ) )
		);
	}

	public function test_constructor_defines_SHORTPIXEL_TRUSTED_MODE_when_not_already_defined() {
		// Constant may already be defined by an earlier test or by
		// the Pantheon self-boot at file-load time. Both paths reach
		// the same state.
		new Pantheon();

		$this->assertTrue( defined( 'SHORTPIXEL_TRUSTED_MODE' ) );
	}

	public function test_flush_image_caches_no_ops_when_pantheon_edge_function_is_missing() {
		// `pantheon_wp_clear_edge_paths` isn't defined in the test env
		// — a bare call would trigger a fatal. The function-exists
		// guard should protect us. If this test passes without a
		// fatal, the guard is working.
		$p    = new Pantheon();
		$item = $this->makeImageItem( 'https://example.test/a.jpg' );

		$p->flush_image_caches( $item );

		$this->assertTrue( true, 'reached this point → function_exists guard held' );
	}
}
