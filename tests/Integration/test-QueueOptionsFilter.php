<?php
/**
 * Integration tests: queue options filters reach ShortQ (regression for bug #35).
 *
 * Bug #35 (fixed in aea6e783): MediaLibraryQueue::__construct applied the
 * 'shortpixel/medialibraryqueue/options' filter into $this->options but then
 * passed the UNFILTERED $options array to $this->q->setOptions(). The filter
 * therefore never reached the inner ShortQ for the non-bulk (mediaSingle /
 * auto-upload) queues — numitems / process_timeout stayed at their defaults
 * no matter what a site owner hooked in (the documented way to throttle
 * optimization request bursts, e.g. behind a Cloudflare rate limiter).
 * CustomQueue always passed the filtered array; it is covered here as well
 * so both filters stay wired.
 *
 * Sentinel: with the bug present, the inner ShortQ reports the default
 * numitems (5) instead of the filtered value and the assertSame fails.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Queue\CustomQueue;
use ShortPixel\Controller\Queue\MediaLibraryQueue;

class QueueOptionsFilterTest extends SPIO_IntegrationTestCase {

	/**
	 * Returns the protected inner ShortQ queue object of a Queue instance,
	 * walking up the class hierarchy to find the property.
	 *
	 * @param object $queue MediaLibraryQueue or CustomQueue instance.
	 * @return object Inner \ShortPixel\ShortQ\Queue\WPQ instance.
	 */
	private function innerQ( object $queue ) {
		$ref = new ReflectionClass( $queue );
		while ( ! $ref->hasProperty( 'q' ) && $ref->getParentClass() ) {
			$ref = $ref->getParentClass();
		}
		$prop = $ref->getProperty( 'q' );
		$prop->setAccessible( true );
		return $prop->getValue( $queue );
	}

	/**
	 * The 'shortpixel/medialibraryqueue/options' filter must reach the inner
	 * ShortQ instance on construction — for EVERY MediaLibraryQueue, not just
	 * bulk queues (which additionally persist options via createNewBulk).
	 *
	 * Regression for bug #35.
	 */
	public function test_medialibraryqueue_options_filter_reaches_shortq() {
		$callback = function ( $options ) {
			$options['numitems']        = 2;
			$options['process_timeout'] = 44444;
			return $options;
		};
		add_filter( 'shortpixel/medialibraryqueue/options', $callback );

		$queue = new MediaLibraryQueue( 'Media' );
		$q     = $this->innerQ( $queue );

		remove_filter( 'shortpixel/medialibraryqueue/options', $callback );

		$this->assertSame(
			2,
			$q->getOption( 'numitems' ),
			'Filtered numitems must reach the inner ShortQ (bug #35: unfiltered $options was passed to setOptions).'
		);
		$this->assertSame(
			44444,
			$q->getOption( 'process_timeout' ),
			'Filtered process_timeout must reach the inner ShortQ (bug #35).'
		);
	}

	/**
	 * The 'shortpixel/customqueue/options' filter must reach the inner ShortQ
	 * instance on construction (this path was always correct; kept wired).
	 */
	public function test_customqueue_options_filter_reaches_shortq() {
		$callback = function ( $options ) {
			$options['numitems'] = 3;
			return $options;
		};
		add_filter( 'shortpixel/customqueue/options', $callback );

		$queue = new CustomQueue( 'Custom' );
		$q     = $this->innerQ( $queue );

		remove_filter( 'shortpixel/customqueue/options', $callback );

		$this->assertSame(
			3,
			$q->getOption( 'numitems' ),
			'Filtered numitems must reach the inner ShortQ for the custom queue.'
		);
	}
}
