<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

class QueryMonitor
{

	public function __construct()
	{

			if (false === \wpSPIO()->env()->is_debug)
				return;

			$this->hooks();
	}

	public function hooks()
	{
			add_action('qm/output/after', array($this, 'panelEnd'), 10, 2);
	}

	public function panelEnd($qmObj, $outputters)
	{

	}

}


$qm = new QueryMonitor();
