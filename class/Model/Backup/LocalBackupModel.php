<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


class LocalBackupModel extends BackupModel
{


    // This must be able to create backup for images one-by-one. 
     public function create($name = 'full')
     {

     }

     // This one should probably do the whole procedure. 
     public function restore()
     {

     }

     public function hasBackup()
     {

     }

}