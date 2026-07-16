<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Notices\NoticeController as Notice;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\File\DirectoryOtherMediaModel as DirectoryOtherMediaModel;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;
use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;

use ShortPixel\NextGenViewController as NextGenViewController;

/**
 * Integration with **NextGen Gallery** — surfaces NextGen gallery folders
 * in SPIO's Custom Media pipeline and hooks NextGen's per-image lifecycle.
 *
 * Two-way responsibilities:
 *
 *   1. **Inbound (NextGen → SPIO):** every NextGen gallery folder gets
 *      registered as a Custom Media directory so SPIO's optimizer can
 *      walk it. Uploads and deletes on NextGen images propagate through
 *      to `OtherMediaController` so SPIO's state stays in sync with
 *      what's on disk.
 *   2. **Outbound (SPIO → NextGen):** the NextGen image-manager table
 *      grows a "ShortPixel Compression" column showing per-image
 *      status, and the compare/optimize popup UI is enqueued on
 *      NextGen screens. Both are handled by the paired
 *      `NextGenViewController`.
 *
 * The class is a singleton, self-instantiated at the bottom of this
 * file so its hooks register regardless of who loads the file first.
 *
 * Compat layer:
 *
 *   NextGen shipped a major-version rewrite in 2022 that moved from
 *   the legacy `\C_*` static namespace to `\Imagely\NGG\*`. This
 *   controller detects which one is present via `class_exists` in
 *   `hooks()` and stores the answer on `$is_legacy`; the three
 *   `getNGImageByID` / `getImageAbspath` / `getImageSizes` helpers
 *   then route through the appropriate API.
 *
 * Setting gate: unless `settings()->includeNextGen == 1` (or a caller
 * has set `$enableOverride`), the optimize-specific hooks stay off —
 * NextGen users who don't want SPIO touching their galleries can leave
 * the plugin installed without side-effects.
 *
 * @package ShortPixel
 */
// @integration NextGen Gallery
class NextGenController
{
  /** @var NextGenController|null Singleton instance held by getInstance(). */
  protected static $instance;
//  protected $view;

  /**
   * Temporary flag set by `enableNextGen()` when the user toggles the
   * NextGen setting on. NextGen may not yet report itself as active
   * (plugin still activating), but we still want to refresh folders —
   * `optimizeNextGen()` treats this flag as an early yes.
   *
   * @var bool
   */
	private $enableOverride = false; // when activating NG will not report active yet, but try to refresh folders. Do so.

  /**
   * Sticky flag set by `add_screen_loads()` when the current screen
   * matches one of NextGen's slug patterns. Cheaper than re-detecting
   * on every subsequent read.
   *
   * @var bool
   */
	private $is_ngg_screen = false; // is current screen NGG.

  /**
   * True when the installed NextGen version predates the 2022 Imagely
   * namespaces rewrite (i.e. only exposes `\C_Image_Mapper`,
   * `\C_Gallery_Storage`, etc.). Set in `hooks()` after class-existence
   * detection, and consulted by the three NG-API adapter methods at
   * the bottom of the class.
   *
   * @var bool
   */
  protected $is_legacy = false; // assume the best.

// ngg_created_new_gallery
  /**
   * Register the three unconditional hooks — everything else waits
   * for `plugins_loaded` so we can detect NextGen's version first.
   *
   *   - `shortpixel/init/optimize_on_screens` → `add_screen_loads`
   *     tells the SPIO processor which admin screens count as ours.
   *   - `plugins_loaded` → `hooks` fires the version-detection +
   *     conditional NG-specific wiring.
   *   - `deactivate_nextgen-gallery/nggallery.php` → `resetNotification`
   *     clears the SPIO integration notice when NextGen is turned
   *     off, so users don't see a stale "integration active" badge.
   */
  public function __construct()
  {
    add_filter('shortpixel/init/optimize_on_screens', array($this, 'add_screen_loads'), 10, 2);

    add_action('plugins_loaded', array($this, 'hooks'));
    add_action('deactivate_nextgen-gallery/nggallery.php', array($this, 'resetNotification'));
  }

