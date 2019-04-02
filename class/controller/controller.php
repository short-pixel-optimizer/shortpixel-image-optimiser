<?php

namespace ShortPixel;

class ShortPixelController
{
  protected static $controllers = array();

  protected $data = array(); // data array for usage with databases data and such
  protected $postData = array(); // data coming from form posts.
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
    if (isset($_POST) && count($_POST) > 0)
    {
      $this->processPostData($_POST);
    }
  }

  /** Meant as a temporary glue method between all the shortpixel methods and the newer structures
  *
  * @param Object $pixel WPShortPixel instance.
  */

  public function setShortPixel($pixel)
  {
    $this->shortPixel = $pixel;
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

  protected function processPostData($post)
  {
    // most likely to easy .
    foreach($post as $name => $data )
    {
      $this->postData[sanitize_text_field($name)] = sanitize_text_field($data);
    }
  }





}
