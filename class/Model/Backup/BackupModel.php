<?php
namespace ShortPixel\Model\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

 // Model to keep the backups of one item with many variables into one piece.  This should be the whole backup for one image item 
abstract class BackupModel
{

    protected $backup_files = []; 
    protected $full_backup_loaded = false; 
    protected $backupDirectory;

    protected $controller; 
    protected $mediaItem; 

    abstract function getBackupDirectory($create = false);
    abstract function createBackupFile(ImageModel $sourceFile);
    abstract function restore(ImageModel $sourceFile);
    abstract function hasBackup(ImageModel $sourceFile); 

    /* Implement below functions, these things can be done all at the same time. Use Model as 'all' loop. */
    abstract protected function loadAll(); 
    abstract public function restoreAll(); 
    abstract public function onDeleteAll(); 



    public function __construct(BackupController $controller, ImageModel $mediaItem)
    {
        $this->controller = $controller; 
        $this->mediaItem = $mediaItem;      
    }

    	/** Function returns the filename for the backup.  This is an own function so it's possible to manipulate backup file name if needed, i.e. conversion or enumeration */
	/*public function getBackupFileName()
	{
        // This can't be mediaItem directly, needs to either use main / thumbs or whatever is requested here. 
		 return $this->mediaItem->getFileName();
	} */



    




}