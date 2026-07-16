<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\ApiKeyModel as ApiKeyModel;

/**
 * Manages access to the ShortPixel API key at runtime.
 *
 * Loads the key from ApiKeyModel on construction and exposes safe accessors.
 * The controller intentionally separates display-safe retrieval from the raw
 * key fetch — callers must use `forceGetApiKey()` only for internal API calls,
 * never for display. Follows the singleton pattern via `getInstance()`.
 *
 * @package ShortPixel\Controller
 */
class ApiKeyController extends \ShortPixel\Controller
{
    /** @var ApiKeyController|null Singleton instance. */
    private static $instance;

    /**
     * Instantiate the controller and immediately load the stored API key.
     */
    public function __construct()
    {
      $this->model = new ApiKeyModel();
      $this->load();
    }

    /**
     * Return the singleton instance, creating it on first call.
     *
     * @return ApiKeyController
     */
    public static function getInstance()
    {
        if (is_null(self::$instance))
           self::$instance = new ApiKeyController();

        return self::$instance;
    }

    /**
     * Delegate key loading to the underlying ApiKeyModel.
     *
     * @return void
     */
    public function load()
    {
      $this->model->loadKey();
    }

    /**
     * Return the underlying ApiKeyModel.
     *
     * @return ApiKeyModel
     */
		public function getKeyModel()
		{
			 return $this->model;
		}

    /**
     * Return the API key only when it is safe to display (not hidden).
     *
     * Returns false when the key is marked as hidden in the model, so it is
     * safe to pass the return value directly to templates.
     *
     * @return string|false The API key string, or false if the key is hidden.
     */
    public function getKeyForDisplay()
    {
       if (! $this->model->is_hidden())
       {
          return $this->model->getKey();
       }
       else
         return false;
    }

    /**
     * Return the raw API key regardless of hidden status.
     *
     * Warning: NEVER use this for displaying API keys. Only for internal functions
     * such as remote API calls.
     *
     * @return string The raw API key.
     */
    public function forceGetApiKey()
    {
      return $this->model->getKey();
    }

    /**
     * Return whether the current API key has been verified by the remote API.
     *
     * @return bool True when the key is verified, false otherwise.
     */
    public function keyIsVerified()
    {
       return $this->model->is_verified();
    }

    /**
     * Remove the stored API key via the model (e.g. on plugin uninstall).
     *
     * @return void
     */
		public function uninstall()
		{
			 $this->model->uninstall();
		}

    /**
     * Static entry point for plugin uninstall routines.
     *
     * Obtains the singleton and delegates to `uninstall()` so that WordPress
     * uninstall hooks do not need to manage controller state directly.
     *
     * @return void
     */
		public static function uninstallPlugin()
		{
			 $controller = self::getInstance();
			 $controller->uninstall();
		}

}
