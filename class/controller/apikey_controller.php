<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

/* Main function of this controller is to load key on runtime
This should probably in future incorporate some apikey checking functions that shouldn't be in model.
*/
class ApiKeyController extends shortPixelController
{
    private static $instance;

    public function __construct()
    {
      $this->loadModel('apikey');
      $this->model = new apiKeyModel();
      $this->load();
    }

    public static function getInstance()
    {
        if (is_null(self::$instance))
           self::$instance = new ApiKeyController();

        return self::$instance;
    }

    // glue method.
    public function setShortPixel($pixel)
    {
      parent::setShortPixel($pixel);
      $this->model->shortPixel = $pixel;
    }

    public function load()
    {
      $this->model->loadKey();
    }

    public function getKeyForDisplay()
    {
       if (! $this->model->is_hidden())
       {
          return $this->model->getKey();
       }
       else
         return false;
    }

    public function keyIsVerified()
    {
       return $this->model->is_verified();
    }

}
