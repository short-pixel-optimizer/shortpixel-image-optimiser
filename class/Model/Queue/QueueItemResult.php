<?php 
namespace ShortPixel\Model\Queue;

if (!defined('ABSPATH')) {
   exit; // Exit if accessed directly.
}

// Handler for QueueItem Data stuff

use JsonSerializable;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class QueueItemResult implements JsonSerializable
{

   protected $item_id; 
   protected $is_done = false;  // Flag is whole item is done with, finish. 
   protected $is_error = false;  // If there is an error in this item 

   protected $apiStatus; 
   protected $message; 
   protected $file;  // should probably be merged these two.
   protected $files;
   protected $fileStatus;
   protected $filename; // @todo figure out why this is here.
   protected $error;  // might in time better be called error_code or so
   protected $new_attach_id; // new attach id for background remove.
   protected $success; // new
   protected $improvements; // Percentual improvemens of all thumbs after optimization.
   protected $original; // Link to original image for bulk view 
   protected $optimized; // Link to optimized image for bulk view. 
   protected $redirect; // Redirection for background remove etc 
   protected $queueType; // OptimizeController but (?) usage
   protected $kblink;  // Link to Knowledge base for error code. 
   protected $data; // Is returnDataList returned by apiController. (array)
   protected $apiName; // NAme of the handling api, for JS / Response to show different results.
   protected $remote_id; 
   protected $aiData;   // Returning AI Data
   

   public function __construct($item_id)
   {
        $this->item_id = $item_id; 
   }

   public function __get($name)
   {
       if (property_exists($this, $name))
       {
           $value = $this->$name; 

         
           return $value;
       }
       else
       {
           Log::addWarn('QueueItemResult Field requested not foudn: ' . $name);
       }
       return null;
   }

   public function __set($name, $value)
   {
       if (property_exists($this, $name))
       {
            $this->$name = $value; 
       }             
       else
       {
            Log::addWarn('QueueItemResult Field not exists - ' . $name);
       }
       
   }

   public function remove($name)
   {
         if (property_exists($this, $name))
         {
            $this->$name = null;
         }
   }

   public function jsonSerialize(): mixed
   {
        return $this->forReturn();
   }

   public function forReturn()
   {
      $vars = get_object_vars($this);
      $vars = array_filter($vars, ['\ShortPixel\Helper\UtilHelper','arrayFilterNullValues']);
      return (object) $vars; 
   }

} // Class 
