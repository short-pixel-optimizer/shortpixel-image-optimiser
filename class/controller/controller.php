<?php

namespace ShortPixel;

class ShortPixelController
{
  protected static $controllers = array();

  protected $data = array(); // data array for usage with databases data and such
  protected $layout; // object to use in the view.

  protected $template = null; // template name to include when loading.

  public static function init()
  {
    foreach (get_declared_classes() as $class) {
      if (is_subclass_of($class, \ShortPixelTools::namespaceit('shortPixelController') ))
        self::$controllers[] = $class;
    }
  }

  public static function findControllerbySlug($name)
  {
      foreach(self::$controllers as $className)
      {
        if ($className::$slug == $name)
        {
          return $className; // found!
        }
      }
  }

  public function __construct()
  {
    $this->layout = new \stdClass;
  }


  public function loadView()
  {
      if (is_null($this->template))
      {
        // error
        return false;
      }

      $layout = $this->layout;
      $controller = $this;

      $template_path = \ShortPixelTools::getPluginPath() . 'class/view/' . $this->template  . '.php';
      if (file_exists($template_path))
        include($template_path);

  }





}
