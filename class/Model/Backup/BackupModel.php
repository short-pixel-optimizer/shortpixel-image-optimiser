<?php
namespace ShortPixel\Model\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Helper\DownloadHelper;
use ShortPixel\Model\File\FileModel;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * Abstract base for per-attachment backup managers.
 *
 * One BackupModel instance holds the entire backup state for a single
 * media item — main file, thumbnails, retinas, and the unscaled original
 * when present — so that create / restore / delete flows can operate on
 * the family as a unit. The concrete storage strategy (local disk vs.
 * anything else in the future) is left to subclasses; the only shipped
 * implementation today is LocalBackupModel.
 *
 * Owned by BackupController; typically not instantiated directly. The
 * $mediaItem is intentionally cloned in loadMediaItem() so subsequent
 * mutations on the caller's ImageModel don't leak into decisions made
 * during backup / restore.
 *
 * @package ShortPixel\Model\Backup
 */
abstract class BackupModel
{

    /**
     * Per-image cache of backup lookup results, keyed by image name (see
     * subclass getBackupName()).
     *
     * Each entry has the shape:
     *   [ 'has_backup' => bool, 'file' => string|false, 'has_own_file' => bool ]
     *
     * `has_own_file = false` means "no dedicated backup for this thumbnail,
     * but the main file's backup covers it" — the regenerate path uses this
     * distinction (see needsRegenerate()).
     *
     * @var array<string, array{has_backup: bool, file: string|false, has_own_file: bool}>
     */
    protected $backup_files = [];

    /** @var bool True once loadAll() has walked every file of the media item; used to short-circuit repeat calls. */
    protected $full_backup_loaded = false;

    /** @var \ShortPixel\Model\File\DirectoryModel|null Cached backup directory model, populated on first getBackupDirectory() call. */
    protected $backupDirectory;

    /** @var int Result code of the most recent write operation — one of the STATUS_* or ERR_* constants. */
    protected $statusCode = 0;

    /** @var BackupController Owning controller; kept for subclasses that need to reach back into the wider backup subsystem. */
    protected $controller;

    /** @var ImageModel Cloned snapshot of the media item this backup manages. */
    protected $mediaItem;

    /** @var bool Cached convertMeta.isConverted() at loadMediaItem() time; drives the "backup + regenerate" restore branches. */
    protected $isConverted;

    /**
     * Return the backup directory for the current media item.
     *
     * @param bool $create Create the directory on disk (and any missing
     *                     ancestors) when it does not yet exist.
     * @return \ShortPixel\Model\File\DirectoryModel|false
     */
    abstract protected function getBackupDirectory($create = false);

    /**
     * Copy the given source image into backup storage.
     *
     * @param ImageModel $sourceFile Image (main file OR thumbnail) to back up.
     * @return bool True on success (or when the backup already exists with a matching size).
     */
    abstract public function createBackupFile(ImageModel $sourceFile) : bool;

    /**
     * Move the stored backup file back to $sourceFile's live location.
     *
     * @param ImageModel $sourceFile Image whose backup should be restored.
     * @return bool
     */
    abstract public function restore(ImageModel $sourceFile);

    /**
     * Whether a backup exists for the given image.
     *
     * @param ImageModel $sourceFile Image to look up.
     * @param bool       $strict     When true, do NOT fall through to a
     *                               main-file backup for thumbnails — used
     *                               to prevent recursion when the caller
     *                               already knows about the main file.
     * @return bool
     */
    abstract public function hasBackup(ImageModel $sourceFile, $strict = false) : bool;

    /**
     * Delete the backup file (if any) belonging to the given image.
     *
     * @param ImageModel $sourceFile Image whose backup should be removed.
     * @return bool True on success or when there was nothing to delete.
     */
    abstract public function onDelete(ImageModel $sourceFile) : bool;

    /**
     * Return a FileModel pointing at the backup file for the given image,
     * or false when no dedicated backup exists.
     *
     * @param ImageModel $sourceFile Image to look up.
     * @return \ShortPixel\Model\File\FileModel|false
     */
    abstract public function getBackupFile(ImageModel $sourceFile);

    /**
     * Indicate whether the backup strategy stores a single main-file backup
     * that covers thumbnails (as opposed to per-thumbnail backups).
     *
     * @return bool
     */
    abstract public function backupIsMain();

    /**
     * Return the FileModel for the main-file backup of this media item.
     *
     * @return \ShortPixel\Model\File\FileModel|false
     */
    abstract public function getMainBackupFile();

    /**
     * Rename every backup file for this media item so its base filename
     * matches $newBaseFileName. Used by the AI rename flow.
     *
     * @param string $newBaseFileName New base name (without extension).
     * @return bool True when every rename succeeded, false when any failed
     *              (successful renames are preserved either way).
     */
    abstract public function renameBackup($newBaseFileName) : bool;


    /**
     * Populate $backup_files by walking every file belonging to the media
     * item and running hasBackup() against each. Meant to be idempotent
     * via the $full_backup_loaded guard.
     *
     * @return void
     */
    abstract protected function loadAll();

    /**
     * Return the fully-loaded $backup_files map (running loadAll() first if
     * it hasn't yet).
     *
     * @return array<string, array{has_backup: bool, file: string|false, has_own_file: bool}>
     */
    abstract public function getBackupData();

    /** File-status code: source was not copied, because a suitable backup already existed elsewhere (e.g. covered by the main file). */
    const STATUS_IGNORED = 1;
    /** File-status code: the file was freshly copied to the backup location. */
    const STATUS_COPIED = 2;
    /** File-status code: an existing backup was verified as OK (same size on disk); no copy was performed. */
    const STATUS_BACKUP_OK = 3;

