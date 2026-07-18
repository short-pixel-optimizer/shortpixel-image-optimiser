<?php
/**
 * Base class for SPIO integration tests.
 *
 * All shared machinery (MockShortPixelApi lifecycle, verified-key settings
 * baseline, fixture upload helpers, queue-driving loops, singleton/table
 * hygiene) lives in the SPIO_IntegrationHelpers trait so it can be shared
 * with SPIO_AjaxTestCase (WP_Ajax_UnitTestCase-based). This class only wires
 * the trait into WP_UnitTestCase's set_up()/tear_down().
 *
 * @package Shortpixel_Image_Optimiser
 */

abstract class SPIO_IntegrationTestCase extends WP_UnitTestCase {

	use SPIO_IntegrationHelpers;

	public function set_up() {
		parent::set_up();
		$this->spioSetUpBaseline();
	}

	public function tear_down() {
		$this->spioTearDownBaseline();
		parent::tear_down();
	}
}
