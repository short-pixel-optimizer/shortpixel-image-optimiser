<?php
namespace ShortPixel\Controller\Backup;

use ShortPixel\Model\File\DirectoryModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


class LocalBackupController extends BackupController
{
    //private $backupDirectory; // main backup directory location ;

    protected function autoRemoveBackups()
    {
        /* @todo Since the backups are not stored in the database, the strategy for local backups could be as following: 
            1. Determine year, month from the timestamp and search for this directory and 'older' directories. 
            2. If month is WAY before the timestamp removal, dump the whole directory. 
            3. The month that is IN the timestamp, check all files. 
            4. Perhaps better is to have the timestamp only allow whole months / 6 months/ 1 year as params and dump dir?
            5. If with periods, should also have some compat for non-month installations (?) 
        */

         $fs = \wpSPIO()->filesystem(); 
         $backupBaseDir = $this->getBackupBaseDirectory();
         $rootBackupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

         $backupSubdirs = $backupBaseDir->getSubDirectories();
         
         $period = $this->getPeriodAr();

         // Check all files in the root backupdir (in case of uploads in root)
         $this->checkFilesinDirectory($rootBackupDir, $period['date']);

         // @todo Rtturned date formats are string, so === compare can't happen if other is intval. Perhaps 
         foreach($backupSubdirs as $dir)
         {
            $dirName = $dir->getName();
            if (strlen($dirName) === 4 && $dirName < $period['year'])
            {
                Log::addWarn('Automatic Backup Removal, removing dir: ', $dir->getPath()); 
            }
            elseif(strlen($dirName) === 4 && $period['year'] === $dirName)
            {
                $this->checkRemoveMonth($dir->getSubDirectories(), $period['month']); 
                $this->checkFilesinDirectory($dir, $period['date']); 
            }
         }     

    }

    private function checkRemoveMonth($subdirs, $month)
    {
        foreach($subdirs as $subdir)
        {
             $name = $subdir->getName(); 

             // Every month number that is lower (older) than month
             if (strlen($name) == 2 && $name < $month)
             {
                 Log::addWarn('Automatic Backup Removal of month, removing ', $subdir->getFullPath()); 
             }     

        }
    }

    private function checkFilesinDirectory(DirectoryModel $directory, $date)
    {
        $files = $directory->getFiles(['date_created_older' => $date]);
        foreach($files as $fileObj)
        {
            Log::addWarning('Removing file ' . $fileObj->getFullPath());
        }
        
    }

    private function getPeriodAr()
    {
        $settings = \wpSPIO()->settings(); 
        $removePeriod = $settings->autoRemoveBackupsPeriod; 

        $dateNow = new \DateTime(); 

        switch($removePeriod)
        {
             case 'month':
                $interval = new \DateInterval('P1M');
             break; 
             case '3month':
                $interval = new \DateInterval('P3M');
             break; 
             case '6month':
                $interval = new \DateInterval('P6M');
             break;
             case '1year':
                $interval = new \DateInterval('P1Y');
             break; 
             case '2year':
                $interval = new \DateInterval('P2Y');
             break;
             case '5year': 
                $interval = new \DateInterval('P5Y');
             break; 
             default:
                $interval = null; 
             break; 
        }

        if (is_null($interval))
        {
             return null; 
        }

        $dateNow->sub($interval); 

        // @todo Add a sanity check here if month / year returns are reliable. 
        $month = $dateNow->format('m');
        $year = $dateNow->format('Y');

        return ['month' => $month, 'year' => $year, 'date' => $dateNow->getTimestamp()]; 

    }

    private function getBackupBaseDirectory()
    {
        $fs = \wpSPIO()->filesystem(); 
        $wpUploadBase = $fs->getWPUploadBase(); 
        $rel = $wpUploadBase->getRelativePath();

        $backupBaseDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER . '/' . $rel); 

        return $backupBaseDir;
    }

} // Class 