  /**
   * `plugins_loaded` callback — wire the NG-specific hooks now that
   * NextGen's classes / constants are loaded and detectable.
   *
   * Runs in three passes:
   *
   *   1. **Always** — instantiate a `NextGenViewController` (its
   *      constructor runs the ViewController's parent hook wiring).
   *   2. **When `optimizeNextGen()` is on** — hook gallery-page and
   *      per-image lifecycle events so SPIO keeps up with NextGen
   *      changes.
   *   3. **When NextGen is actually present** (`has_nextgen()`) —
   *      register the delete-image hook, the columns + refresh
   *      filters for NG's image manager, and the screen detector.
   *      Also detect the legacy vs modern API here and cache the
   *      result on `$is_legacy`.
   *
   * @return void
   */
  public function hooks()
  {

		$controller = new NextGenViewController();

    if ($this->optimizeNextGen()) // if optimization is on, hook.
    {
      add_action('ngg_update_addgallery_page', array( $this, 'addNextGenGalleriesToCustom'));
      add_action('ngg_added_new_image', array($this,'handleImageUpload'));
    }

    if ($this->has_nextgen())
    {
			add_action('ngg_delete_image', array($this, 'OnDeleteImage'),10, 2); // this works only on single images!

      add_action('shortpixel/othermedia/folder/load', array($this, 'loadFolder'), 10, 2);
      // Off because this causes bad UX ( refresh folder but no images added)
			//add_action('shortpixel/othermedia/addfiles', array($this, 'checkAddFiles'), 10, 3);

      add_filter( 'ngg_manage_images_columns', array( $controller, 'nggColumns' ) );
      add_filter( 'ngg_manage_images_number_of_columns', array( $controller, 'nggCountColumns' ) );
      add_filter( 'ngg_manage_images_column_7_header', array( $controller, 'nggColumnHeader' ) );
      add_filter( 'ngg_manage_images_column_7_content', array( $this, 'loadNextGenItem' ), 10,2 );
			add_filter('ngg_manage_gallery_fields', array($this, 'refreshFolderOnLoad'), 10, 2);

      if (false === class_exists('\Imagely\NGG\DataStorage\Manager'))
      {
        $this->is_legacy = true;
      }
  
      add_action('current_screen', array($this, 'checkCurrentScreen'), 50);
   }



  }

  /**
   * Return the singleton instance, constructing it (and registering
   * its early hooks) on first call. The self-boot line at the bottom
   * of this file makes sure that happens the moment the autoloader
   * touches the class.
   *
   * @return NextGenController
   */
  // Use GetInstance, don't use the construct.
  public static function getInstance()
  {
    if (is_null(self::$instance))
      self::$instance = new NextGenController();

     return self::$instance;
  }

  /**
   * Whether NextGen Gallery is installed and active.
   *
   * Detected via NextGen's own `NGG_PLUGIN` constant, which the plugin
   * defines during its own bootstrap — cheaper and more reliable than
   * a `class_exists` walk.
   *
   * @return bool
   */
  public function has_nextgen()
  {
     if (defined('NGG_PLUGIN'))
      return true;
     else
       return false;
  }

  /**
   * Whether SPIO should treat NextGen galleries as optimize targets.
   *
   * True when either:
   *   - `$enableOverride` is set (temporary transition state during
   *     `enableNextGen()`), or
   *   - the user has ticked the NextGen integration in Settings
   *     (`settings()->includeNextGen == 1`).
   *
   * Used by `hooks()` to decide whether to wire the upload / gallery
   * lifecycle hooks, and by `checkAddFiles()` to reject files if the
   * setting is off.
   *
   * @return bool
   */
  public function optimizeNextGen()
  {
		 if (true === $this->enableOverride || \wpSPIO()->settings()->includeNextGen == 1)
		 {
		 	 return true;
		 }

     return false;
  }

