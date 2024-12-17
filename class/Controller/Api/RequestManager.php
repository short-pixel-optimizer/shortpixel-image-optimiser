<?php
namespace ShortPixel\Controller\Api;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

class RequestManager
{

	public static function getInstance()
	{
		 if (is_null(self::$instance))
			 self::$instance = new static();

			return self::$instance;
	}

	/** DoRequest : Does a remote_post to the API
	*
	* @param Object $item  The QueueItemObject
	* @param Array $requestParameters  The HTTP parameters for the remote post (arguments in getRequest)
	*/
	protected function doRequest($item, $requestParameters )
	{
		$response = wp_remote_post($this->apiEndPoint, $requestParameters );
		Log::addDebug('ShortPixel API Request sent', $requestParameters['body']);

		//only if $Blocking is true analyze the response
		if ( $requestParameters['blocking'] )
		{
				if ( is_object($response) && get_class($response) == 'WP_Error' )
				{
						$errorMessage = $response->errors['http_request_failed'][0];
						$errorCode = self::STATUS_CONNECTION_ERROR;
						$item->result = $this->returnRetry($errorCode, $errorMessage);
				}
				elseif ( isset($response['response']['code']) && $response['response']['code'] <> 200 )
				{
						$errorMessage = $response['response']['code'] . " - " . $response['response']['message'];
						$errorCode = $response['response']['code'];
						$item->result = $this->returnFailure($errorCode, $errorMessage);
				}
				else
				{
					 $item->result = $this->handleResponse($item, $response);
				}

		}
		else // This should be only non-blocking the FIRST time it's send off.
		{
			 if ($item->tries > 0)
			 {
					Log::addWarn('DOREQUEST sent item non-blocking with multiple tries!', $item);
			 }

			 $urls = count($item->urls);
			 $flags = property_exists($item, 'flags') ? $item->flags : array();
			 $flags = implode("|", $flags);
			 $text = sprintf(__('New item #%d sent for processing ( %d URLS %s)  ', 'shortpixel-image-optimiser'), $item->item_id, $urls, $flags );

			 $item->result = $this->returnOK(self::STATUS_ENQUEUED, $text );
		}

		return $item;
	}



} // class RequestManager
