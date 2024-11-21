<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\AccessModel as AccessModel;


class ViewController extends Controller
{
 // protected static $controllers = array();
	protected static $viewsLoaded = array();

  protected static $instance;

  protected $model; // connected model to load.
  protected $template = null; // template name to include when loading.

  protected $data = array(); // data array for usage with databases data and such
  protected $postData = array(); // data coming from form posts.

  protected $mapper; // Mapper is array of View Name => Model Name. Convert between the two
  protected $is_form_submit = false; // Was the form submitted?

  protected $view; // object to use in the view.
  protected $url; // if controller is home to a page, sets the URL here. For redirects and what not.

  protected $form_action = 'sp-action';

  public static function init()
  {
	 /*
	 Not sure why this is here
	 foreach (get_declared_classes() as $class) {
      if (is_subclass_of($class, 'ShortPixel\Controller') )
        self::$controllers[] = $class;
		} */
  }

  public function __construct()
  {
		parent::__construct();
    $this->view = new \stdClass;
    // Basic View Construct
    $this->view->notices =  null; // Notices of class notice, for everything noticable
    $this->view->data = null;  // Data(base), to separate from regular view data

  }

	public static function getInstance() {
    if (is_null(static::$instance)) {
        static::$instance = new static();
    }

    return static::$instance;
}

  /* Check if postData has been submitted.
  * This function should always be called at any ACTION function ( load, load_$action etc ).
	*
  */
  protected function checkPost($processPostData = true)
  {

		if(count($_POST) === 0) // no post, nothing to check, return silent.
		{
			return true;
		}
    elseif (! isset($_POST['sp-nonce']) || ! wp_verify_nonce( sanitize_key($_POST['sp-nonce']), $this->form_action))
    {
      Log::addInfo('Check Post fails nonce check, action : ' . $this->form_action, array($_POST) );
			exit('Nonce Failed');
      return false;
    }
    elseif (isset($_POST) && count($_POST) > 0)
    {
      check_admin_referer( $this->form_action, 'sp-nonce' ); // extra check, when we are wrong here, it dies.

      $this->is_form_submit = true;
      if (true === $processPostData) // only processData on form save. 
      {
          $this->processPostData($_POST);
      }


    }
		return true;
	}

	public function access()
	{
		 return AccessModel::getInstance();
	}

  /** Loads a view
  *
  * @param String View Template in view directory to load. When empty will search for class attribute
  */
  public function loadView($template = null, $unique = true)
  {
      // load either param or class template.
      $template = (is_null($template)) ? $this->template : $template;

      if (is_null($template) )
      {
        return false;
      }
      elseif (strlen(trim($template)) == 0)
			{
        return false;
			}

      $view = $this->view;
      $controller = $this;

      $template_path = \wpSPIO()->plugin_path('class/view/' . $template  . '.php');
     	if (file_exists($template_path) === false)
			{
        Log::addError("View $template could not be found in " . $template_path,
        array('class' => get_class($this)));
      }
      elseif ($unique === false || ! in_array($template, self::$viewsLoaded))
      {
        include($template_path);
				self::$viewsLoaded[] = $template;
      }
      else {
        Log::addTemp("Not loading $template ? ");
      }

  }

  protected function printInlineHelp($url)
  {

      $output = '<i class="documentation dashicons dashicons-editor-help" data-link="' . esc_url($url).  '"></i>';
      echo $output;

  }

  protected function printSwitchButton($args)
  {
    $defaults = array(
        'name' => '',
        'checked' => false,
        'label' => '',
        'switch_class' => false,
        'data' => [],
        'disabled' => false,
    );

    $args = wp_parse_args($args, $defaults);

    $switchclass = ($args['switch_class'] !== false) ? 'class="' . $args['switch_class'] . '"' : '';
    $checked = checked($args['checked'], true, false);
    $name = esc_attr($args['name']);
    $label = esc_attr($args['label']);

    $data = implode(' ', $args['data']);
    $disabled = (true === $args['disabled']) ? 'disabled' : '';

    $output = sprintf('<switch %s>
      <label>
        <input type="checkbox" class="switch" name="%s" value="1" %s %s %s>
        <div class="the_switch">&nbsp;</div>
        %s
      </label>
    </switch>', $switchclass, $name, $checked, $disabled, $data, $label);

    echo $output;
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

    if (is_null($this->model) && is_null($model))
    {
      foreach($post as $name => $value )
      {
        $this->postData[sanitize_text_field($name)] = sanitize_text_field($value);
        return true;
      }
    }
    else
    {
      $this->postData = $this->model->getSanitizedData($post, false);
    }

    return $this->postData;

  }

  /** Sets the URL of the admin page */
  public function setControllerURL($url)
  {
    $this->url = $url;
  }



} // controller