  /**
   * Whether the currently-active admin screen is a NextGen one.
   *
   * The answer is set by `add_screen_loads()` earlier in the request
   * lifecycle; this method just reads the sticky flag. Consumed by
   * `ShortPixelPlugin::load_admin_scripts()` (which asks whether to
   * enqueue the NextGen-specific assets) and by `checkCurrentScreen()`
   * (which uses it to decide whether to enqueue the comparer).
   *
   * @return bool
   */
  public function isNextGenScreen()
  {
			return $this->is_ngg_screen;

  }

  /**
   * Enable the NextGen integration in-flight (called from the settings
   * controller when the user just ticked the include-NextGen option).
   *
   * Sets `$enableOverride = true` so `optimizeNextGen()` starts
   * returning true immediately (before the setting round-trips
   * through the DB), then re-runs `addNextGenGalleriesToCustom()`
   * so all existing galleries appear in Custom Media on the same
   * request.
   *
   * @param bool $silent Forwarded to `addNextGenGalleriesToCustom()` — when true, suppresses admin notices during the bulk add.
   * @return void
   */
  /** called from settingController when enabling the nextGen settings */
  public function enableNextGen($silent)
  {
		 $this->enableOverride = true;
     $this->addNextGenGalleriesToCustom($silent);
  }


  /**
   * `shortpixel/init/optimize_on_screens` filter — declare which
   * admin screens count as "SPIO-relevant" for asset-loading purposes.
   *
   * Detection is intentionally lenient because NextGen's screen ids
   * have changed shape across versions (the commented-out block at
   * the top shows the fragile explicit list this replaced). Two
   * fallbacks:
   *
   *   1. **Precise:** if `$screen` has an `ngg` property, NextGen
   *      itself tagged it as one of its own — take it as-is.
   *   2. **Substring:** otherwise, match `$screen->id` against
   *      `ngg` / `nggallery` / `nextgen-gallery` substrings so
   *      renamed variants still register.
   *
   * Every match also sets `$is_ngg_screen = true` so
   * `isNextGenScreen()` can answer without re-running the detection.
   *
   * @param array   $use_screens Screen ids collected so far by SPIO / other integrations.
   * @param WP_Screen $screen    Current admin screen object.
   * @return array The augmented list.
   */
  public function add_screen_loads($use_screens, $screen)
  {
/*
The screen IDS seem to be have changed, trying a more definitive solution
    $use_screens[] = 'toplevel_page_nextgen-gallery'; // toplevel
    $use_screens[] = 'nextgen-gallery_page_ngg_addgallery';  // add gallery
    $use_screens[] = 'nextgen-gallery_page_nggallery-manage-album'; // manage album
    $use_screens[] = 'nextgen-gallery_page_nggallery-manage-gallery'; // manage toplevel gallery
    $use_screens[] = 'nggallery-manage-images'; // images in gallery overview
*/

	 $screen_pos = ['ngg', 'nggallery', 'nextgen-gallery'];

	 if (property_exists($screen, 'ngg'))
	 {
		 	$use_screens[] = $screen->id;
			$this->is_ngg_screen = true;

	 }
	 else
	 {
		 	foreach($screen_pos as $pos)
			{
				  $index = strpos($screen->id, $pos);

          if ($index !== false)
					{
						 $use_screens[]= $screen->id;
						 $this->is_ngg_screen = true;
					}
			}
	 }
    return $use_screens;
  }

  /**
   * `current_screen` action (priority 50) — enqueue the compare-popup
   * snippet on NextGen screens.
   *
   * Delays the enqueue until we're sure we're on a NextGen screen
   * (avoids shipping the markup to every WP admin page). Instantiates
   * a `NextGenViewController` per request — see the `@todo` on
   * `loadNextGenItem` below about the re-instantiation cost.
   *
   * @param WP_Screen $screen Current admin screen (unused; state comes from `isNextGenScreen()`).
   * @return void
   */
	public function checkCurrentScreen($screen)
	{

		if ($this->isNextGenScreen())
		{
				$controller = new NextGenViewController();
					add_action('admin_footer', [$controller, 'loadComparer']);
		}
	}


