<?php
/**
 * Integration tests: API conversion formats (Wave 2) — heic / tiff / bmp.
 *
 * These formats can't be optimized natively; ApiConverter reroutes the
 * queue item ('optimize' → 'convert_api' with 'optimize' as next_action),
 * the API returns converted JPEG bytes on the SAME reducer endpoint, and
 * the plugin replaces the attachment with the .jpg before running the
 * normal optimization round-trip on it.
 *
 * The mock serves tests/fixtures/optimized/<basename> for main files —
 * for these fixtures those are real converted JPEG bytes.
 *
 * @package Shortpixel_Image_Optimiser
 */

class ApiConverterFlowTest extends SPIO_IntegrationTestCase {

	/**
	 * @dataProvider convertableFixtures
	 */
	public function test_convertable_format_is_replaced_by_optimized_jpg( string $fixture ) {
		$id = $this->uploadFixture( $fixture );

		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ),
			"$fixture must be converted to a .jpg attachment."
		);
		$this->assertFileExists( $mainPath );

		$info = getimagesize( $mainPath );
		$this->assertSame( IMAGETYPE_JPEG, $info[2], 'The converted main file must contain JPEG bytes.' );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertTrue(
			$image->isOptimized(),
			"$fixture must be optimized after the convert + optimize round-trips."
		);
	}

	public function convertableFixtures(): array {
		return array(
			'heic' => array( 'fixture-large.heic' ),
			'tiff' => array( 'fixture-medium.tiff' ),
			'bmp'  => array( 'fixture-medium.bmp' ),
		);
	}

	public function test_conversion_sends_convert_api_then_optimize_requests() {
		$id = $this->uploadFixture( 'fixture-large.heic' );

		$this->optimizeAttachment( $id );

		$reducerBodies = array();
		foreach ( $this->api->requests as $req ) {
			if ( false !== strpos( $req['url'], 'reducer' ) && isset( $req['request']['urllist'] ) ) {
				$reducerBodies[] = $req['request'];
			}
		}
		$this->assertGreaterThanOrEqual( 2, count( $reducerBodies ), 'Conversion needs (at least) a convert round-trip and an optimize round-trip.' );

		$first = wp_json_encode( $reducerBodies[0] );
		$this->assertStringContainsString( '.heic', $first, 'The first reducer call must target the heic source.' );

		$last = wp_json_encode( end( $reducerBodies ) );
		$this->assertStringContainsString( '.jpg', $last, 'The follow-up optimize call must target the converted jpg.' );
	}
}
