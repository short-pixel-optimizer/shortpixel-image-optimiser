<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

class QueryMonitor
{

	public function __construct()
	{

      $this->hooks();

		/*	if (false === \wpSPIO()->env()->is_debug)
				return;
        */

	}

	public function hooks()
	{
			//add_action('qm/output/after', array($this, 'panelEnd'), 10, 2);

			// Filter QM dispatch because it consumes a lot of resources when preparing and out of memory. Keep it until end of ajax call
      add_action('shortpixel/queue/prepare_items', array($this, 'addDispatchFilter'));


	}

  public function addDispatchFilter()
  {
      add_filter('qm/dispatch/ajax', array($this, 'dispatchFilter'), 20);
  }


  public function dispatchFilter()
  {
     return false;
  }

	public function panelEnd($qmObj, $outputters)
	{

	}

}


$qm = new QueryMonitor();
