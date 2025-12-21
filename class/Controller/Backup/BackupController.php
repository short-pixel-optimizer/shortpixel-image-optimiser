<?php
namespace ShortPixel\Controller;

use ShortPixel\Model\File\FileModel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}



/** 
BackupController, need to implement the following : 

1) Get and check Backup Directories. Controll all hooks, actions, filters for directories here (with FS) 
2) CreateBackup, CheckBackup, RestoreBackup functions with a ImageModel(FileModel) Parameter here 
3) RemoveBackups older than X functionality and Cron Handler. 
4) Implement it's possible to store only main file as backup (with checks and what not) and if so, on restore regenerate the thumbnails back . Need checking for special filetypes such as pnh, heic etc 
5) Should pave the way for remote/cloud backups as well(?)

*/


abstract class BackupController 
{
    protected static $instance;

    
    protected static $models = []; 


    public function __construct()
    {
         
    }

    public static function getBackupController()
    {
      $settings = \wpSPIO()->settings; 

      if (is_null(self::$instance))
      {
        if (false === $settings->backupImages)
        {
          self::$instance = new NoBackupController();  
        } 
        else
        {
          self::$instance = new LocalBackupController();
        }
        // Here check with settings which backup method is active 

      }

      return self::$instance; 
    }


    public function getModel($mediaItem)
    {
        $id = $mediaItem->get('id');
        $type = $mediaItem->get('type');
        
        if (! isset(self::$models[$type]) || ! isset(self::$models[$type][$id]))
        {
            $model = new BackupModel(self::$instance, $mediaItem, $type);

            if (! isset(self::$models[$type]))
            {
              self::$models[$type] = []; 
            }
            self::$models[$type][$id] = $model; 
        }
        
        return self::$models[$type][$id];
    }

    public function withItem($mediaItem)
    {
        
    }



} // class