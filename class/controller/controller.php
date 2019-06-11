<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger as Log;

class ShortPixelController
{
  protected static $controllers = array();
  protected static $modelsLoaded = array(); // don't require twice, limit amount of require looksups..

  protected $shortPixel;

  protected $model;
  protected $template = null; // template name to include when loading.
  protected $data = array(); // data array for usage with databases data and such
  protected $postData = array(); // data coming from form posts.
  protected $mapper; // Mapper is array of View Name => Model Name. Convert between the two
  protected $is_form_submit = false;
  protected $view; // object to use in the view.
  protected $url; // if controller is home to a page, sets the URL here. For redirects and what not.

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


  }

  /* Check if postData has been submitted.
  * This function should always be called at any ACTION function ( load, load_$action etc ).
  */
  protected function checkPost()
  {
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
    $this->shortPixel = $pixel; // notice the capital, case-sensitive!
  }

  /** Loads a view
  *
  *
  */
  public function loadView($template = null)
  {
      if (strlen(trim($template)) == 0)
        $template = null;

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
        Log::addError("View $template could not be found in " . $template_path,
        array('class' => get_class($this), 'req' => $_REQUEST));
      }

  }

  /** Loads the Model Data Structure upon request
  *
  * @param string $name Name of the model
  */
  protected function loadModel($name){
     $path = \ShortPixelTools::getPluginPath() . 'class/model/' . $name . '_model.php';

     if (! in_array($name, self::$modelsLoaded))
     {
       self::$modelsLoaded[] = $name;
       if(file_exists($path)){
            require_once($path);
       }
       else {
         Log::addError('Model $name could not be found');
       }
     }
}

  /** Accepts POST data, maps, checks missing fields, and applies sanitization to it.
  * @param array $post POST data
  */
  protected function processPostData($post)
  {

    // If there is something to map, map.
    if ($this->mapper && is_array($this->mapper) && count($this->mapper) > 0)
    {
      foreach($this->mapper as $item => $replace)
      {
        if ( isset($post[$item]))
        {
          $post[$replace] = $post[$item];
          unset($post[$item]);
        }
      }
    }

    if (is_null($this->model))
    {
      foreach($post as $name => $value )
      {
        $this->postData[sanitize_text_field($name)] = sanitize_text_field($value);
        return true;
      }
    }

    $model = $this->model;
    $this->postData = $model->getSanitizedData($post);

    return $this->postData;

  }

  public function setControllerURL($url)
  {
    $this->url = $url;
  }

} // controller
