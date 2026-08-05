<?php
/**
 * Cross-plugin compatibility: WooCommerce (Wave 3).
 *
 * Runs with the REAL WooCommerce plugin active (bin/test.sh --compat
 * downloads + activates it). Covers class/external/Woocommerce.php:
 *
 *   - WC and SPIO load side by side without fatals.
 *   - SPIO's three WC hooks are wired (they are gated behind
 *     plugin_active('woocommerce'), so they only exist in this run).
 *   - The regeneration signal state machine: WC's pre-regenerate filter
 *     arms the signal; the next `intermediate_image_sizes_advanced`
 *     fire drops SPIO's optimized state for the regenerated thumbnails
 *     (they're new files on disk — the old state is stale).
 *   - Without the signal (a normal upload), thumbnail state is kept.
 *   - The Tools → Regenerate panel gets the SPIO auto-optimize warning.
 *
 * @package Shortpixel_Image_Optimiser
 */

class CompatWooCommerceTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded — run via bin/test.sh --compat.' );
		}

		parent::set_up();

		// The signal is a static flag on SPIO's Woocommerce shim; a
		// previous test may have left it armed.
		$reflection = new ReflectionProperty( \ShortPixel\Woocommerce::class, 'SIGNAL' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, false );
	}

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/** Upload + optimize a fixture with thumbnails, return [id, sizes]. */
	private function optimizedAttachmentWithThumbs(): array {
		\wpSPIO()->settings()->processThumbnails = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'], 'Fixture upload must generate thumbnail sizes.' );

		return array( $id, $metadata['sizes'] );
	}

	// -------------------------------------------------------------------
	// Coexistence + wiring
	// -------------------------------------------------------------------

	public function test_woocommerce_loads_alongside_spio() {
		$this->assertTrue( class_exists( 'WooCommerce' ), 'WooCommerce main class must exist.' );
		$this->assertGreaterThan( 0, did_action( 'woocommerce_loaded' ), 'WooCommerce must have finished loading.' );
		$this->assertTrue( \wpSPIO()->env()->plugin_active( 'woocommerce' ), "SPIO's environment must detect WooCommerce as active." );
	}

	public function test_spio_woocommerce_hooks_are_wired() {
		// These are only registered when plugin_active('woocommerce') was
		// true on plugins_loaded — i.e. exactly in this compat run.
		$this->assertNotFalse( has_filter( 'woocommerce_regenerate_images_intermediate_image_sizes' ), 'The WC pre-regenerate signal hook must be wired.' );
		$this->assertNotFalse( has_filter( 'woocommerce_debug_tools' ), 'The WC debug-tools warning hook must be wired.' );
		$this->assertSame( 99, has_filter( 'intermediate_image_sizes_advanced', array( $this->spioWcInstance(), 'handleCreateImages' ) ), 'handleCreateImages must be wired late (priority 99).' );
	}

	/** The Woocommerce shim instance registered on the WC signal filter. */
	private function spioWcInstance() {
		global $wp_filter;
		foreach ( $wp_filter['woocommerce_regenerate_images_intermediate_image_sizes']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \ShortPixel\Woocommerce ) {
					return $callback['function'][0];
				}
			}
		}
		$this->fail( 'No ShortPixel\Woocommerce instance found on the WC signal filter.' );
	}

	// -------------------------------------------------------------------
	// Regeneration signal state machine
	// -------------------------------------------------------------------

	public function test_wc_regeneration_drops_optimized_thumbnail_state() {
		list( $id, $sizes ) = $this->optimizedAttachmentWithThumbs();

		$image     = $this->freshImageModel( $id );
		$sizeNames = array_keys( $sizes );
		$firstSize = $sizeNames[0];
		$this->assertTrue( $image->getThumbNail( $firstSize ) !== false && $image->getThumbNail( $firstSize )->isOptimized(), "Thumbnail '$firstSize' must be optimized before regeneration." );

		// WC fires this just before regenerating product images…
		apply_filters( 'woocommerce_regenerate_images_intermediate_image_sizes', array() );
		// …then WP creates the new files, firing this for the attachment.
		apply_filters( 'intermediate_image_sizes_advanced', $sizes, wp_get_attachment_metadata( $id ), $id );

		$image = $this->freshImageModel( $id );
		foreach ( $sizeNames as $sizeName ) {
			$thumb = $image->getThumbNail( $sizeName );
			if ( false === $thumb ) {
				continue;
			}
			$this->assertFalse( $thumb->isOptimized(), "Thumbnail '$sizeName' must lose its optimized state after a WC regeneration (the file on disk is new)." );
		}
	}

	public function test_thumbnail_creation_without_signal_keeps_optimized_state() {
		list( $id, $sizes ) = $this->optimizedAttachmentWithThumbs();

		// Normal upload path: intermediate_image_sizes_advanced fires with
		// NO preceding WC signal — SPIO must not touch existing state.
		apply_filters( 'intermediate_image_sizes_advanced', $sizes, wp_get_attachment_metadata( $id ), $id );

		$image     = $this->freshImageModel( $id );
		$firstSize = array_keys( $sizes )[0];
		$thumb     = $image->getThumbNail( $firstSize );
		$this->assertNotFalse( $thumb );
		$this->assertTrue( $thumb->isOptimized(), 'Without the WC regeneration signal, optimized thumbnail state must be kept.' );
	}

	// -------------------------------------------------------------------
	// Tools panel warning
	// -------------------------------------------------------------------

	public function test_wc_debug_tools_gets_spio_warning_when_autoprocess_is_on() {
		\wpSPIO()->env()->is_autoprocess = true;

		$tools = apply_filters(
			'woocommerce_debug_tools',
			array( 'regenerate_thumbnails' => array( 'desc' => 'Base description.' ) )
		);

		$this->assertStringContainsString( 'ShortPixel Image Optimizer Note:', $tools['regenerate_thumbnails']['desc'], 'SPIO must extend the WC regenerate-thumbnails tool description when auto-optimize is on.' );

		\wpSPIO()->env()->is_autoprocess = false;
		$tools = apply_filters(
			'woocommerce_debug_tools',
			array( 'regenerate_thumbnails' => array( 'desc' => 'Base description.' ) )
		);
		$this->assertSame( 'Base description.', $tools['regenerate_thumbnails']['desc'], 'Without auto-optimize the description must stay untouched.' );
	}
}
