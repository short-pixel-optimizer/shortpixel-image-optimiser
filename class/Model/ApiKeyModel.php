<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\QuotaController as QuotaController;

class ApiKeyModel extends \ShortPixel\Model
{

  // variables
  protected $apiKey;
  protected $apiKeyTried;  // stop retrying the same key over and over if not valid.
  protected $verifiedKey;

  // states
  // key is verified is set by checkKey *after* checks and validation
  protected $key_is_verified = false; // this state doesn't have to be the same as the verifiedKey field in DB.
  protected $key_is_empty = false;
  protected $key_is_constant = false;
  protected $key_is_hidden = false;

  protected static $notified = array();


  protected $legacy_model = array(
       'apiKey' => array('s' => 'string',
                          'key' => 'wp-short-pixel-apiKey',
                        ),
       'apiKeyTried' => array('s' => 'string',
                           'key' => 'wp-short-pixel-apiKeyTried'
       ),
       'verifiedKey' => array('s' => 'boolean',
                          'key' => 'wp-short-pixel-verifiedKey',
                       ),

  );

	protected $model = array(
       'apiKey' => array('s' => 'string',
       ),
       'apiKeyTried' => array('s' => 'string',
       ),
       'verifiedKey' => array('s' => 'boolean',
       ),
  );

	private $option_name =  'spio_key';

  /** Constructor. Check for constants, load the key */
  public function __construct()
  {
    $this->key_is_constant = (defined("SHORTPIXEL_API_KEY")) ? true : false;
    $this->key_is_hidden = (defined("SHORTPIXEL_HIDE_API_KEY")) ? (bool) SHORTPIXEL_HIDE_API_KEY : false;

  }

  /** Load the key from storage. This can be a constant, or the database. Check if key is valid.
  *
  */
  public function loadKey()
  {
 		$apikeySettings = get_option($this->option_name, null);

		if (is_null($apikeySettings))
		{
			$this->apiKey = get_option($this->legacy_model['apiKey']['key'], false);
	    $this->verifiedKey = get_option($this->legacy_model['verifiedKey']['key'], false);
	    $this->apiKeyTried = get_option($this->legacy_model['apiKeyTried']['key'], false);

				$apikeySettings = [
						'apiKey' => $this->apiKey,
						'verifiedKey' => $this->verifiedKey,
						'apiKeyTried' => $this->apiKeyTried,
				];
			 delete_option($this->legacy_model['apiKey']['key']);
			 delete_option($this->legacy_model['verifiedKey']['key']);
			 delete_option($this->legacy_model['apiKeyTried']['key']);

			 $this->update();
		}

		$this->apiKey = isset($apikeySettings['apiKey']) ? $apikeySettings['apiKey'] : '';
		$this->verifiedKey = $apikeySettings['verifiedKey'];
		$this->apiKeyTried = $apikeySettings['apiKeyTried'];


    if ($this->key_is_constant)
    {
        $key = SHORTPIXEL_API_KEY;
        if (isset($apikeySettings['apiKey']))
        {
            $this->apiKey = '';
            $this->update();
        }
        $this->apiKey = $key;
    }


    $valid = $this->checkKey($this->apiKey);

    return $valid;
  }

  protected function update()
  {
			$apikeySettings = [
					'apiKey' => trim($this->apiKey),
					'verifiedKey' => $this->verifiedKey,
					'apiKeyTried' => $this->apiKeyTried,
			];


			$res = update_option($this->option_name, $apikeySettings, true);
			return $res;
  }

  /** Resets the last APIkey that was attempted with validation
  *
  *  The last apikey tried is saved to prevent server and notice spamming when using a constant key, or a wrong key in the database without updating.
  */
  public function resetTried()
  {
    if (is_null($this->apiKeyTried))
    {
      return; // if already null, no need for additional activity
    }
    $this->apiKeyTried = null;
    $this->update();
    Log::addDebug('Reset Tried', $this->apiKeyTried);
  }

  /** Checks the API key to see if we have a validated situation
  *  @param $key String The 20-character ShortPixel API Key or empty string
  *  @return boolean Returns a boolean indicating valid key or not
  *
  * An Api key can be removed from the system by passing an empty string when the key is not hidden.
  * If the key has changed from stored key, the function will pass a validation request to the server
  * Failing to key a 20char string, or passing an empty key will result in notices.
  */
  public function checkKey($key)
  {
			$valid = false;
      if (is_null($key) || strlen($key) == 0)
      {
        // first-timers, redirect to nokey screen
        $this->checkRedirect(); // this should be a one-time thing.
        if($this->key_is_hidden) // hidden empty keys shouldn't be checked
        {
           $this->key_is_verified = $this->verifiedKey;
           return $this->key_is_verified;
        }
        elseif ($key != $this->apiKey)
        {
          Notice::addWarning(__('Your API Key has been removed', 'shortpixel-image-optimiser'));
          $this->clearApiKey(); // notice and remove;
          return false;
        }
        $valid = false;

      }
      elseif (strlen($key) <> 20 && $key != $this->apiKeyTried)
      {
        $this->NoticeApiKeyLength($key);
        Log::addDebug('Key Wrong Length: ' . $key);

				// Don't validate is wrong key is constant.
				if (false === $this->key_is_constant)
				{
        	$valid = $this->verifiedKey; // if we already had a verified key, and a wrong new one is giving keep status.
				}
      }
      elseif( ($key != $this->apiKey || ! $this->verifiedKey) && $key != $this->apiKeyTried)
      {
        Log::addDebug('Validate Key' . $key);
        $valid = $this->validateKey($key);
      }
      elseif($key == $this->apiKey) // all is fine!
      {
        $valid = $this->verifiedKey;
      }

      // if key is not valid on load, means not valid at all
      if (false === $valid)
      {
        $this->verifiedKey = false;
        $this->key_is_verified = false;
        $this->apiKeyTried = $key;
        $this->update();
      }
      else {
        $this->key_is_verified = true;
      }

      return $this->key_is_verified; // first time this is set! *after* this function
  }