  /**
   * `ngg_manage_images_column_7_content` filter — render the per-row
   * "ShortPixel Compression" cell in NextGen's image-manager table.
   *
   * Delegates to `NextGenViewController::loadItem()` which does the
   * `getCustomImageByPath` → render pipeline.
   *
   * @todo The view controller is re-instanced on every row (once per
   *       filter fire) even though its state doesn't need to reset
   *       between rows. Would be cheaper to cache one instance on
   *       `$this->viewController` and reuse.
   *
   * @param mixed  $unknown Ignored — NextGen passes the current column's default value here.
   * @param object $picture NextGen image object to render.
   * @return void
   */
  public function loadNextGenItem($unknown, $picture)
  {
			 // @todo The view controller is re-instanced all the time while also doing permanent hooks work in the top, which is not great.
       $viewController = new NextGenViewController();
       $viewController->loadItem($picture);
  }

  /**
   * `ngg_manage_gallery_fields` filter — force a refresh of the SPIO
   * folder record whenever a NextGen gallery-manage page is loaded.
   *
   * The refresh is what surfaces newly-uploaded images (or removed
   * ones) into SPIO's Custom Media list without waiting for the
   * separate custom-folders scan job.
   *
   * @param array  $array   Field-config array NextGen is building.
   * @param object $gallery NextGen gallery object; must expose `$gid`.
   * @return array The `$array` argument passed through unchanged.
   */
	public function refreshFolderOnLoad($array, $gallery)
	{
		 $galleries = $this->getGalleries($gallery->gid);
		 if (isset($galleries[0]))
		 {
			  $otherMedia = OtherMediaController::getInstance();
			  $galleryFolder = $galleries[0];
				$folder = $otherMedia->getFolderByPath($galleryFolder->getPath());
				$folder->refreshFolder(true);
		 }
		 return $array;
	}


  /**
   * `shortpixel/othermedia/folder/load` action — try to identify a
   * Custom Media folder as a NextGen gallery and mark it accordingly.
   *
   * The tricky bit: NextGen stores gallery paths in a short "relative"
   * form (e.g. `wp-content/gallery/summer-photos/`) while SPIO stores
   * absolute paths. Rather than reverse the transformation, we assume
   * the **last two directory segments** of the SPIO path are unique
   * enough to identify a specific gallery — a `LIKE '%.../foo/bar/'`
   * query against `ngg_gallery.path` succeeds when the folder is
   * indeed a NextGen gallery.
   *
   * When a match is found, the folder is upgraded to
   * `DIRECTORY_STATUS_NEXTGEN` and saved. Existing NextGen-tagged
   * folders short-circuit with no query.
   *
   * @param int                        $id        Folder id (unused; kept for the SPIO hook signature).
   * @param DirectoryOtherMediaModel   $directory Directory model instance passed by the hook.
   * @return void
   */
  public function loadFolder($id, $directory)
  {
      $path = $directory->getPath();
			// No reason to check this?
			if ($directory->get('status') == DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN)
			{	return;  }

      $path_split = array_filter(explode('/', $path));

      $searchPath = trailingslashit(implode('/', array_slice($path_split, -2, 2)));

      global $wpdb;
      $sql = "SELECT gid FROM {$wpdb->prefix}ngg_gallery WHERE path LIKE %s";
      $sql = $wpdb->prepare($sql, '%' . $searchPath . '');
      $gid = $wpdb->get_var($sql);


      if (! is_null($gid) && is_numeric($gid))
			{
        $res = $directory->set('status', DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN);
				$directory->save();
				//echo $gid;
			}
  }

