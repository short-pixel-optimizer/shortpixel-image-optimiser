<?php
namespace ShortPixel\Controller\Backup;

use ShortPixel\Model\Backup\BackupModel;
use ShortPixel\Model\Backup\LocalBackupModel;
use ShortPixel\Model\File\FileModel;
use ShortPixel\Model\Image\CustomImageModel;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\Model\Image\MediaLibraryModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Abstract base for SPIO backup controllers.
 *
 * Provides the factory that selects the active backup implementation based
 * on the `backupImages` setting, and the shared infrastructure for
 * per-attachment {@see BackupModel} instantiation, auto-removal scheduling,
 * and per-image model caching.
 *
 * Two concrete subclasses ship with the plugin:
 *
 * - {@see LocalBackupController} — active when `backupImages` is truthy.
 *   Orchestrates auto-removal of old backup files from the local filesystem
 *   (via cron or WP-CLI).
 *
 * - {@see NoBackupController} — active when `backupImages` is false.
 *   Extends LocalBackupController but adds no behaviour: all backup creation
 *   calls are still routed through LocalBackupModel, which means existing
 *   backups remain accessible for restore even after the user disables the
 *   backup setting.
 *
 * Neither backup mode is to be confused with the "Smart Backup"
 * (`singleFileBackup`) setting, which is a LocalBackupModel-level concern:
 * when `singleFileBackup` is on, only the main file is backed up and
 * thumbnails are regenerated from it on restore (see
 * {@see LocalBackupModel::createBackupFile()} and
 * {@see BackupModel::needsRegenerate()}). When `singleFileBackup` is off,
 * every thumbnail gets its own backup file.
 *
 * Obtain the active controller via {@see BackupController::getBackupController()}.
 * Obtain a per-image model via {@see BackupController::getBackupModel()}.
 *
 * @package ShortPixel\Controller\Backup
 */
abstract class BackupController
{
    /** @var static|null Singleton instance — set by getBackupController() on first call. */
    protected static $instance;

    /**
     * Two-level cache of BackupModel instances keyed by type then attachment id.
     *
     * Shape: `[ 'media' => [ $id => BackupModel ], 'custom' => [ $id => BackupModel ] ]`
     *
     * @var array<string, array<int, BackupModel>>
     */
    protected static $models = [];

    /**
     * Fully-qualified class name of the BackupModel implementation to
     * instantiate for new entries. Set by getBackupController() when
     * LocalBackupController is selected; intentionally left unset when
     * NoBackupController is selected (see suspected-bug note in report).
     *
     * @var string|null
     */
    protected static $model;

    /**
     * Subclass hook for the auto-removal logic triggered by cronRemoveBackups()
     * and cliRemoveBackups() after checkRemoveBackups() returns true.
     *
     * @return void
     */
    abstract protected function autoRemoveBackups();

    /** Intentionally empty; use {@see getInstance()} to obtain the mode-specific singleton. */
    public function __construct()
    {

    }

    /**
     * Factory: return the singleton backup controller for the current request.
     *
     * On the first call, inspects `settings()->backupImages`:
     *   - false → {@see NoBackupController} (no new backups; existing ones still readable)
     *   - truthy → {@see LocalBackupController} + sets `self::$model` to LocalBackupModel
     *
     * Subsequent calls return the cached instance without re-reading settings.
     *
     * @return static
     */
    public static function getBackupController()
    {
      $settings = \wpSPIO()->settings();

      if (is_null(self::$instance))
      {

      // @todo  The problem here is perhaps that the localBackupModel is not set, but reference in getBackup by ID.
        if (false === $settings->backupImages)
        {
          self::$instance = new NoBackupController();

        }
        else
        {
          self::$instance = new LocalBackupController();
          self::$model = '\ShortPixel\Model\Backup\LocalBackupModel';
        }
        // Here check with settings which backup method is active
      }

      return self::$instance;
    }

    /**
     * Return the BackupModel for the given top-level image item.
     *
     * Enforces that $imageItem must be a MediaLibraryModel or
     * CustomImageModel (not a raw ImageModel or thumbnail sub-object) so
     * that convertMeta and the full file family are always available.
     * Throws \Exception when called with a lower-level type.
     *
     * @param ImageModel $imageItem Top-level media or custom image.
     * @return BackupModel
     * @throws \Exception When $imageItem is not MediaLibraryModel or CustomImageModel.
     */
    public static function getBackupModel(ImageModel $imageItem)
    {
      //MediaLibraryModel|CustomImageModel
      if (! ($imageItem instanceof MediaLibraryModel) && ! ($imageItem instanceof CustomImageModel))
      {
        throw new \Exception('BackupController - BackupModel initialization class must be of the highest level either media or custom');
      }

      $backupController = self::getBackupController();

      return $backupController->getModel($imageItem);
    }

