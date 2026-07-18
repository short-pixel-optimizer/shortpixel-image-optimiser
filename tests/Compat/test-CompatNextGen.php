<?php
/**
 * Cross-plugin compatibility: NextGen Gallery (Wave 3).
 *
 * Runs with the REAL NextGen Gallery plugin active (bin/test.sh --compat
 * downloads + activates it; the activation hook creates ngg_gallery).
 * Covers class/external/nextgen/nextGenController.php:
 *
 *   - NGG and SPIO load side by side; SPIO detects NGG via NGG_PLUGIN.
 *   - Hook gating: the presence hooks (ngg_delete_image, folder/load)
 *     are wired, but the optimize hooks (ngg_added_new_image, …) stay
 *     OFF until the includeNextGen setting is on at plugins_loaded.
 *   - addNextGenGalleriesToCustom() registers a real ngg_gallery row
 *     as a Custom Media folder with DIRECTORY_STATUS_NEXTGEN.
 *   - the folder/load hook auto-upgrades any folder saved at a
 *     gallery path to NextGen status (match against ngg_gallery).
 *   - A NextGen upload piped through the Custom Media pipeline
 *     (the handleImageUpload body) optimizes end-to-end.
 *
 * The gallery row lives inside the per-test transaction (rolled back);
 * only the on-disk gallery dir needs explicit cleanup.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\NextGenController;
use ShortPixel\Controller\OtherMediaController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Model\File\DirectoryOtherMediaModel;

class CompatNextGenTest extends SPIO_IntegrationTestCase {

	private const GALLERY_REL = 'wp-content/gallery/spio-ngg-test/';

	/** @var string Absolute path of the on-disk gallery dir. */
	private $galleryDir;

	public function set_up() {
		if ( ! defined( 'NGG_PLUGIN' ) ) {
			$this->markTestSkipped( 'NextGen Gallery is not loaded — run via bin/test.sh --compat.' );
		}

		parent::set_up();

		$this->galleryDir = \wpSPIO()->filesystem()->getWPFileBase()->getPath() . self::GALLERY_REL;
		if ( ! is_dir( $this->galleryDir ) ) {
			mkdir( $this->galleryDir, 0777, true );
		}
	}

	public function tear_down() {
		if ( is_dir( $this->galleryDir ) ) {
			foreach ( glob( $this->galleryDir . '*' ) ?: array() as $file ) {
				@unlink( $file );
			}
			@rmdir( $this->galleryDir );
		}
		parent::tear_down();
	}

	/** Insert a real ngg_gallery row pointing at the on-disk dir. */
	private function insertGalleryRow(): int {
		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'ngg_gallery',
			array(
				'name'  => 'spio-ngg-test',
				'slug'  => 'spio-ngg-test',
				'path'  => self::GALLERY_REL,
				'title' => 'SPIO NGG compat test gallery',
			)
		);
		$this->assertNotFalse( $inserted, 'ngg_gallery insert failed: ' . $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	// -------------------------------------------------------------------
	// Coexistence + hook gating
	// -------------------------------------------------------------------

	public function test_nextgen_loads_alongside_spio() {
		global $wpdb;
		$this->assertTrue( defined( 'NGG_PLUGIN' ), 'NextGen must define its NGG_PLUGIN constant.' );
		$this->assertTrue( NextGenController::getInstance()->has_nextgen(), 'SPIO must detect NextGen via NGG_PLUGIN.' );

		$table = $wpdb->prefix . 'ngg_gallery';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'The NGG activation hook must have created ngg_gallery.' );
	}

	public function test_optimize_hooks_gated_behind_include_setting() {
		// Presence hooks: wired whenever NGG is active…
		$this->assertNotFalse( has_action( 'ngg_delete_image' ), 'The delete-image sync hook must be wired when NGG is active.' );
		$this->assertNotFalse( has_action( 'shortpixel/othermedia/folder/load' ), 'The folder-load NextGen detector must be wired.' );

		// …optimize hooks: NOT wired, because includeNextGen was off when
		// plugins_loaded fired in this process.
		$this->assertFalse( has_action( 'ngg_added_new_image' ), 'Upload hook must stay off while includeNextGen is disabled.' );
		$this->assertFalse( has_action( 'ngg_update_addgallery_page' ), 'Gallery-page hook must stay off while includeNextGen is disabled.' );

		// Enabling the setting and re-running hooks() wires them
		// ($wp_filter is restored per test, so this doesn't leak).
		\wpSPIO()->settings()->includeNextGen = 1;
		NextGenController::getInstance()->hooks();
		$this->assertNotFalse( has_action( 'ngg_added_new_image' ), 'Upload hook must be wired once includeNextGen is on.' );
		$this->assertNotFalse( has_action( 'ngg_update_addgallery_page' ), 'Gallery-page hook must be wired once includeNextGen is on.' );
	}

	// -------------------------------------------------------------------
	// Gallery → Custom Media folder registration
	// -------------------------------------------------------------------

	public function test_galleries_register_as_custom_media_folders() {
		\wpSPIO()->settings()->includeNextGen = 1;
		$this->insertGalleryRow();

		NextGenController::getInstance()->addNextGenGalleriesToCustom( true );

		// Lookup path must keep the trailing slash: the model matches the
		// raw constructor path against the stored (trailingslashed) row.
		$folder = OtherMediaController::getInstance()->getFolderByPath( $this->galleryDir );
		$this->assertTrue( $folder->get( 'in_db' ), 'The NGG gallery must be registered as a Custom Media folder.' );
		$this->assertSame( DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN, (int) $folder->get( 'status' ), 'The registered folder must carry NextGen status.' );

		$this->assertGreaterThan( 0, (int) \wpSPIO()->settings()->hasCustomFolders, 'hasCustomFolders timestamp must be set after registering galleries.' );
	}

	public function test_folder_at_gallery_path_auto_upgrades_to_nextgen_on_save() {
		$this->insertGalleryRow();

		// A folder at the gallery path starts as a plain custom folder…
		$otherMedia = OtherMediaController::getInstance();
		$folder     = $otherMedia->getFolderByPath( $this->galleryDir );
		$this->assertFalse( $folder->get( 'in_db' ) );
		$this->assertSame( DirectoryOtherMediaModel::DIRECTORY_STATUS_NORMAL, (int) $folder->get( 'status' ) );

		// …but every DB (re)load fires shortpixel/othermedia/folder/load,
		// where NextGenController::loadFolder matches the path against
		// ngg_gallery and upgrades + persists the status. Saving triggers
		// the reload, so the upgrade happens right here.
		$folder->save();

		$reloaded = $otherMedia->getFolderByPath( $this->galleryDir );
		$this->assertTrue( $reloaded->get( 'in_db' ) );
		$this->assertSame( DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN, (int) $reloaded->get( 'status' ), 'The load hook must tag folders at gallery paths as NextGen.' );
		$this->assertTrue( $reloaded->get( 'is_nextgen' ), 'The is_nextgen flag must be derived from the status.' );
	}

	// -------------------------------------------------------------------
	// Upload → Custom Media pipeline → optimize
	// -------------------------------------------------------------------

	public function test_nextgen_upload_optimizes_through_custom_media_pipeline() {
		\wpSPIO()->settings()->includeNextGen = 1;
		$this->insertGalleryRow();
		NextGenController::getInstance()->addNextGenGalleriesToCustom( true );

		// What handleImageUpload() does once NGG resolves the abspath.
		$imagePath = $this->galleryDir . 'fixture-small.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $imagePath );
		OtherMediaController::getInstance()->addImage( $imagePath, array( 'is_nextgen' => true ) );

		$customImage = OtherMediaController::getInstance()->getCustomImageByPath( $imagePath );
		$this->assertGreaterThan( 0, (int) $customImage->get( 'id' ), 'The uploaded NGG image must land in the custom-media table.' );
		$this->assertFalse( $customImage->isOptimized() );

		$queueController = new QueueController();
		$queueController->addItemToQueue( $customImage );
		$this->runQueueUntilEmpty();

		$customImage = OtherMediaController::getInstance()->getCustomImageByPath( $imagePath );
		$this->assertTrue( $customImage->isOptimized(), 'The NextGen image must optimize through the custom queue.' );
		$this->assertNotEmpty( $this->api->requests, 'The (mock) API must have been called for the NGG image.' );
	}
}
