<?php
namespace ShortPixel\Controller;

class QuotaController
{
    protected static $instance;

    public function __construct()
    {

    }

    public static function getInstance()
    {
      if (is_null(self::$instance))
        self::$instance = new QuotaController();

      return self::$instance;
    }

    public function hasQuota()
    {
      $settings = \wpSPIO()->settings();
      if ($settings->quotaExceeded)
        return false;

      return true;
      
    }

    public function forceCheckRemoteQuota()
    {
       $this->getRemoteQuota();
    }

    private function resetQuotaExceeded()
    {
        $settings = \wpSPIO()->settings();
        if( $settings->quotaExceeded == 1) {
            $dismissed = $settings->dismissedNotices ? $settings->dismissedNotices : array();
            //unset($dismissed['exceed']);
            $settings->prioritySkip = array();
            $settings->dismissedNotices = $dismissed;
            \ShortPixel\Controller\adminNoticesController::resetAPINotices();
            \ShortPixel\Controller\adminNoticesController::resetQuotaNotices();
        }
        $settings->quotaExceeded = 0;
    }

    private function getRemoteQuota()
    {
        $keyControl = ApiKeyController::getInstance();
        $apiKey = $keyControl->forceGetApiKey();

        $settings = \wpSPIO()->settings();

          if(is_null($apiKey)) { $apiKey = $settings->apiKey; }

          if($settings->httpProto != 'https' && $settings->httpProto != 'http') {
              $settings->httpProto = 'https';
          }

          $requestURL = $settings->httpProto . '://' . SHORTPIXEL_API . '/v2/api-status.php';
          $args = array(
              'timeout'=> SHORTPIXEL_VALIDATE_MAX_TIMEOUT,
              'body' => array('key' => $apiKey)
          );
          $argsStr = "?key=".$apiKey;

          if($appendUserAgent) {
              $args['body']['useragent'] = "Agent" . urlencode($_SERVER['HTTP_USER_AGENT']);
              $argsStr .= "&useragent=Agent".$args['body']['useragent'];
          }
          if($validate) {
              $args['body']['DomainCheck'] = get_site_url();
              $args['body']['Info'] = get_bloginfo('version') . '|' . phpversion();
              $imageCount = WpShortPixelMediaLbraryAdapter::countAllProcessable($settings);
              $args['body']['ImagesCount'] = $imageCount['mainFiles'];
              $args['body']['ThumbsCount'] = $imageCount['totalFiles'] - $imageCount['mainFiles'];
              $argsStr .= "&DomainCheck={$args['body']['DomainCheck']}&Info={$args['body']['Info']}&ImagesCount={$imageCount['mainFiles']}&ThumbsCount={$args['body']['ThumbsCount']}";
          }
          $args['body']['host'] = parse_url(get_site_url(),PHP_URL_HOST);
          $argsStr .= "&host={$args['body']['host']}";
          if(strlen($settings->siteAuthUser)) {

              $args['body']['user'] = stripslashes($settings->siteAuthUser);
              $args['body']['pass'] = stripslashes($settings->siteAuthPass);
              $argsStr .= '&user=' . urlencode($args['body']['user']) . '&pass=' . urlencode($args['body']['pass']);
          }
          if($settings !== false) {
              $args['body']['Settings'] = $settings;
          }

          $time = microtime(true);
          $comm = array();

          //Try first HTTPS post. add the sslverify = false if https
          if($settings->httpProto === 'https') {
              $args['sslverify'] = false;
          }
          $response = wp_remote_post($requestURL, $args);

          $comm['A: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);

          //some hosting providers won't allow https:// POST connections so we try http:// as well
          if(is_wp_error( $response )) {
              //echo("protocol " . $this->_settings->httpProto . " failed. switching...");
              $requestURL = $settings->httpProto == 'https' ?
                  str_replace('https://', 'http://', $requestURL) :
                  str_replace('http://', 'https://', $requestURL);
              // add or remove the sslverify
              if($settings->httpProto === 'http') {
                  $args['sslverify'] = false;
              } else {
                  unset($args['sslverify']);
              }
              $response = wp_remote_post($requestURL, $args);
              $comm['B: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);

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
              $comm['C: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);
          }
          Log::addInfo("API STATUS COMM: " . json_encode($comm));

          $defaultData = array(
              "APIKeyValid" => false,
              "Message" => __('API Key could not be validated due to a connectivity error.<BR>Your firewall may be blocking us. Please contact your hosting provider and ask them to allow connections from your site to api.shortpixel.com (IP 176.9.21.94).<BR> If you still cannot validate your API Key after this, please <a href="https://shortpixel.com/contact" target="_blank">contact us</a> and we will try to help. ','shortpixel-image-optimiser'),
              "APICallsMade" => __('Information unavailable. Please check your API key.','shortpixel-image-optimiser'),
              "APICallsQuota" => __('Information unavailable. Please check your API key.','shortpixel-image-optimiser'),
              "APICallsMadeOneTime" => 0,
              "APICallsQuotaOneTime" => 0,
              "APICallsMadeNumeric" => 0,
              "APICallsQuotaNumeric" => 0,
              "APICallsMadeOneTimeNumeric" => 0,
              "APICallsQuotaOneTimeNumeric" => 0,
              "APICallsRemaining" => 0,
              "APILastRenewalDate" => 0,
              "DomainCheck" => 'NOT Accessible');
          $defaultData = is_array($settings->currentStats) ? array_merge( $settings->currentStats, $defaultData) : $defaultData;

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

          if ( ( $data->APICallsMade + $data->APICallsMadeOneTime ) < ( $data->APICallsQuota + $data->APICallsQuotaOneTime ) ) //reset quota exceeded flag -> user is allowed to process more images.
              $this->resetQuotaExceeded();
          else
              $settings->quotaExceeded = 1;//activate quota limiting

          //if a non-valid status exists, delete it
          // @todo Clarify the reason for this statement
          $lastStatus = $this->_settings->bulkLastStatus;
          if($lastStatus && $lastStatus['Status'] == ShortPixelAPI::STATUS_NO_KEY) {
              $settings->bulkLastStatus = null;
          }

          $dataArray = array(
              "APIKeyValid" => true,
              "APICallsMade" => number_format($data->APICallsMade) . __(' images','shortpixel-image-optimiser'),
              "APICallsQuota" => number_format($data->APICallsQuota) . __(' images','shortpixel-image-optimiser'),
              "APICallsMadeOneTime" => number_format($data->APICallsMadeOneTime) . __(' images','shortpixel-image-optimiser'),
              "APICallsQuotaOneTime" => number_format($data->APICallsQuotaOneTime) . __(' images','shortpixel-image-optimiser'),
              "APICallsMadeNumeric" => $data->APICallsMade,
              "APICallsQuotaNumeric" => $data->APICallsQuota,
              "APICallsMadeOneTimeNumeric" => $data->APICallsMadeOneTime,
              "APICallsQuotaOneTimeNumeric" => $data->APICallsQuotaOneTime,
              "APICallsRemaining" => $data->APICallsQuota + $data->APICallsQuotaOneTime - $data->APICallsMade - $data->APICallsMadeOneTime,
              "APILastRenewalDate" => $data->DateSubscription,
              "DomainCheck" => (isset($data->DomainCheck) ? $data->DomainCheck : null)
          );

          $crtStats = is_array($settings->currentStats) ? array_merge( $settings->currentStats, $dataArray) : $dataArray;
          $crtStats['optimizePdfs'] = $settings->optimizePdfs;
          $settings->currentStats = $crtStats;

          Log::addDebug('GetQuotaInformation Result ', $dataArray);
          return $dataArray;
    }

}
