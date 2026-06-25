<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class QuotaController
{
    protected static $instance;
    const CACHE_NAME = 'quotaData';

    protected $quotaData;

    /** Singleton instance
    * @return Object QuotaController object
    */
    public static function getInstance()
    {
      if (is_null(self::$instance))
        self::$instance = new QuotaController();

      return self::$instance;
    }


    /**
     * Return if account has any quota left
     * @return boolean Has quota left
     */
    public function hasQuota()
    {
      $settings = \wpSPIO()->settings();

      if ($settings->quotaExceeded)
	  {
        return false;
	  }
      return true;

    }

    /**
     * Retrieves QuotaData object from cache or from remote source
     * @return array The quotadata array (remote format)
     */
    private function getQuotaData()
    {
        if (! is_null($this->quotaData))
          return $this->quotaData;

        $cache = new CacheController();
        $cacheData = $cache->getItem(self::CACHE_NAME);

        if (! $cacheData->exists() )
        {
            $quotaData = $this->getRemoteQuota();
            if (false === $this->hasQuota() || false === $quotaData['GetSuccess'])
							$timeout = MINUTE_IN_SECONDS;
						else {
							$timeout = HOUR_IN_SECONDS;
						}
            $cache->storeItem(self::CACHE_NAME, $quotaData, $timeout);
        }
        else
				{
          $quotaData = $cacheData->getValue();
				}

        return $quotaData;
    }

    /**
     * Retrieve quota information for this account
     * @return object quotadata SPIO format
     */
    public function getQuota()
    {

          $quotaData = $this->getQuotaData();
          $DateNow = time();

          // Provision since we slashes fields and removed numeric, prevent version with old quotaData from crashing.
          if (isset($quotaData['APICallsQuota']) && false === is_numeric($quotaData['APICallsQuota']))
          {
              $this->forceCheckRemoteQuota();
              $quotData = $this->getQuotaData(); 
          }

          // This check to prevent IIS issue on 32Bit PHP to have complaints (?) .  //https://support.shortpixel.com/conversation/240212
          $DateSubscription = (isset($quotaData['APILastRenewalDate']) && $quotaData['APILastRenewalDate'] != 0 ) ? 
                          strtotime($quotaData['APILastRenewalDate']) : false; 
          $DaysToReset =  30 - ( (int) (  ( $DateNow  - $DateSubscription) / DAY_IN_SECONDS) % 30);

          $quota = (object) [
              'unlimited' => isset($quotaData['Unlimited']) ? $quotaData['Unlimited'] : false,
              'AIUnlimited' => isset($quotaData['AIUnlimited']) ? $quotaData['AIUnlimited'] : false, 
              'monthly' => (object) [
                'text' =>  sprintf(__('%s/month', 'shortpixel-image-optimiser'), number_format($quotaData['APICallsQuota']) . __(' credits','shortpixel-image-optimiser') ),
                'total' =>  $quotaData['APICallsQuota'],
                'consumed' => $quotaData['APICallsMade'],
                'remaining' => max($quotaData['APICallsQuota'] - $quotaData['APICallsMade'], 0),
                'renew' => $DaysToReset,
              ],
              'onetime' => (object) [
                'text' => number_format($quotaData['APICallsQuotaOneTime']) . __(' credits','shortpixel-image-optimiser'),
                'total' => $quotaData['APICallsQuotaOneTime'],
                'consumed' => $quotaData['APICallsMadeOneTime'],
                'remaining' => $quotaData['APICallsQuotaOneTime'] - $quotaData['APICallsMadeOneTime'],
              ],
              'ai' => (object) [
                 'text' => number_format($quotaData['CaptionsCallsQuota']) . __(' credits','shortpixel-image-optimiser') , 
                 'total' => $quotaData['CaptionsCallsQuota'], 
                 'consumed' => $quotaData['CaptionsCallsMade'], 
                 'remaining' => $quotaData['CaptionsCallsRemaining'], 
              ],
          ];


          $quota->total = (object) [
              'total' => $quota->monthly->total + $quota->onetime->total,
              'consumed'  => $quota->monthly->consumed + $quota->onetime->consumed,
              'remaining' =>$quota->monthly->remaining + $quota->onetime->remaining,
          ];


          return $quota;
    }

    /**
     * Get available remaining - total - credits
     * @return int Total remaining credits
     */
    public function getAvailableQuota()
    {
        $quota = $this->getQuota();
        return $quota->total->remaining;
    }

    /**
     * Force to get quotadata from API, even if cache is still active ( use very sparingly )
     * Does not actively fetches it, but invalidates cache, so next call will trigger a remote call
     * @return void
     */
    public function forceCheckRemoteQuota()
    {
        $cache = new CacheController();
        $cacheData = $cache->getItem(self::CACHE_NAME);
        $cacheData->delete();
        $this->quotaData = null;
    }

    /**
     * Validate account key in the API via quota check
     * @param  string $key               User account key
     * @return array      Quotadata array (remote format) with validated key
     */
    public function remoteValidateKey($key)
    {
			  // Remove the cache before checking.
				$this->forceCheckRemoteQuota();
        return $this->getRemoteQuota($key, true);
    }

		/**
     * Called when plugin detects the remote quota has been exceeded.
     * Triggers various call to actions to customer
     */
		public function setQuotaExceeded()
		{
			  $settings = \wpSPIO()->settings();
				$settings->quotaExceeded = 1;
				$this->forceCheckRemoteQuota(); // remove the previous cache.
		}

    /**
     * When quota is detected again via remote check, reset all call to actions
     */
    private function resetQuotaExceeded()
    {
        $settings = \wpSPIO()->settings();

        AdminNoticesController::resetAPINotices();

				// Only reset after a quotaExceeded situation, otherwise it keeps popping.
				if ($settings->quotaExceeded == 1)
				{
						AdminNoticesController::resetQuotaNotices();
				}
       	$settings->quotaExceeded = 0;
    }


    /**
     * [getRemoteQuota description]
     * @param  string $apiKey                 User account key
     * @param  boolean $validate               Api should also validate key or not
     * @return array            Quotadata array (remote format) [with validated key]
     */
    private function getRemoteQuota($apiKey = false, $validate = false)
    {
        if (false === $apiKey && false === $validate) // validation is done by apikeymodel, might result in a loop.
        {
          $keyControl = ApiKeyController::getInstance();
          $apiKey = $keyControl->forceGetApiKey();
        }

        $settings = \wpSPIO()->settings();

        $numericResults = [
              'APICallsMade', 
              'APICallsQuota', 
              'APICallsMadeOnTime', 
              'APICallsQuotaOneTime', 
              'APICallsMadeOneTime', 
              'CaptionsCallsMade', 
              'CaptionsCallsQuota', 
              'CaptionsCallsRemaining'
        ]; 

          if($settings->httpProto != 'https' && $settings->httpProto != 'http') {
              $settings->httpProto = 'https';
          }

          $requestURL = $settings->httpProto . '://' . SHORTPIXEL_API . '/v2/api-status.php';

          $args = array(
              'timeout'=> 15, // wait for 15 secs.
              'body' => array('key' => $apiKey)
          );
          $argsStr = "?key=".$apiKey;

		  $serverAgent = isset($_SERVER['HTTP_USER_AGENT']) ? urlencode(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';
          $args['body']['useragent'] = "Agent" . $serverAgent;
          $argsStr .= "&useragent=Agent".$args['body']['useragent'];

          // Only used for keyValidation
          if($validate) {

              $statsController = StatsController::getInstance();
              $imageCount = $statsController->find('media', 'itemsTotal');
              $thumbsCount = $statsController->find('media', 'thumbsTotal');

              $args['body']['DomainCheck'] = get_site_url();
              $args['body']['Info'] = get_bloginfo('version') . '|' . phpversion();
              $args['body']['ImagesCount'] = $imageCount; 
              $args['body']['ThumbsCount'] = $thumbsCount; 
              $argsStr .= "&DomainCheck={$args['body']['DomainCheck']}&Info={$args['body']['Info']}&ImagesCount=$imageCount&ThumbsCount=$thumbsCount";
          }

          $args['body']['host'] = parse_url(get_site_url(),PHP_URL_HOST);
          $argsStr .= "&host={$args['body']['host']}";
					if (defined('SHORTPIXEL_HTTP_AUTH_USER') && defined('SHORTPIXEL_HTTP_AUTH_PASSWORD'))
					{
						$args['body']['user'] = stripslashes(SHORTPIXEL_HTTP_AUTH_USER);
						$args['body']['pass'] = stripslashes(SHORTPIXEL_HTTP_AUTH_PASSWORD);
						$argsStr .= '&user=' . urlencode($args['body']['user']) . '&pass=' . urlencode($args['body']['pass']);
					}
          elseif(! is_null($settings->siteAuthUser) && strlen($settings->siteAuthUser)) {

              $args['body']['user'] = stripslashes($settings->siteAuthUser);
              $args['body']['pass'] = stripslashes($settings->siteAuthPass);
              $argsStr .= '&user=' . urlencode($args['body']['user']) . '&pass=' . urlencode($args['body']['pass']);
          }

//          $time = microtime(true);
//          $comm = array();

          //Try first HTTPS post. add the sslverify = false if https
          if($settings->httpProto === 'https') {
              $args['sslverify'] = apply_filters('shortpixel/system/sslverify', true);
          }

          $response = wp_remote_post($requestURL, $args);

        //  $comm['A: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);

          //some hosting providers won't allow https:// POST connections so we try http:// as well
          if(is_wp_error( $response )) {

              $requestURL = $settings->httpProto == 'https' ?
                  str_replace('https://', 'http://', $requestURL) :
                  str_replace('http://', 'https://', $requestURL);
              // add or remove the sslverify
              if($settings->httpProto === 'https') {
                  $args['sslverify'] = apply_filters('shortpixel/system/sslverify', true);
              } else {
                  unset($args['sslverify']);
              }
              $response = wp_remote_post($requestURL, $args);
            //  $comm['B: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);

              if(!is_wp_error( $response )){
                  $settings->httpProto = ($settings->httpProto == 'https' ? 'http' : 'https');
              } else {
              }
          }
          //Second fallback to HTTP get
          if(is_wp_error( $response )){
              $args['body'] = null;
              $requestURL .= $argsStr;
              $response = wp_remote_get($requestURL, $args);
          //    $comm['C: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);
          }

          $defaultData = [
              "APIKeyValid" => false,
              "Message" => __('API Key could not be validated due to a connectivity error.<BR>Your firewall may be blocking us. Please contact your hosting provider and ask them to allow connections from your site to api.shortpixel.com (IP 176.9.21.94).<BR> If you still cannot validate your API Key after this, please <a href="https://shortpixel.com/contact" target="_blank">contact us</a> and we will try to help. ','shortpixel-image-optimiser'),
              "DomainCheck" => 'NOT Accessible',
              "GetSuccess" => false, 
            ];

          $defaultData = is_array($settings->currentStats) ? array_merge( $settings->currentStats, $defaultData) : $defaultData;

          foreach($numericResults as $numField)
          {
             $defaultData[$numField] = 0;
          }

          if(is_object($response) && get_class($response) == 'WP_Error') {

              $urlElements = parse_url($requestURL);
              $portConnect = @fsockopen($urlElements['host'],8,$errno,$errstr,15);
              if(!$portConnect) {
                  $defaultData['Message'] .= "<BR>Debug info: <i>$errstr</i>";
              }
              return $defaultData;
          }

          if($response['response']['code'] != 200) {
             return $defaultData;
          }

          $data = $response['body'];
          $data = json_decode($data);

          if(empty($data)) { return $defaultData; }

          if($data->Status->Code != 2) {
              $defaultData['Message'] = $data->Status->Message;
              return $defaultData;
          }

          $dataArray = [
              "APIKeyValid" => true,
              "Unlimited" => (property_exists($data, 'Unlimited') && $data->Unlimited == 'true') ? true : false,
              'AIUnlimited' => (property_exists($data, 'PlanType') && $data->PlanType == 'Unlimited AI') ? true : false, 
              "APILastRenewalDate" => $data->DateSubscription,
              "DomainCheck" => (isset($data->DomainCheck) ? $data->DomainCheck : null), 
              "GetSuccess" => true, 
          ];

          foreach($numericResults as $resultName)
          {
              if (property_exists($data, $resultName))
              {
                  $dataArray[$resultName] = (int) max($data->$resultName, 0); 
              }
              else
              {
                 $dataArray[$resultName] = 0;
              }
          }

		 $dataArray["APICallsRemaining"] = max($dataArray['APICallsQuota'] - $dataArray['APICallsMade'], 0) + max($dataArray['APICallsQuotaOneTime'] - $dataArray['APICallsMadeOneTime'],0);

          if ( $dataArray['APICallsRemaining'] > 0 || $dataArray['Unlimited'])
					{
              $this->resetQuotaExceeded();
					}
          else
					{
							//activate quota limiting
              $this->setQuotaExceeded();
					}

          return $dataArray;
    }

}