  /**
   * `shortpixel/othermedia/addfiles` filter — veto the file-adding
   * step for NextGen folders when the include-NextGen setting is off.
   *
   * **Currently unhooked** (the `add_action` call in `hooks()` is
   * commented out because it caused a confusing UX where refresh-
   * folder would visibly run but no images actually got added).
   * Kept in the class for reference — un-comment the hook and this
   * method takes over rejecting files for NG folders on refresh.
   *
   * @param bool                       $bool   Existing filter value from prior handlers.
   * @param array                      $files  Files that would be added.
   * @param DirectoryOtherMediaModel   $dirObj Directory the files belong to.
   * @return bool `$bool` for non-NG folders; false for NG folders when the setting is off; `$bool` otherwise.
   */
	public function checkAddFiles($bool, $files, $dirObj)
	{
			// Nothing nextgen.
			if ($dirObj->get('is_nextgen') === false)
		  {
				 return $bool;
			}

			// If it's nextgen, but the setting is not on, reject those files.
			if ($this->optimizeNextGen() === false)
			{
				 	return false;
			}

			return $bool;
	}

  /**
   * Return every NextGen gallery as a list of `DirectoryModel`
   * objects, filtered to those that actually exist on disk.
   *
   * With `$id` supplied, returns just the matching gallery (used by
   * `refreshFolderOnLoad()` when reacting to a specific gallery's
   * manage page). With `$id` null, returns all galleries — used by
   * `addNextGenGalleriesToCustom()` for the bulk-add flow.
   *
   * Non-existent paths (galleries whose folders were deleted outside
   * NextGen's admin) are silently dropped.
   *
   * @param int|null $id Optional NextGen gallery id (`ngg_gallery.gid`).
   * @return DirectoryModel[]
   */
  /* @return DirectoryModel */
  public function getGalleries($id = null)
  {
    global $wpdb;
    $fs = \wpSPIO()->filesystem();
    $homepath = $fs->getWPFileBase();

		$sql = "SELECT path FROM {$wpdb->prefix}ngg_gallery";
		if (! is_null($id))
		{
			 $sql .= ' WHERE gid = %d';
			 $sql = $wpdb->prepare($sql, $id);
		}
    $result = $wpdb->get_results($sql);

    $galleries = array();

    foreach($result as $row)
    {
      $directory = $fs->getDirectory($homepath->getPath() . $row->path);
      if ($directory->exists())
        $galleries[] = $directory;
    }

    return $galleries;
  }

  /**
   * `ngg_update_addgallery_page` action — walk every NextGen gallery
   * and make sure each has a Custom Media folder row.
   *
   * Three cases per gallery:
   *
   *   1. Already in the SPIO folders table (`in_db === true`) — just
   *      make sure the status is `DIRECTORY_STATUS_NEXTGEN` and save
   *      if it wasn't.
   *   2. Not in the table but `checkDirectory(true)` (silent-mode)
   *      rejects it — skip. Usually means the gallery folder is
   *      unwritable, sits outside the WP root, or overlaps the
   *      backup directory.
   *   3. Not in the table and passes the check — add via
   *      `OtherMediaController::addDirectory()`, then tag it
   *      `DIRECTORY_STATUS_NEXTGEN`.
   *
   * When at least one gallery is present, updates the
   * `hasCustomFolders` timestamp so the Custom Media dashboard can
   * detect that folders exist.
   *
   * NOTE: does NOT check whether the NextGen integration is enabled —
   * callers must gate that themselves (see `optimizeNextGen()`).
   *
   * @param bool $silent When true, `checkDirectory` runs in silent mode (no admin notices for rejected directories).
   * @return void
   */
   public function addNextGenGalleriesToCustom($silent = true) {
      $fs = \wpSPIO()->filesystem();
      $homepath = $fs->getWPFileBase();

      //add the NextGen galleries to custom folders
      $ngGalleries = $this->getGalleries(); // DirectoryModel return.

      $otherMedia = OtherMediaController::getInstance();

      foreach($ngGalleries as $gallery)
			{
          $folder = $otherMedia->getFolderByPath($gallery->getPath());

          if ($folder->get('in_db') === true)
          {
						if ($folder->get('status') !== 1)
						{
							 $folder->set('status', DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN);
							 $folder->save();
						}
            continue;
          }
					else
					{
						// Try to silently fail this if directory is not allowed.
						if (false === $folder->checkDirectory(true))
						{
							continue;
						}
          	$directory = $otherMedia->addDirectory($gallery->getPath());
						if (! $directory)
						{
							Log::addWarn('Could not add this directory' . $gallery->getPath() );
						}
						else
						{
							 $directory->set('status', DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN);
							 $directory->save();
						}
					}


      }

      if (count($ngGalleries) > 0)
      {
        // put timestamp to this setting.
        $settings = \wpSPIO()->settings();
        $settings->hasCustomFolders = time();
      }


  }

