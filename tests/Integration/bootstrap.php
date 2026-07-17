<?php
/**
 * PHPUnit bootstrap for the INTEGRATION suite.
 *
 * Reuses the unit-test bootstrap (WP test lib, plugin load, settings
 * redirect guard, custom-table creation, PHP 8.5 deprecation handler)
 * and adds the integration-only pieces on top: the ShortPixel API mock
 * and the shared integration base class.
 *
 * The integration suite runs the REAL optimize/restore pipeline against
 * the WordPress test install; only outbound HTTP to api.shortpixel.com
 * is intercepted (pre_http_request) — see MockShortPixelApi.
 *
 * @package Shortpixel_Image_Optimiser
 */

require dirname( __DIR__ ) . '/bootstrap.php';

require_once __DIR__ . '/Helpers/MockShortPixelApi.php';
require_once __DIR__ . '/Helpers/SPIO_IntegrationTestCase.php';
