<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;



class ApiController
{
  const STATUS_SUCCESS = 1;
  const STATUS_UNCHANGED = 0;
  const STATUS_ERROR = -1;
  const STATUS_FAIL = -2;
  const STATUS_QUOTA_EXCEEDED = -3;
  const STATUS_SKIP = -4;
  const STATUS_NOT_FOUND = -5;
  const STATUS_NO_KEY = -6;
  const STATUS_RETRY = -7;
  const STATUS_SEARCHING = -8; // when the Queue is looping over images, but in batch none were found.
  const STATUS_QUEUE_FULL = -404;
  const STATUS_MAINTENANCE = -500;

  const ERR_FILE_NOT_FOUND = -2;
  const ERR_TIMEOUT = -3;
  const ERR_SAVE = -4;
  const ERR_SAVE_BKP = -5;
  const ERR_INCORRECT_FILE_SIZE = -6;
  const ERR_DOWNLOAD = -7;
  const ERR_PNG2JPG_MEMORY = -8;
  const ERR_POSTMETA_CORRUPT = -9;
  const ERR_UNKNOWN = -999;

  public function __construct()
  {

  }


  public static function getInstance()
  {
     if (is_null(self::$instance))
       self::$instance = new ApiController();

      return self::$instance;
  }


  public function processMediaItem($item)
  {
      var_dump($item);
  }


  private function getSetting($name)
  {
     return \wpSPIO()->settings()->$name;
     
  }




} // class