  /**
   * `ngg_added_new_image` action — pipe a freshly-uploaded NextGen
   * image into SPIO's Custom Media pipeline so the optimizer picks
   * it up on the next run.
   *
   * Bails silently when the include-NextGen setting is off — the
   * hook stays registered but does nothing, so toggling the setting
   * doesn't require a plugin reload.
   *
   * @param object $image NextGen image object; its abspath is resolved via `getImageAbspath()`.
   * @return void
   */
  public function handleImageUpload($image)
  {
    $otherMedia = OtherMediaController::getInstance();
    //$fs = \wpSPIO()->filesystem();

    if ($this->optimizeNextGen() === true) {
          $imageFsPath = $this->getImageAbspath($image);

          $otherMedia->addImage($imageFsPath, array('is_nextgen' => true));
      }
  }

  /**
   * `deactivate_nextgen-gallery/nggallery.php` action — clear the SPIO
   * NextGen integration notice when the user deactivates NextGen.
   *
   * Without this, the "NextGen integration active" admin notice would
   * linger even after NextGen was removed, prompting confused support
   * tickets.
   *
   * @return void
   */
  public function resetNotification()
  {
    Notice::removeNoticeByID('MSG_INTEGRATION_NGGALLERY');
  }

  /**
   * `ngg_delete_image` action — tell SPIO to purge its records for a
   * NextGen image that just got deleted.
   *
   * NextGen fires this hook for both whole-image deletes (`$size ===
   * false`) and individual-size deletes (`$size` is a size slug). For
   * a whole-image delete we walk every size via `getImageSizes()` and
   * queue each abspath for `onDelete()`; for a specific size we
   * process just that one path.
   *
   * (Prior to 399b29e2 the else-branch used `array_merge($paths,
   * $this->getImageAbspath(...))`, which raised a PHP 8 TypeError
   * because `getImageAbspath` returns a string; the current
   * `$paths[] = ...` append works uniformly.)
   *
   * @param int         $nggId NextGen image id.
   * @param string|false $size Size slug, or false for a whole-image delete.
   * @return void
   */
  public function onDeleteImage($nggId, $size)
  {

	  	$image = $this->getNGImageByID($nggId);

			$paths = array();

			if ($size === false)
			{
				$imageSizes = $this->getImageSizes($image);
				foreach($imageSizes as $size)
				{
					$paths[] = $this->getImageAbspath($image, $size);

				}
			}
			else {
				$paths[] = $this->getImageAbspath($image, $size);
			}

			foreach($paths as $path)
			{
				$otherMediaController = OtherMediaController::getInstance();
				$mediaItem = $otherMediaController->getCustomImageByPath($path);
				$mediaItem->onDelete();
			}
  }