    /**
     * Return the BackupModel for the given image by delegating to getModelById().
     *
     * Prefers the cached instance when one already exists for this item's
     * id + type combination.
     *
     * @param ImageModel $mediaItem Top-level media item.
     * @return BackupModel
     */
    public function getModel(ImageModel $mediaItem)
    {
        $id = $mediaItem->get('id');
        $type = $mediaItem->get('type');

        return $this->getModelById($id, $type, $mediaItem);
    }

    /**
     * Return (or create and cache) a BackupModel for the given id + type pair.
     *
     * When no cache entry exists, fetches the top-level image from the
     * filesystem controller if $mediaItem is null or not the main file — this
     * is necessary because convertMeta.isConverted() must be read from the
     * canonical main item, not from a thumbnail sub-object.
     *
     * Note: regardless of whether NoBackupController or LocalBackupController
     * is active, the concrete BackupModel is always hardcoded to
     * LocalBackupModel (see inline comment). This means existing backups remain
     * queryable even when the backup setting is off.
     *
     * @param int         $id        Attachment / custom-image id.
     * @param string      $type      'media' or 'custom'.
     * @param ImageModel|null $mediaItem Optional pre-fetched main item; avoids a
     *                                  redundant filesystem lookup when already known.
     * @return BackupModel
     */
    protected function getModelById(int $id, $type = 'media', $mediaItem = null) : BackupModel
    {
      if (! isset(self::$models[$type]) || ! isset(self::$models[$type][$id]))
      {
          // It needs to be the main MediaItem here, because it checks ConvertMeta for IsConvertered, which is only set there.
          if (is_null($mediaItem) || false === $mediaItem->get('is_main_file'))
          {
             $fs = \wpSPIO()->filesystem();
             $mediaItem = $fs->getImage($id, $type);
          }

          // The issue here is when the backups are off, the model var isn't loaded properly, leading to crash
          //$model = new self::$model(self::$instance, $mediaItem);
          $model = new \ShortPixel\Model\Backup\LocalBackupModel(self::$instance, $mediaItem);


          if (! isset(self::$models[$type]))
          {
            self::$models[$type] = [];
          }
          self::$models[$type][$id] = $model;
      }

      return self::$models[$type][$id];
    }

    /**
     * Placeholder for future per-item contextual binding.
     *
     * Currently unimplemented; reserved for a planned API to associate
     * additional context with a media item mid-flow.
     *
     * @param ImageModel $mediaItem Media item to bind.
     * @return void
     */
    public function withItem($mediaItem)
    {

    }


    /**
     * Cron hook entry point for automatic backup removal.
     *
     * Delegates to checkRemoveBackups() first; returns false early when the
     * auto-remove setting is off or the removal period is not configured.
     * When the check passes, calls autoRemoveBackups() on the concrete
     * subclass to perform the actual filesystem work.
     *
     * @return false|void False when the removal preconditions are not met;
     *                    void on successful dispatch to autoRemoveBackups().
     */
    public function cronRemoveBackups()
    {
        $bool = $this->checkRemoveBackups();
        if (false === $bool || $bool !== true)
        {
          return false;
        }

        $this->autoRemoveBackups();

    }

    /**
     * WP-CLI entry point for manual backup removal.
     *
     * Identical logic to cronRemoveBackups(); separated so WP-CLI calls can
     * be distinguished from scheduled cron calls in logs and tests.
     *
     * @return false|void False when the removal preconditions are not met;
     *                    void on successful dispatch to autoRemoveBackups().
     */
    public function cliRemoveBackups()
    {
      $bool = $this->checkRemoveBackups();
      if (false === $bool || $bool !== true)
      {
        return false;
      }

      $this->autoRemoveBackups();
    }

    /**
     * Verify that the auto-remove preconditions are satisfied.
     *
     * Returns true only when both `autoRemoveBackups` is strictly true AND
     * `autoRemoveBackupsPeriod` is a non-null string. The double-condition
     * guard (false === $bool || $bool !== true) in the callers is
     * intentional: this method deliberately errs on the side of caution —
     * see the inline comment "better fail than fault".
     *
     * @return bool True when both settings are present and valid; false otherwise.
     */
    protected function checkRemoveBackups()
    {
        $settings = \wpSPIO()->settings();
        $bool = false;

        $removeBackups = $settings->autoRemoveBackups;
        $removePeriod = $settings->autoRemoveBackupsPeriod;

        if (true !== $removeBackups)
        {
           return false;
        }

        if (is_null($removePeriod))
        {
           return false;
        }

        // After many double checks, -better fail than fault- perhaps return true.
        if (is_string($removePeriod) && true === $removeBackups)
        {
           return true;
        }

        return false;

    }

} // class
