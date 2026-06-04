<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\AccessModel as AccessModel;

/**
 * Base controller for view-related functionality.
 *
 * Extends Controller to add view rendering, POST data handling, nonce verification,
 * and HTML output helpers. Most admin page controllers extend this class.
 *
 * @package ShortPixel
 */
class ViewController extends Controller
{
 // protected static $controllers = array();

	/**
	 * Tracks which view templates have already been included to prevent double-loading.
	 *
	 * @var string[]
	 */
	protected static $viewsLoaded = array();

	/**
	 * Singleton instance of the controller.
	 *
	 * @var static|null
	 */
  protected static $instance;

	/** @var mixed|null Connected model instance. */
  protected $model; // connected model to load.

	/** @var string|null Template file name (without .php extension) to include when loading the view. */
  protected $template = null; // template name to include when loading.

	/** @var array<string, mixed> Data array for passing database data and other values to the view. */
  protected $data = []; // data array for usage with databases data and such

	/** @var array<string, mixed> Sanitized data received from form POST submissions. */
  protected $postData = []; // data coming from form posts.

	/**
	 * Optional map of POST field names to model field names.
	 * Keys are the incoming POST names; values are the corresponding model field names.
	 *
	 * @var array<string, string>|null
	 */
  protected $mapper; // Mapper is array of View Name => Model Name. Convert between the two

	/** @var bool Whether a form was submitted in the current request. */
  protected $is_form_submit = false; // Was the form submitted?

	/** @var \stdClass View data object passed into included template files. */
  protected $view; // object to use in the view.

	/** @var string|null URL of the admin page this controller manages, used for redirects. */
  protected $url; // if controller is home to a page, sets the URL here. For redirects and what not.

	/** @var string Nonce action name used when verifying form submissions. */
  protected $form_action = 'sp-action';

  public static function init()
  {

  }

  public function __construct()
  {
		parent::__construct();
    $this->view = new \stdClass;
    // Basic View Construct
    $this->view->notices =  null; // Notices of class notice, for everything noticable
    $this->view->data = null;  // Data(base), to separate from regular view data

  }

	/**
	 * Returns the singleton instance of the calling class, creating it if necessary.
	 *
	 * Uses late static binding so subclasses each maintain their own instance.
	 *
	 * @return static The singleton instance.
	 */
	public static function getInstance() {
    if (is_null(static::$instance)) {
        static::$instance = new static();
    }

    return static::$instance;
}

  /**
   * Verifies an incoming form POST against the controller's nonce.
   *
   * When a valid POST is detected, optionally calls processPostData() to sanitize
   * and store the submitted fields. Returns false and terminates execution on a
   * hard nonce failure; returns true silently when no POST data is present.
   *
   * @param bool $processPostData Whether to call processPostData() on valid submission. Default true.
   * @return bool True when no POST or POST is valid; false on nonce mismatch with ajaxSave present.
   */
  protected function checkPost($processPostData = true)
  {

		if(count($_POST) === 0) // no post, nothing to check, return silent.
		{
			return true;
		}
    elseif (! isset($_POST['sp-nonce']) || ! wp_verify_nonce( sanitize_key($_POST['sp-nonce']), $this->form_action))
    {
      // Obscure issue. Detected other plugin that adds information to $_POST without an actual form submit, which would trigger the nonce check on the settings page. In case this happens, be lenient.
      if ( ! isset($_POST['ajaxSave']) || ! isset($_POST['action']) )
      {
         return false;
      }
      Log::addInfo('Check Post fails nonce check, action : ' . $this->form_action, array($_POST) );
			wp_die('Nonce Failed');
      return true;
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

	/**
	 * Returns the singleton AccessModel instance for permission checks.
	 *
	 * @return AccessModel
	 */
	public function access()
	{
		 return AccessModel::getInstance();
	}

  /**
   * Loads and includes a view template file from the class/view/ directory.
   *
   * If $unique is true (default), each template is included only once per request.
   * Passes $this->view and $this as local variables ($view and $controller) to the template.
   *
   * @param string|null $template Relative template name (without .php). Falls back to $this->template.
   * @param bool        $unique   Whether to prevent loading the same template more than once. Default true.
   * @param array       $args     Additional arguments made available as $view->template_args inside the template.
   * @return bool|void False when no valid template name is available; void otherwise.
   */
  public function loadView($template = null, $unique = true, $args = [])
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
      $view->template_args = $args; // local pass only for this view, useful for snippets, not main controllers.
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

      }

  }

