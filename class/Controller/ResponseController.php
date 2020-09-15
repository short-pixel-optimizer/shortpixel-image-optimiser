<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\ResponseModel as ResponseModel;

class ResponseController
{

    protected static $responses = array();


    public static function add()
    {
        $model = new ResponseModel();
        self::$responses[] = $model;
        return $model;
    }

    public static function getAll()
    {
        return self::$responses;
    }

    public static function clear()
    {
        self::$responses = array();
    }

    // @internal Should not be used.
    public static function updateModel(ResponseModel $model)
    {
        foreach(self::$responses as $index => $response)
        {
           if (spl_object_id($response) == spl_object_id($model))
           {
              self::$responses[$index] = $model;
              return $model;
           }
        }

    }
    // offer filters based on status / priority here.


}
