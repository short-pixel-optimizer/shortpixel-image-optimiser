<?php
namespace ShortPixel\Model\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\Backup\BackupController as BackupController;
use ShortPixel\Model\Image\ImageModel;

 // Model to keep the backups of one item with many variables into one piece.  This should be the whole backup for one image item 
abstract class BackupModel
{

    protected $backup_files = []; 
    protected $full_backup_loaded = false; 
    protected $backupDirectory;
    protected $statusCode = 0; 

    protected $controller; 
    protected $mediaItem; 

    protected $isConverted; 

    abstract protected function getBackupDirectory($create = false);
    abstract public function createBackupFile(ImageModel $sourceFile);
    abstract public function restore(ImageModel $sourceFile);
    abstract public function hasBackup(ImageModel $sourceFile, $strict = false) : bool; 
    abstract public function onDelete(ImageModel $sourceFile) : bool;

    /* Implement below functions, these things can be done all at the same time. Use Model as 'all' loop. */
    abstract protected function loadAll(); 

    abstract public function getBackupData(); 

    const STATUS_IGNORED = 1; 
    const STATUS_COPIED = 2; 
    const STATUS_BACKUP_OK = 3; 
    
    const ERR_COPY_FAILED = -1; 
    const ERR_BACKUP_EXISTS = -2; 

    public function __construct(BackupController $controller, ImageModel $mediaItem)
    {
        $this->controller = $controller; 
        $this->mediaItem = $mediaItem;      

        $this->isConverted = $this->mediaItem->getMeta()->convertMeta()->isConverted();

    }

    	/** Function returns the filename for the backup.  This is an own function so it's possible to manipulate backup file name if needed, i.e. conversion or enumeration */
	/*public function getBackupFileName()
	{
        // This can't be mediaItem directly, needs to either use main / thumbs or whatever is requested here. 
		 return $this->mediaItem->getFileName();
	} */

    public function __get($name)
    {
         if (isset($this, $name))
         {
             return $this->$name; 
         }
         else   
         {
             return null;
         }
    }
    
    public function needsRegenerate() : bool
    {
         foreach($this->backup_files as $name => $fileAr)
         {
              if (true === $fileAr[$name]['has_backup'] && false === $fileAr[$name]['has_own_file'] )
              {
                return true; 
              }
         }
         return false; 

    }

    /** Function returns the filename for the backup.  This is an own function so it's possible to manipulate backup file name if needed, i.e. conversion or enumeration */
	public function getBackupFileName(ImageModel $sourceFile)
	{
        $is_main_file = $sourceFile->get('is_main_file'); 


        // NOTE -- Based on that in old source  this first statement never possible, false == mainfile, so commented. 
        /*
        $mainFile = (true === $is_main_file) ? $sourceFile : $this->mediaItem;
		if (false === $mainFile) {
			return $sourceFile->getFileName();
		} 
        */
        // Assertion here that for convert-types, there is no scaled- happening
        $mainFile = (true === $is_main_file) ? $sourceFile : $this->mediaItem;

		if ($mainFile->getMeta()->convertMeta()->getReplacementImageBase() !== false) {
			if ($this->is_main_file)
				return $mainFile->getMeta()->convertMeta()->getReplacementImageBase() . '.' . $sourceFile->getExtension();
			else {
				//					 $fileBaseNoSize =
				$name = str_replace($mainFile->getFileBase(), $mainFile->getMeta()->convertMeta()->getReplacementImageBase(), $this->getFileName());

				return $name;
			}
		}
        else
        {
            return $sourceFile->getFileName();
        }


	}


} // class 