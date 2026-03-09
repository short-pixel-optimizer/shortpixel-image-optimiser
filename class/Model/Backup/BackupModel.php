<?php
namespace ShortPixel\Model\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\Backup\BackupController;
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
    abstract public function createBackupFile(ImageModel $sourceFile) : bool;
    abstract public function restore(ImageModel $sourceFile);
    abstract public function hasBackup(ImageModel $sourceFile, $strict = false) : bool; 
    abstract public function onDelete(ImageModel $sourceFile) : bool;
    abstract public function getBackupFile(ImageModel $sourceFile);
    abstract public function getMainBackupFile(); 

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

    /** Function returns the filename for the backup.  This is an own function so it's possible to manipulate backup file name if needed, i.e. conversion or enumeration */
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
        // Assertion here that for convert-types, there is no scaled- happening
        $mainFile = $this->mediaItem;

		if (true === $this->isConverted) {
        
            $extension = $mainFile->getMeta()->convertMeta()->getFileFormat();
            $replaceBase = $mainFile->getMeta()->convertMeta()->getReplacementImageBase(); 

// Seems this always needs to be checked against file, and use imagebase if this is in the convertmeta. 
			//if ($is_main_file)
            //{
               // $imageBase = $mainFile->getMeta()->convertMeta()->getReplacementImageBase(); 
                //$extension = $mainFile->getMeta()->convertMeta()->getFileFormat();

                if (strlen(trim($replaceBase)) > 0)
                {
                   //  $imageBase = $sourceFile->getFileBase(); 
                   if ($is_main_file)
                    {
                        $backupFileName = $replaceBase . '.' . $extension; 
                    }
                    else
                    {
                        $backupFileName = str_replace($mainFile->getFileBase(), $replaceBase, $sourceFile->getFileName());
                    }
                }
                elseif (strlen($extension) > 0)
                {
                    $backupFileName = $sourceFile->getFileBase() . '.' . $extension; 
                }
                else  // This probably should not happen.
                {
                    $backupFileName = $sourceFile->getFileName();
                }


		}
        else
        {
            $backupFileName = $sourceFile->getFileName();
        }



        return $backupFileName; 
	}


} // class 