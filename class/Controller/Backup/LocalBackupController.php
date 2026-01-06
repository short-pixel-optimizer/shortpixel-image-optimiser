<?php
namespace ShortPixel\Controller\Backup;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


class LocalBackupController extends BackupController
{
    //private $backupDirectory; // main backup directory location ;

    protected function autoRemoveBackups()
    {

        $timestamp = \wpSPIO()->settings()->autoRemoveBackupsTimestamp; 

        /* @todo Since the backups are not stored in the database, the strategy for local backups could be as following: 
            1. Determine year, month from the timestamp and search for this directory and 'older' directories. 
            2. If month is WAY before the timestamp removal, dump the whole directory. 
            3. The month that is IN the timestamp, check all files. 
            4. Perhaps better is to have the timestamp only allow whole months / 6 months/ 1 year as params and dump dir?
        */

        return false; 

    }

}