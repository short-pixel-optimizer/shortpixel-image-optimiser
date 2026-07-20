<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Query Monitor compatibility shim.
 *
 * QM's AJAX-dispatch output is expensive — it serialises the full
 * request/query/hook profile into every AJAX response. During SPIO's
 * queue-preparation phase (bulk creation and per-tick processing) this
 * pushes memory usage over the ceiling on smaller PHP configs and
 * causes OOM kills mid-optimisation.
 *
 * Fix: when `shortpixel/queue/prepare_items` fires, register a filter
 * that unconditionally returns `false` from `qm/dispatch/ajax`,
 * silencing QM's AJAX output for the rest of the request. QM restores
 * normal behaviour on the next request because the filter isn't
 * persisted.
 *
 * NOTE: `panelEnd()` and the commented-out `qm/output/after`
 * registration are legacy scaffolding from an earlier "add a SPIO
 * panel to QM" experiment that was never finished. Left in place as a
 * marker; safe to delete when someone confirms the panel isn't coming
 * back.
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
class QueryMonitor
{

	/**
	 * Wire hooks immediately. Constructor delegates to `hooks()` so
	 * the wiring is grep-able as a separate method.
	 */
	public function __construct()
	{

      $this->hooks();

		/*	if (false === \wpSPIO()->env()->is_debug)
				return;
        */

	}

	/**
	 * Register the deferred filter attach — we can't add the
	 * `qm/dispatch/ajax` filter directly here because QM hasn't
	 * necessarily loaded when this class instantiates. Instead we
	 * listen for the `shortpixel/queue/prepare_items` action and
	 * attach the filter then.
	 *
	 * @return void
	 */
	public function hooks()
	{
			//add_action('qm/output/after', array($this, 'panelEnd'), 10, 2);

			// Filter QM dispatch because it consumes a lot of resources when preparing and out of memory. Keep it until end of ajax call
      add_action('shortpixel/queue/prepare_items', array($this, 'addDispatchFilter'));


	}

  /**
   * Late-attach `qm/dispatch/ajax` at priority 20 so we run after
   * QM's own default handler. Called from the
   * `shortpixel/queue/prepare_items` action.
   *
   * @return void
   */
  public function addDispatchFilter()
  {
      add_filter('qm/dispatch/ajax', array($this, 'dispatchFilter'), 20);
  }


  /**
   * Unconditionally veto QM's AJAX-dispatch output.
   *
   * @return false Always false.
   */
  public function dispatchFilter()
  {
     return false;
  }

	/**
	 * Legacy no-op — placeholder for a SPIO panel in QM's UI that was
	 * never wired up. The `qm/output/after` registration in `hooks()`
	 * is commented out too. Kept as scaffolding.
	 *
	 * @param mixed $qmObj      QM output object (unused).
	 * @param mixed $outputters QM outputter list (unused).
	 * @return void
	 */
	public function panelEnd($qmObj, $outputters)
	{

	}

}


$qm = new QueryMonitor();