    /** Error code: the file copy operation failed. */
    const ERR_COPY_FAILED = -1;
    /** Error code: a conflicting backup already exists. */
    const ERR_BACKUP_EXISTS = -2;

    /**
     * Constructor.
     *
     * @param BackupController $controller Owning controller.
     * @param ImageModel       $mediaItem  Media item to manage backups for.
     */
    public function __construct(BackupController $controller, ImageModel $mediaItem)
    {
        $this->controller = $controller;
        $this->loadMediaItem($mediaItem);
    }

    /**
     * (Re)bind this instance to a media item.
     *
     * The passed model is cloned so downstream mutations by the caller do
     * not leak into backup / restore decisions. Also snapshots
     * convertMeta.isConverted() so we don't have to re-check it later.
     *
     * This is a public method because the Converter needs to be able to
     * reset the bound item mid-flow (e.g. when a conversion produces a
     * new replacementImageBase).
     *
     * @param ImageModel $mediaItem Media item to bind.
     * @return void
     */
    public function loadMediaItem(ImageModel $mediaItem)
    {
        $this->mediaItem = clone $mediaItem;       // Read-ony copy, no referencing here.
        $this->isConverted = $this->mediaItem->getMeta()->convertMeta()->isConverted();
    }

    /**
     * Magic accessor exposing protected properties (backup_files,
     * mediaItem, statusCode, etc.) for read-only inspection.
     *
     * @param string $name Property name.
     * @return mixed|null
     */
    public function __get($name)
    {
         if (property_exists($this, $name))
         {
             return $this->$name;
         }
         else
         {
             return null;
         }
    }

    /**
     * Whether at least one image in the family has a backup but no
     * dedicated backup file of its own — a signal to the restore path
     * that thumbnails should be regenerated after the main-file restore.
     *
     * @return bool
     */
    public function needsRegenerate() : bool
    {
         foreach($this->backup_files as $name => $fileAr)
         {
              if (true === $fileAr['has_backup'] && false === $fileAr['has_own_file'] )
              {
                return true;
              }
         }
         return false;

    }

    /**
     * Compute the filename that should be used for the backup of $sourceFile.
     *
     * Handles four cases, in priority order:
     *
     *   1. `replacementImageBase` set on convertMeta (conversion in progress):
     *      - main file → `<replaceBase>.<extension>`
     *      - thumbnail → `str_replace(<mainFileBase>, <replaceBase>, <sourceFileName>)`
     *   2. Only `fileFormat` set on convertMeta (post-conversion, no rename):
     *      - `<sourceFileBase>.<extension>` — swap the source's own extension for the original one
     *   3. Neither set (normal, non-converted flow):
     *      - `<sourceFileName>` verbatim
     *
     * For scaled media items, the main file base is taken from the unscaled
     * original — this keeps thumbnail rename math consistent with the
     * (non-scaled) filename the API expects.
     *
     * @param ImageModel $sourceFile Image whose backup filename we're computing.
     * @return string Backup filename (basename only — no directory).
     */
	public function getBackupFileName(ImageModel $sourceFile) : string
	{
        $is_main_file = $sourceFile->get('is_main_file'); 

        // NOTE -- Based on that in old source  this first statement never possible, false == mainfile, so commented. 
        /*
        $mainFile = (true === $is_main_file) ? $sourceFile : $this->mediaItem;
		if (false === $mainFile) {
			return $sourceFile->getFileName();
		} 
        */
        // Assertion here that for convert-types, there is no scaled- happening - Wrong! 
        
        $mainFile = $this->mediaItem;
        if (true === $mainFile->isScaled())
        { 
             $mainFileBase = $this->mediaItem->getOriginalFile()->getFileBase(); 
        }
        else
        {
            $mainFileBase = $this->mediaItem->getFileBase();
        }
        
        // Cannot test here for isConverted, because conversion could be in process and needs to correctly check ReplacementImageBase
		//if (true === $this->isConverted) {
        
            $extension = $mainFile->getMeta()->convertMeta()->getFileFormat();
            $replaceBase = $mainFile->getMeta()->convertMeta()->getReplacementImageBase(); 

// Seems this always needs to be checked against file, and use imagebase if this is in the convertmeta. 
			//if ($is_main_file)
            //{
               // $imageBase = $mainFile->getMeta()->convertMeta()->getReplacementImageBase(); 
                //$extension = $mainFile->getMeta()->convertMeta()->getFileFormat();

                if (false === is_null($replaceBase) && strlen(trim($replaceBase)) > 0)
                {
                   //  $imageBase = $sourceFile->getFileBase(); 
                   if ($is_main_file)
                    {
                        $backupFileName = $replaceBase . '.' . $extension; 
                    }
                    else
                    {
                        $backupFileName = str_replace($mainFileBase, $replaceBase, $sourceFile->getFileName());
                    }
                }
                elseif (false === is_null($extension) && strlen($extension) > 0)
                {
                    $backupFileName = $sourceFile->getFileBase() . '.' . $extension; 
                }
                else  // No replaceBase. 
                {
                    $backupFileName = $sourceFile->getFileName();
                }
		//}
        /*else
        {
            // This happens when the conversion is in progress. ReplaceBase compensates for Unique Filename. 
            if (false !== $replaceBase)
            {
                $backupFileName = $replaceBase . '.' . $sourceFile->getExtension();
            }
            else
            {
                $backupFileName = $sourceFile->getFileName();
            }
            
        } */

        return $backupFileName; 
	}


} // class 