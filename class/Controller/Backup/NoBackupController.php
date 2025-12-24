<?php
namespace ShortPixel\Controller\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


// Backup Controller that can check / restore, but should not create new ones.
class NoBackupController extends LocalBackupController
{
    private $backupDirectory; // main backup directory location ;


}