  public function is_verified()
  {
      return $this->key_is_verified;
  }

  public function is_constant()
  {
      return $this->key_is_constant;
  }

  public function is_hidden()
  {
      return $this->key_is_hidden;
  }

  public function getKey()
  {
      return $this->apiKey;
  }

	public function uninstall()
	{
		 $this->clearApiKey();
	}

  protected function clearApiKey()
  {
    $this->key_is_empty = true;
    $this->apiKey = '';
    $this->verifiedKey = false;
    $this->apiKeyTried = '';
    $this->key_is_verified = false;

    AdminNoticesController::resetAPINotices();
    AdminNoticesController::resetQuotaNotices();
    AdminNoticesController::resetIntegrationNotices();

		// Remove them all
		delete_option($this->legacy_model['apiKey']['key']);
		delete_option($this->legacy_model['verifiedKey']['key']);
		delete_option($this->legacy_model['apiKeyTried']['key']);

    delete_option($this->option_name);

  }

  protected function validateKey($key)
  {
    Log::addDebug('Validating Key ' . $key);
    // first, save Auth to satisfy getquotainformation

    $quotaData = $this->remoteValidate($key);

    $checked_key = ($quotaData['APIKeyValid']) ? true : false;

     if (! $checked_key)
     {
			  Log::addError('Key is not validated', $quotaData['Message']);
        Notice::addError(sprintf(__('Error during verifying API key: %s','shortpixel-image-optimiser'), $quotaData['Message'] ));
     }
     elseif ($checked_key) {
        if (false === $this->is_constant())
        {
          $this->apiKey = $key;
        }
        $this->verifiedKey = $checked_key;
        $this->processNewKey($quotaData);
        $this->update();
     }
     return $this->verifiedKey;
  }

  /** Process some things when key has been added. This is from original wp-short-pixel.php */
  protected function processNewKey($quotaData)
  {

    //display notification
    $urlParts = explode("/", get_site_url());
    if( $quotaData['DomainCheck'] == 'NOT Accessible'){
        $notice = array("status" => "warn", "msg" => __("API Key is valid but your site is not accessible from our servers. Please make sure that your server is accessible from the Internet before using the API or otherwise we won't be able to optimize them.",'shortpixel-image-optimiser'));
        Notice::addWarning($notice);
    } else {
        if ( function_exists("is_multisite") && is_multisite() && !defined("SHORTPIXEL_API_KEY"))
            $notice = __("Great, your API Key is valid! <br>You seem to be running a multisite, please note that API Key can also be configured in wp-config.php like this:",'shortpixel-image-optimiser')
                . "<BR> <b>define('SHORTPIXEL_API_KEY', '". $this->apiKey ."');</b>";
        else
            $notice = __('Great, your API Key is valid. Please take a few moments to review the plugin settings before starting to optimize your images.','shortpixel-image-optimiser');

        Notice::addSuccess($notice);
    }

    //test that the "uploads"  have the right rights and also we can create the backup dir for ShortPixel
    if ( \wpSPIO()->filesystem()->checkBackupFolder() === false)
    {
        $notice = sprintf(__("There is something preventing us to create a new folder for backing up your original files.<BR>Please make sure that folder <b>%s</b> has the necessary write and read rights.",'shortpixel-image-optimiser'), WP_CONTENT_DIR . '/' . SHORTPIXEL_UPLOADS_NAME );
       Notice::addError($notice);
    }

    AdminNoticesController::resetAPINotices();
  }

  protected function NoticeApiKeyLength($key)
  {
      // repress double warning.
    if (isset(self::$notified['apilength']) && self::$notified['apilength'])
      return;

    $KeyLength = strlen($key);
    $notice =  sprintf(__("The API Key you provided has %s characters, but it should contain exactly 20 characters, using only letters and numbers.",'shortpixel-image-optimiser'), $KeyLength)
               . "<BR> <b>"
               . __('Please check that the API Key is the same as the one you received in your sign-up email.','shortpixel-image-optimiser')
               . "</b><BR> "
               . __('If this problem persists, please contact us at ','shortpixel-image-optimiser')
               . "<a href='mailto:help@shortpixel.com?Subject=API Key issues' target='_top'>help@shortpixel.com</a>"
               . __(' or ','shortpixel-image-optimiser')
               . "<a href='https://shortpixel.com/contact' target='_blank'>" . __('here','shortpixel-image-optimiser') . "</a>.";
    self::$notified['apilength'] = true;
    Notice::addError($notice);
  }

  // Does remote Validation of key. In due time should be replaced with something more lean.
  private function remoteValidate($key)
  {
   $qControl = QuotaController::getInstance();
   $quotaData = $qControl->remoteValidateKey($key);

   return $quotaData;

  }

  protected function checkRedirect()
  {
    $redirectedSettings =  \wpSPIO()->settings()->redirectedSettings;
    if(! \wpSPIO()->env()->is_ajaxcall && !$redirectedSettings && !$this->verifiedKey && (!function_exists("is_multisite") || ! is_multisite())) {
      \wpSPIO()->settings()->redirectedSettings = 1;
  //    $this->update();
      wp_safe_redirect(admin_url("options-general.php?page=wp-shortpixel-settings"));
      exit();
    }

}


}
