<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


 // Model to keep the backups of one item with many variables into one piece.  This should be the whole backup for one image item 
abstract class BackupModel
{

//    protected $backup_files = []; 

//    protected $type; 
//    protected $id; 

    protected $controller; 
    protected $mediaItem; 

    abstract function create();
    abstract function restore();
    abstract function hasBackup(); 
    

    public function __construct($controller, $mediaItem)
    {
        $this->controller = $controller; 
        $this->mediaItem = $mediaItem;      
    }


    




}