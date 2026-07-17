<?php
/**
 * Tests for ShortPixel\Controller\ImageEditorController.
 *
 * Scope: singleton contract and localizeScript() pure-data shaping.
 *
 * Out of scope / why:
 * - getImageForEditor() calls wpSPIO()->filesystem()->getImage() which requires
 *   a real attachment in the media library and a physical file on disk — an
 *   integration concern, not a unit boundary.
 * - saveImageFile() calls wpSPIO()->filesystem()->getImage() and then
 *   onDelete() on the resulting model, again requiring real attachment state.
 * - Both hook callbacks (getImageForEditor, saveImageFile) are registered by
 *   the caller, not by this class, so there is no add_filter/add_action to
 *   assert from here.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\ImageEditorController;

class ImageEditorControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
	}

	public function tear_down() {
		$this->resetSingleton();
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( ImageEditorController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/*
	 * getInstance — singleton contract
	 */

	public function test_getInstance_returns_image_editor_controller() {
		$ctrl = ImageEditorController::getInstance();
		$this->assertInstanceOf( ImageEditorController::class, $ctrl );
	}

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = ImageEditorController::getInstance();
		$b = ImageEditorController::getInstance();
		$this->assertSame( $a, $b );
	}

	/*
	 * localizeScript — pure data shaping, no side-effects
	 */

	public function test_localizeScript_returns_array() {
		$local = ImageEditorController::localizeScript();
		$this->assertIsArray( $local );
	}

	public function test_localizeScript_contains_optimized_text_key() {
		$local = ImageEditorController::localizeScript();
		$this->assertArrayHasKey( 'optimized_text', $local );
	}

	public function test_localizeScript_optimized_text_is_non_empty_string() {
		$local = ImageEditorController::localizeScript();
		$this->assertIsString( $local['optimized_text'] );
		$this->assertNotEmpty( $local['optimized_text'] );
	}

	public function test_localizeScript_contains_restore_link_key() {
		$local = ImageEditorController::localizeScript();
		$this->assertArrayHasKey( 'restore_link', $local );
	}

	public function test_localizeScript_restore_link_contains_post_id_placeholder() {
		$local = ImageEditorController::localizeScript();
		$this->assertStringContainsString( '#post_id#', $local['restore_link'] );
	}

	public function test_localizeScript_contains_restore_link_text_key() {
		$local = ImageEditorController::localizeScript();
		$this->assertArrayHasKey( 'restore_link_text', $local );
		$this->assertIsString( $local['restore_link_text'] );
	}

	public function test_localizeScript_contains_restore_link_text_unrestorable_key() {
		$local = ImageEditorController::localizeScript();
		$this->assertArrayHasKey( 'restore_link_text_unrestorable', $local );
		$this->assertIsString( $local['restore_link_text_unrestorable'] );
	}

	public function test_localizeScript_restore_link_is_javascript_uri() {
		$local = ImageEditorController::localizeScript();
		$this->assertStringStartsWith( 'javascript:', $local['restore_link'] );
	}

	public function test_localizeScript_contains_exactly_four_keys() {
		$local = ImageEditorController::localizeScript();
		$expected_keys = array( 'optimized_text', 'restore_link', 'restore_link_text', 'restore_link_text_unrestorable' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $local );
		}
		$this->assertCount( 4, $local );
	}
}
