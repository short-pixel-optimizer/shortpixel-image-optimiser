<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger as Log;

class ShortPixelController
{
  protected static $controllers = array();
  protected $shortPixel;

  protected $data = array(); // data array for usage with databases data and such

  protected $postData = array(); // data coming from form posts.
  protected $is_form_submit = false;

  protected $view; // object to use in the view.

  protected $template = null; // template name to include when loading.

  public static function init()
  {
    foreach (get_declared_classes() as $class) {
      if (is_subclass_of($class, \ShortPixelTools::namespaceit('shortPixelController') ))
        self::$controllers[] = $class;
    }
  }

  /* Static function to use for finding a associated controller within the WP page ecosystem
  *
  *  e.g. My page path in Wp-admin is bulk-restore-all, it can autofind needed controller ( and view )
  */
  public static function findControllerbySlug($name)
  {
      foreach(self::$controllers as $className)
      {
        if (! isset($className::$slug)) // controllers not connected by slugs
          continue;

        if ($className::$slug == $name)
        {
          return $className; // found!
        }
      }
  }

  public function __construct()
  {
    $this->view = new \stdClass;
    // Basic View Construct
    $this->view->notices =  null; // Notices of class notice, for everything noticable
    $this->view->data = null;  // Data(base), to separate from regular view data

    if (isset($_POST) && count($_POST) > 0)
    {
      $this->is_form_submit = true;
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

  /** Loads a view
  *
  *
  */
  public function loadView($template = null)
  {
      if (is_null($this->template) && is_null($template))
      {
        // error
        return false;
      }
      // load either param or class template.
      $template = (is_null($template)) ? $this->template : $template;

      $view = $this->view;
      $controller = $this;

      $template_path = \ShortPixelTools::getPluginPath() . 'class/view/' . $template  . '.php';
      if (file_exists($template_path))
      {
        include($template_path);
      }
      else {
        Log::addError("View $template could not be found in " . $template_path);
      }

  }

  /** Loads the Model Data Structure upon request
  *
  * @param string $name Name of the model
  */
  public function loadModel($name){
     $path = \ShortPixelTools::getPluginPath() . 'class/model/' . $name . '_model.php';

     if(file_exists($path)){
          require($path);
     }
     else {
       Log::addError('Model $name could not be found');
     }
}

  /** Accepts POST data and applies sanitization to it.
  * @param array $post POST data
  */
  protected function processPostData($post)
  {
    if (is_null($this->model))
    {
      foreach($post as $name => $value )
      {
        $this->postData[sanitize_text_field($name)] = sanitize_text_field($value);
        return true;
      }
    }

    $model = $this->model;
    foreach($post as $name => $value)
    {
        $value = $model->sanitize($name, $value);
        if ($value !== null)
          $this->postData[$name] = $value;
        else {
          Log::addWarn("Provided field $name not part of model");
        }

    }


  }





}