  /* Seems not in use 
  public function updateImageSize($nggId, $path) {

      $image = $this->getNGImageByID($nggId);

      $dimensions = getimagesize($this->getImageAbspath($image));
      $size_meta = array('width' => $dimensions[0], 'height' => $dimensions[1]);
      $image->meta_data = array_merge($image->meta_data, $size_meta);
      $image->meta_data['full'] = $size_meta;
      $this->saveToNextGen($image);
  } */

  /**
   * NG API adapter — fetch a NextGen image entity by id.
   *
   * Legacy branch: `\C_Image_Mapper::get_instance()->find($id)`.
   * Modern branch: `\Imagely\NGG\DataMappers\Image::get_instance()->find($id)`.
   *
   * The `$is_legacy` flag was set during `hooks()` based on whether
   * `\Imagely\NGG\DataStorage\Manager` exists.
   *
   * @param int $nggId NextGen image id.
   * @return object|null NextGen image entity, or null when not found.
   */
  protected function getNGImageByID($nggId)
  {
    if (true === $this->is_legacy)
    {
      $mapper = \C_Image_Mapper::get_instance();
      $image = $mapper->find($nggId);
      return $image;
    }
    
    $class = '\Imagely\NGG\DataMappers\Image';
    $imageMapper = $class::get_instance(); 

    return $imageMapper->find($nggId);

  }

  /* @param NextGen Image */
  /* Seems not in use 
  protected function saveToNextGen($image)
  {
    if (true === $this->is_legacy)
    {
      $mapper = \C_Image_Mapper::get_instance();
      $mapper->save($image);
    }
    else
    {
      $class = '\Imagely\NGG\DataMappers\Image';
      $imageMapper = $class::get_instance(); 
      $imageMapper->save_entity($image);
    }

  } */

  /**
   * NG API adapter — resolve the local filesystem path for a NextGen
   * image at a given size.
   *
   * Legacy branch: `\C_Gallery_Storage::get_instance()->get_image_abspath($image, $size)`.
   * Modern branch: `\Imagely\NGG\DataStorage\Manager::get_instance()->get_image_abspath($image, $size)`.
   *
   * @param object $image NextGen image entity (as returned by `getNGImageByID`).
   * @param string $size  Size slug — defaults to `'full'`.
   * @return string Absolute filesystem path to the image at that size.
   */
  protected function getImageAbspath($image, $size = 'full') : string
  {

      if (true === $this->is_legacy)
      {
        $storage = \C_Gallery_Storage::get_instance();
        return $storage->get_image_abspath($image, $size);
      }

      $class = '\Imagely\NGG\DataStorage\Manager'; 
      $galleryManager = $class::get_instance(); 

      return $galleryManager->get_image_abspath($image, $size); 

  }

  /**
   * NG API adapter — return every size slug NextGen tracks for a
   * given image (`full`, thumbnails, custom crops, etc.).
   *
   * Legacy branch: `\C_Gallery_Storage::get_instance()->get_image_sizes($image)`.
   * Modern branch: `\Imagely\NGG\DataStorage\Manager::get_instance()->get_image_sizes($image)`.
   *
   * Used by `onDeleteImage()` when NextGen fires a whole-image delete
   * so SPIO can clean up every size, not just the one the user
   * happened to touch.
   *
   * @param object $image NextGen image entity.
   * @return string[] Size slugs.
   */
  protected function getImageSizes($image)
	{
     if (true === $this->is_legacy)
     {
		    $storage = \C_Gallery_Storage::get_instance();
		    return $storage->get_image_sizes($image);
     }

      $class = '\Imagely\NGG\DataStorage\Manager'; 
      $galleryManager = $class::get_instance(); 

      return $galleryManager->get_image_sizes($image); 

	}

} // class.

// Self-boot at file-load time — instantiating the singleton here
// registers the `plugins_loaded` / `shortpixel/init/optimize_on_screens`
// hooks before either can fire. Same pattern as
// `class/external/offload/Offloader.php`. Fine as long as the file is
// listed in the autoloader manifest early enough.
$ng = NextGenController::getInstance();