  /** Manually add data to this viewcontroller
   *
   * @param array $data  Data to add.
   * @return void
   */
  public function addData($data)
  {
      $this->data = array_merge($this->data, $data);
  }

  /** Loads a view and then returns it as html string. Handy for passing back snippets in JSON and other things.
   *
   * @param string $template Name of template
   * @return string HTML string of view loaded.
   */
  public function returnView($template = null)
  {
     $bool = ob_start();
     $html = '';

     if (true === $bool)
     {
        $this->loadView($template, false);
        $html = ob_get_contents();
        ob_end_clean();
     }
     else
     {
       Log::addError('Output buffer failed requesting returnView!' . $template);
     }

     return $html;
  }

  /**
   * Outputs an inline help icon linking to external documentation.
   *
   * @param string $url The documentation URL to link to (will be escaped).
   * @return void
   */
  protected function printInlineHelp($url)
  {

      $output = '<i class="documentation dashicons dashicons-editor-help" data-link="' . esc_url($url).  '"></i>';
      echo $output;

  }

  /**
   * Renders and echoes a toggle switch button element.
   *
   * Accepts an array of arguments that control the name, checked state, label,
   * CSS classes, data attributes, and disabled state of the rendered switch.
   *
   * @param array $args {
   *     Optional. Overrides for the switch button defaults.
   *
   *     @type string       $name         Input name attribute. Default ''.
   *     @type bool         $checked      Whether the switch is checked. Default false.
   *     @type string       $label        Label text displayed beside the switch. Default ''.
   *     @type string|false $switch_class CSS class for the outer <switch> element. Default false.
   *     @type string       $input_class  CSS class for the <input> element. Default 'switch'.
   *     @type array        $data         Additional data attributes as pre-formatted strings. Default [].
   *     @type bool         $disabled     Whether the switch is disabled. Default false.
   * }
   * @return void
   */
  protected function printSwitchButton($args)
  {
    $defaults = array(
        'name' => '',
        'checked' => false,
        'label' => '',
        'switch_class' => false,
        'input_class' => 'switch',
        'data' => [],
        'disabled' => false,
        'tooltip_link' => '',
    );

    $args = wp_parse_args($args, $defaults);

    $switchclass = ($args['switch_class'] !== false) ? 'class="' . $args['switch_class'] . '"' : '';
    $inputclass = $args['input_class'];
    $checked = checked($args['checked'], true, false);
    $name = esc_attr($args['name']);
    $label = esc_attr($args['label']);

    $tooltip = '';
    if (! empty($args['tooltip_link'])) {
        $tooltip = sprintf('<i class="documentation dashicons dashicons-editor-help" data-link="%s"></i>', esc_attr($args['tooltip_link']));
    }

    $data = implode(' ', $args['data']);

    $disabled = $args['disabled'];
    $disabled = (true === $disabled) ? 'disabled' : '';

    $output = sprintf('<switch %s>
      <label>
        <input type="checkbox" class="%s" name="%s" value="1" %s %s %s>
        <div class="the_switch">&nbsp;</div>
        %s%s
      </label>
    </switch>', $switchclass, $inputclass, $name, $checked, $disabled, $data, $label, $tooltip);

    echo $output;
  }

  /** Accepts POST data, maps, checks missing fields, and applies sanitization to it.
  *
  * Applies any field name mappings defined in $this->mapper, then either sanitizes
  * each field via the connected model or falls back to sanitize_text_field() when no
  * model is set. The result is stored in $this->postData.
  *
  * @param array $post Raw POST data (typically $_POST).
  * @return array<string, mixed>|bool Sanitized post data array, or true if no model is set.
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
