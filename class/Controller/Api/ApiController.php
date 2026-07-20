<?php
namespace ShortPixel\Controller\Api;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Model\Image\ImageModel as ImageModel;

use ShortPixel\Helper\UtilHelper as UtilHelper;

/**
 * Communicates with the ShortPixel image optimisation API (v2/reducer) to send images
 * for optimisation and process the returned results.
 *
 * Handles standard media optimisation, special API actions (background removal, upscale),
 * and cache-dump requests. Interprets API status codes and maps them to internal result
 * structures for further processing by the optimizer layer.
 *
 * @package ShortPixel\Controller\Api
 */
class ApiController extends RequestManager
{
	// Moved these numbers higher to prevent conflict with STATUS
	// @todo Almost none of these are in use ( ERR_TIMEOUT only )
	const ERR_FILE_NOT_FOUND = -902;
	const ERR_TIMEOUT = -903;
	const ERR_SAVE = -904;
	const ERR_SAVE_BKP = -905;
	const ERR_INCORRECT_FILE_SIZE = -906;
	const ERR_DOWNLOAD = -907;
	const ERR_PNG2JPG_MEMORY = -908;
	const ERR_POSTMETA_CORRUPT = -909;
	const ERR_UNKNOWN = -999;

	//private $apiEndPoint;

	/** @var string URL of the ShortPixel cache-dump (cleanup) endpoint. */
	private $apiDumpEndPoint;

	/** @var array Temporary files created during a request, keyed by identifier. */
	protected static $temporaryFiles = array();

	/** @var array Temporary directories created during a request, keyed by identifier. */
	protected static $temporaryDirs = array();

	/**
	 * Initialises the API endpoint URLs from the plugin settings.
	 *
	 * Sets $apiEndPoint (inherited from RequestManager) to the reducer endpoint
	 * (https://api.shortpixel.com/v2/reducer.php) and $apiDumpEndPoint to the
	 * cleanup endpoint, both using the configured HTTP protocol (http or https).
	 */
	public function __construct()
	{
		$settings = \wpSPIO()->settings();
		$this->apiEndPoint = $settings->httpProto . '://' . SHORTPIXEL_API . '/v2/reducer.php';
		$this->apiDumpEndPoint = $settings->httpProto . '://' . SHORTPIXEL_API . '/v2/cleanup.php';
	}

	/**
	 * Builds and sends an image-optimisation request to the ShortPixel API.
	 *
	 * Validates the image model and URL list on the queue item, assembles the full
	 * request body (compression type, resize, CMYK conversion, EXIF, format-conversion
	 * flags, plugin version, API key, item ID, and optional `paramlist`/`returndatalist`
	 * fields), and calls doRequest(). The first send is non-blocking (tries == 0);
	 * subsequent retries are blocking. On a terminal error the item is immediately
	 * dumped from the remote cache via dumpMediaItem().
	 *
	 * Note: when the image model fails the processable check the method adds a failure
	 * result but does NOT return early — execution continues to the URL check below.
	 *
	 * @param QueueItem $qItem Queue item containing the image model, URLs, and optimisation settings.
	 * @return void
	 */
	public function processMediaItem(QueueItem $qItem)
	{
		$imageModel = $qItem->imageModel;

		if (!is_object($imageModel)) {
			$qItem->addResult($this->returnFailure(self::STATUS_FAIL, __('Item seems invalid, removed or corrupted.', 'shortpixel-image-optimiser')));
		} elseif (false === $imageModel->isProcessable() || $imageModel->isOptimizePrevented() == true) {
			if ($imageModel->isOptimized()) // This only looks at main item
			{
				$qItem->addResult($this->returnFailure(self::STATUS_FAIL, __('Item is already optimized', 'shortpixel-image-optimiser')));
			} else {
				$qItem->addResult($this->returnFailure(self::STATUS_FAIL, __('Item is not processable and not optimized', 'shortpixel-image-optimiser')));
			}
		}

		if (!is_array($qItem->data()->urls) || count($qItem->data()->urls) == 0) {
			$qItem->addResult($this->returnFailure(self::STATUS_FAIL, __('No Urls given for this Item', 'shortpixel-image-optimiser')));
			return;
		}

		$settings = \wpSPIO()->settings();
		$keyControl = ApiKeyController::getInstance();
		$flags = $qItem->data()->flags;
		$convertTo = implode("|", $flags);

		$requestBody = [
			'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
			'key' => $keyControl->forceGetApiKey(),
			'urllist' => $qItem->data()->urls,
			'lossy' => $qItem->data()->compressionType,
			'item_id' => $qItem->item_id,
			'refresh' => $qItem->data()->tries == 0 ? true : false,
			'cmyk2rgb' => $settings->CMYKtoRGBconversion,
			'keep_exif' => UtilHelper::getExifParameter(),
			'convertto' => $convertTo,
			'resize' => $settings->resizeImages ? 1 + 2 * ($settings->resizeType == 'inner' ? 1 : 0) : 0,
			'resize_width' => $settings->resizeWidth,
			'resize_height' => $settings->resizeHeight,
		];

		if (!is_null($qItem->data()->paramlist)) {
			$requestBody['paramlist'] = $qItem->data()->paramlist;
		}

		if (!is_null($qItem->data()->returndatalist)) {
			$requestBody['returndatalist'] = $qItem->data()->returndatalist;
		}

		$requestParameters = [
			'blocking' => (0 == $qItem->data()->tries) ? false : true
		];

		$request = $this->getRequest($requestBody, $requestParameters);
		$this->doRequest($qItem, $request);

		ResponseController::addData($qItem->item_id, 'images_total', count($qItem->data()->urls));

		// If error has occured, but it's not related to connection.
		if ($qItem->result()->is_error === true && $qItem->result()->is_done === true) {
			$this->dumpMediaItem($qItem); // item failed, directly dump anything from server.
		}

	}

	/**
	 * Builds and sends a special-action API request (e.g. background removal, upscale).
	 *
	 * Similar to processMediaItem() but always uses blocking mode and applies
	 * action-specific parameters from the item's paramlist. On terminal error the
	 * item is dumped from the remote cache.
	 *
	 * @param QueueItem $qItem Queue item with the action type and URL list.
	 * @return void
	 */
	public function processActionItem(QueueItem $qItem)
	{
		$keyControl = ApiKeyController::getInstance();

		$requestBody = [
			'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
			'key' => $keyControl->forceGetApiKey(),
			'urllist' => $qItem->data()->urls,
			'lossy' => $qItem->data()->compressionType,
			'item_id' => $qItem->item_id,
			'refresh' => ($qItem->data()->tries == 0) ? $qItem->data()->paramlist['refresh'] : false,
		];

		if (true === $requestBody['refresh'])
		{
			 $this->dumpMediaItem($qItem);
		}

		if (isset($qItem->data()->paramlist['bg_remove']))
		{
			 $requestBody['bg_remove'] = $qItem->data()->paramlist['bg_remove'];
		}
		elseif (isset($qItem->data()->paramlist['upscale'])) 
		{
			 $requestBody['upscale'] = $qItem->data()->paramlist['upscale'];
		}

		$requestParameters = [
			'blocking' => true, //(0 == $qItem->data()->tries) ? false : true
		];

		if (!is_null($qItem->data()->returndatalist)) {
			$requestBody['returndatalist'] = $qItem->data()->returndatalist;
		}


		$request = $this->getRequest($requestBody, $requestParameters);
		$this->doRequest($qItem, $request);

		if ($qItem->result()->is_error === true && $qItem->result()->is_done === true) {
			$this->dumpMediaItem($qItem); // item failed, directly dump anything from server.
		}

	}

	/**
	 * Asks the ShortPixel API to evict the item's URLs from the remote server cache.
	 *
	 * Should be called after a terminal error or before re-submitting an item so that
	 * the API does not serve stale cached results for those URLs.
	 *
	 * @param QueueItem $qItem Queue item whose URLs should be purged. Must have a non-empty urls array.
	 * @return false|mixed False when the item has no URLs, otherwise the raw wp_remote_post() return value.
	 */
	public function dumpMediaItem(QueueItem $qItem)
	{
		$settings = \wpSPIO()->settings();
		$keyControl = ApiKeyController::getInstance();

		if (is_null($qItem->data()->urls) || !is_array($qItem->data()->urls) || count($qItem->data()->urls) == 0) {
			Log::addWarn('Media Item without URLS cannnot be dumped ', $qItem);
			return false;
		}

		$requestBody = [
			'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
			'key' => $keyControl->forceGetApiKey(),
			'urllist' => $qItem->data()->urls,
			'item_id' => $qItem->item_id,
		];

		$request = $this->getRequest($requestBody, []);

		Log::addDebug('Dumping Media Item ', $qItem->data()->urls);

		$ret = wp_remote_post($this->apiDumpEndPoint, $request);

		return $ret;
	}

	/**
	 * Dispatches the parsed API response to the appropriate handler based on the queue item's action.
	 *
	 * Calls parseResponse() to JSON-decode the response body, then inspects the top-level
	 * Status object. The ShortPixel API always returns HTTP 200; error conditions are
	 * signalled via Status.Code values in the JSON body. Known codes handled here:
	 *
	 *  - -102/-105/-106/-108/-111/-113/-201/-202/-203/-207 : permanent file/URL errors → STATUS_ERROR (no retry)
	 *  - -301/-403 : quota exceeded → STATUS_QUOTA_EXCEEDED (retry after quota refill)
	 *  - -302 : file no longer available remotely → STATUS_FAIL
	 *  - -306 : mixed-domain URLs in one request → STATUS_FAIL
	 *  - -401/-402 : invalid/wrong API key → STATUS_NO_KEY
	 *  - -404 : remote optimisation queue full → STATUS_QUEUE_FULL (retry)
	 *  - -500 : API maintenance → STATUS_MAINTENANCE (retry)
	 *
	 * When the response contains image data (array with index 0), delegates to
	 * handleOptimizeResponse() for 'optimize'/'convert_api' actions, or
	 * handleActionResponse() for 'remove_background'/'scale_image' actions.
	 *
	 * @param QueueItem $qItem    The queue item being processed.
	 * @param array     $response Raw wp_remote_post() response array (body not yet decoded).
	 * @return array Result array from one of the return* helper methods.
	 */
	protected function handleResponse(QueueItem $qItem, $response)
	{

		$APIresponse = $this->parseResponse($response);//get the actual response from API, its an array
		$action = $qItem->data()->action;

		// Don't know if it's this or that.
		$status = false;
		if (isset($APIresponse['Status'])) {
			$status = $APIresponse['Status'];
		} elseif (is_array($APIresponse) && isset($APIresponse[0]) && property_exists($APIresponse[0], 'Status')) {
			$status = $APIresponse[0]->Status;
		}

		// This is only set if something is up, otherwise, ApiResponse returns array
		if (is_object($status)) {
			// Check for known errors. : https://shortpixel.com/api-docs
			Log::addDebug('Api Response Status :' . $status->Code);
			switch ($status->Code) {
				case -102: // Invalid URL
				case -105: // URL missing
				case -106: // Url is inaccessible
				case -108:   // Wrong URL for ApiKey
				case -111: // File too big ( for upscale ) 
				case -113: // Too many inaccessible URLs
				case -201: // Invalid image format
				case -202: // Invalid image or unsupported format
				case -203: // Could not download file
				case -207: // Invalid parameters
					return $this->returnFailure(self::STATUS_ERROR, $status->Message);
				break;
				case -403: // Quota Exceeded
				case -301: // The file is larger than remaining quota
					// legacy
					@delete_option('bulkProcessingStatus');
					QuotaController::getInstance()->setQuotaExceeded();

					return $this->returnRetry(self::STATUS_QUOTA_EXCEEDED, __('Quota exceeded.', 'shortpixel-image-optimiser'));
				break;
			    case -302: 
					return $this->returnFailure(self::STATUS_FAIL, __('File no longer available on remote system', 'shortpixel-image-optimiser'));
				break; 
				case -306:
					return $this->returnFailure(self::STATUS_FAIL, __('Files need to be from a single domain per request.', 'shortpixel-image-optimiser'));
				break;
				case -401: // Invalid Api Key
				case -402: // Wrong API key
					return $this->returnFailure(self::STATUS_NO_KEY, $status->Message);
				break;
				case -404: // Maximum number in optimization queue (remote)
					//return array("Status" => self::STATUS_QUEUE_FULL, "Message" => $APIresponse['Status']->Message);
					return $this->returnRetry(self::STATUS_QUEUE_FULL, $status->Message);
				break;
				case -500: // API in maintenance.
					//return array("Status" => self::STATUS_MAINTENANCE, "Message" => $APIresponse['Status']->Message);
					return $this->returnRetry(self::STATUS_MAINTENANCE, $status->Message);

				break;
			}
		}

		if (is_array($APIresponse) && isset($APIresponse[0])) //API returned image details
		{

				if ('optimize' === $action || 'convert_api' === $action)
				{
					 return $this->handleOptimizeResponse($qItem, $APIresponse);
				}
				if ('remove_background' === $action)
				{
					 return $this->handleActionResponse($qItem, $APIresponse);
				}
				if ('scale_image' == $action)
				{
					 return $this->handleActionResponse($qItem, $APIresponse);
				}

				// Bail out if action is not properly defined
				return $this->returnFailure(self::STATUS_FAIL, __('ApiController was not provided with known action', 'shortpixel-image-optimiser'));


		} // ApiResponse[0]

		// If this code reaches here, something is wrong.
		if (!isset($APIresponse['Status'])) {

			Log::addError('API returned Unknown Status/Response ', $response);
			return $this->returnFailure(self::STATUS_FAIL, __('Unrecognized API response. Please contact support.', 'shortpixel-image-optimiser'));

		} else {

			//sometimes the response array can be different
			if (is_numeric($APIresponse['Status']->Code)) {
				$message = $APIresponse['Status']->Message;
			} else {
				$message = $APIresponse[0]->Status->Message;
			}

			if (!isset($message) || is_null($message) || $message == '') {
				$message = __('Unrecognized API message. Please contact support.', 'shortpixel-image-optimiser');
			}
			return $this->returnRetry(self::STATUS_FAIL, $message);
		} // else

	}
	// handleResponse function

	/**
	 * Processes an API response containing optimised image data for a standard optimise action.
	 *
	 * Extracts the `returndatalist` envelope (containing `sizes`, `fileSizes`, `doubles`,
	 * and `duplicates` sub-keys) from the response array before iterating over per-image
	 * objects. For each image object, inspects Status.Code:
	 *  - STATUS_UNCHANGED / STATUS_WAITING : increments the waiting counter; marks partial success.
	 *  - STATUS_SUCCESS                    : delegates to handleNewSuccess() for per-format
	 *                                        result assembly (image, WebP, AVIF), where the
	 *                                        per-format URL fields use 'NC' (not compressible)
	 *                                        and 'NA' (not available / stop polling) sentinels.
	 *
	 * Returns STATUS_SUCCESS when all images are done, STATUS_PARTIAL_SUCCESS when some
	 * are still waiting, or STATUS_UNCHANGED when all images are still pending. Fails with
	 * STATUS_FAIL if `returndatalist.sizes` is absent.
	 *
	 * @param QueueItem $qItem    The queue item being processed.
	 * @param array     $response The decoded API response array (indexed by image).
	 * @return array Result array from one of the return* helper methods.
	 */
	protected function handleOptimizeResponse(QueueItem $qItem, $response)
	{
		$neededURLS = $qItem->data()->urls; // URLS we are waiting for.

		if (isset($response['returndatalist'])) {
			$returnDataList = (array) $response['returndatalist'];
			if (isset($returnDataList['sizes']) && is_object($returnDataList['sizes']))
				$returnDataList['sizes'] = (array) $returnDataList['sizes'];

			if (isset($returnDataList['doubles']) && is_object($returnDataList['doubles']))
				$returnDataList['doubles'] = (array) $returnDataList['doubles'];

			if (isset($returnDataList['duplicates']) && is_object($returnDataList['duplicates']))
				$returnDataList['duplicates'] = (array) $returnDataList['duplicates'];

			if (isset($returnDataList['fileSizes']) && is_object($returnDataList['fileSizes']))
				$returnDataList['fileSizes'] = (array) $returnDataList['fileSizes'];

			unset($response['returndatalist']);
		} else {
			$returnDataList = [];
		}

		if (!isset($returnDataList['sizes'])) {
			return $this->returnFailure(self::STATUS_FAIL, __('Item did not return image size information. This might be a failed queue item. Reset the queue if this persists or contact support', 'shortpixel-image-optimiser'));
		}

		$analyze = array('total' => count($neededURLS), 'ready' => 0, 'waiting' => 0);
		$waitingDebug = array();

		$imageList = [];
		$partialSuccess = false;
		$imageNames = array_keys($returnDataList['sizes']);
		$fileNames = array_values($returnDataList['sizes']);

		foreach ($response as $index => $imageObject) {
			if (!property_exists($imageObject, 'Status')) {
				Log::addWarn('Result without Status', $imageObject);
				continue; // can't do nothing with that, probably not an image.
			} elseif ($imageObject->Status->Code == self::STATUS_UNCHANGED || $imageObject->Status->Code == self::STATUS_WAITING) {
				$analyze['waiting']++;
				$partialSuccess = true; // Not the whole job has been done.
			} elseif ($imageObject->Status->Code == self::STATUS_SUCCESS) {
				$analyze['ready']++;
				$imageName = $imageNames[$index];
				$fileName = $fileNames[$index];
				$paramlist = $qItem->data()->paramlist;

				// Here add paramList items that are possible needed for success checks
				$params = isset($paramlist[$index]) ? (array) $paramlist[$index] : [];


				$data = array(
					'fileName' => $fileName,
					'imageName' => $imageName,
				);

				$data = array_merge($params, $data);

				// Filesize might not be present, but also imageName ( only if smartcrop is done, might differ per image)
				if (isset($returnDataList['fileSizes']) && isset($returnDataList['fileSizes'][$imageName])) {
					$data['fileSize'] = $returnDataList['fileSizes'][$imageName];
				}

				$fileData = $qItem->data()->files;

				// Previous check here was for Item->files[$imageName] , not sure if currently needed.
				// Check if image is not already in fileData.
				// 06/03/26 - changing during testing this to isset since data()->files seems array?
				// 09/03 - Issue here is probably json_encode / decode saving of this data, which enteres as array first, but from record it becomes object. Check for that.

				if (is_array($fileData))
				{
					 $fileData = (object) $fileData;
				}


				if (is_null($fileData) || false === property_exists($fileData,$imageName) ) {
					$imageList[$imageName] = $this->handleNewSuccess($qItem, $imageObject, $data);
				} 
			}

		}

		$imageData = array(
			'images_done' => $analyze['ready'],
			'images_waiting' => $analyze['waiting'],
			'images_total' => $analyze['total']
		);
		ResponseController::addData($qItem->item_id, $imageData);

		if (count($imageList) > 0) {
			$data = array(
				'files' => $imageList,
				'data' => $returnDataList,
			);
			if (false === $partialSuccess) {
				return $this->returnSuccess($data, self::STATUS_SUCCESS, false);
			} else {
				return $this->returnSuccess($data, self::STATUS_PARTIAL_SUCCESS, false);
			}
		} elseif ($analyze['waiting'] > 0) {
			return $this->returnOK(self::STATUS_UNCHANGED, sprintf(__('Item is waiting', 'shortpixel-image-optimiser')));
		} else {
			// Theoretically this should not be needed.
			Log::addWarn('ApiController Response not handled before default case', $imageList);
			if (isset($APIresponse[0]->Status->Message)) {

				$err = array(
					"Status" => self::STATUS_FAIL,
					"Code" => (isset($response[0]->Status->Code) ? $response[0]->Status->Code : self::ERR_UNKNOWN),
					"Message" => __('There was an error and your request was not processed.', 'shortpixel-image-optimiser')
						. " (" . wp_basename($response[0]->OriginalURL) . ": " . $response[0]->Status->Message . ")"
				);
				return $this->returnRetry($err['Code'], $err['Message']);
			} else {
				$err = array(
					"Status" => self::STATUS_FAIL,
					"Message" => __('There was an error and your request was not processed.', 'shortpixel-image-optimiser'),
					"Code" => (isset($response[0]->Status->Code) ? $response[0]->Status->Code : self::ERR_UNKNOWN)
				);
				return $this->returnRetry($err['Code'], $err['Message']);
			}
		}
	}

	/**
	 * Processes an API response for a single-file action (background removal, upscale, etc.).
	 *
	 * Inspects the Status.Code of $response[0]. Returns STATUS_UNCHANGED when the code is
	 * STATUS_UNCHANGED or STATUS_WAITING. On STATUS_SUCCESS, returns a success result with
	 * 'optimized' set to LosslessURL and 'original' set to OriginalURL from the response
	 * object — LosslessURL is used unconditionally regardless of compression type.
	 *
	 * If the status code matches neither waiting nor success the method falls through
	 * without an explicit return (implicit null), which the caller does not handle.
	 *
	 * @param QueueItem $qItem    The queue item being processed.
	 * @param array     $response The decoded API response array; $response[0] is the image object.
	 * @return array|null Result array, or null when the status code is unrecognised.
	 */
	protected function handleActionResponse(QueueItem $qItem, $response)
	{
		$item = $response[0]; // First File Response of API.
		$status_code = intval($item->Status->Code);

		if (in_array($status_code, [self::STATUS_UNCHANGED, self::STATUS_WAITING] ))
		{
			return $this->returnOK(self::STATUS_UNCHANGED, sprintf(__('Item is waiting', 'shortpixel-image-optimiser')));
		}
		if (self::STATUS_SUCCESS == $status_code)
		{
			$image = $item->LosslessURL;
			$imageData = [
				'optimized' => $image,
				'original' => $item->OriginalURL,
			];
			return $this->returnSuccess($imageData);
		}

	}

	/**
	 * Builds the per-image result structure when the API signals a completed optimisation.
	 *
	 * Reads URL and size fields from the API response object. For lossy compression the
	 * relevant fields are `LossyURL` / `LossySize`; for lossless they are `LosslessURL` /
	 * `LosslessSize`. WebP and AVIF variants use the same naming prefixed with `WebP` or
	 * `AVIF` (e.g. `WebPLosslessURL`). Two per-format string sentinels are handled:
	 *  - 'NC' (Not Compressible) : sets STATUS_NOT_COMPATIBLE for that format.
	 *  - 'NA' (Not Available)    : the URL field is absent or skipped; status stays STATUS_SKIP.
	 *
	 * Applies checkFileSizeMargin() against the original file size; if the optimised result
	 * is larger than the allowed margin the status is set to STATUS_OPTIMIZED_BIGGER. The
	 * smartcrop `resize == 4` path bypasses that margin check.
	 *
	 * @param QueueItem $qItem    Queue item object carrying compression type and settings.
	 * @param object    $fileData API response object for a single image (OriginalSize, OriginalURL,
	 *                            LossyURL, LossySize, LosslessURL, LosslessSize, WebP*, AVIF*, etc.).
	 * @param array     $data     Contextual data: 'fileName', 'imageName', optionally 'fileSize'
	 *                            (overrides OriginalSize) and 'resize' (int, 4 = smartcrop).
	 * @return array Associative array with 'image', 'webp', and 'avif' sub-arrays, each containing
	 *               'url', 'size'/'optimizedSize', 'originalSize' (image only), and 'status'.
	 */
	protected function handleNewSuccess(QueueItem $qItem, $fileData, $data)
	{
		$settings = \wpSPIO()->settings();
		$compressionType = ! is_null($qItem->data()->compressionType) ? $qItem->data()->compressionType : $settings->compressionType;
		//$savedSpace =  $originalSpace =  $optimizedSpace = $fileCount  = 0;

		$defaults = [
			'fileName' => false,
			'imageName' => false,
			'fileSize' => false,
		];

		$data = wp_parse_args($data, $defaults);

		if (false === $data['fileName'] || false === $data['imageName']) {
			Log::addError('Failure! HandleSuccess did not receive filename or imagename! ', $data);
			Log::addError('Error Item:', $qItem);

			return $this->returnFailure(self::STATUS_FAIL, __('Internal error, missing variables', 'shortpixel-image-optimiser'));
		}

		$originalFileSize = (false === $data['fileSize']) ? intval($fileData->OriginalSize) : $data['fileSize'];

		$image = array(
			'image' => array(
				'url' => false,
				'originalSize' => $originalFileSize,
				'optimizedSize' => false,
				'status' => self::STATUS_SUCCESS,
			),
			'webp' => array(
				'url' => false,
				'size' => false,
				'status' => self::STATUS_SKIP,
			),
			'avif' => array(
				'url' => false,
				'size' => false,
				'status' => self::STATUS_SKIP,
			),
		);

		$fileType = ($compressionType > 0) ? 'LossyURL' : 'LosslessURL';
		$fileSize = ($compressionType > 0) ? 'LossySize' : 'LosslessSize';

		// if originalURL and OptimizedURL is the same, API is returning it as the same item, aka not optimized.
		if ($fileData->$fileType === $fileData->OriginalURL) {
			$image['image']['status'] = self::STATUS_UNCHANGED;
		} else {
			$image['image']['url'] = $fileData->$fileType;
			$image['image']['optimizedSize'] = intval($fileData->$fileSize);
		}

		// Don't download if the originalSize / OptimizedSize is the same ( same image ) . This can be non-opt result or it was not asked to be optimized( webp/avif only job i.e. )
		if ($image['image']['originalSize'] == $image['image']['optimizedSize']) {
			$image['image']['status'] = self::STATUS_UNCHANGED;
		}

		$checkFileSize = intval($fileData->$fileSize); // Size of optimized image to check against Avif/Webp

		if (false === $this->checkFileSizeMargin($originalFileSize, $checkFileSize)) {

			// Prevent this check if smartcrop is active on this image.
			if (isset($data['resize']) && 4 <> $data['resize'] )
			{
				$image['image']['status'] = self::STATUS_OPTIMIZED_BIGGER;
				$checkFileSize = $originalFileSize;
			}
		}

		if (property_exists($fileData, "WebP" . $fileType)) {
			$type = "WebP" . $fileType;
			$size = "WebP" . $fileSize;

			if ($fileData->$type == 'NC')
			{
				 $image['webp']['status'] = self::STATUS_NOT_COMPATIBLE;
			}
			elseif ($fileData->$type != 'NA') {
				$image['webp']['url'] = $fileData->$type;
				$image['webp']['size'] = $fileData->$size;
				if (false === $this->checkFileSizeMargin($checkFileSize, $fileData->$size)) {
					$image['webp']['status'] = self::STATUS_OPTIMIZED_BIGGER;
				} else {
					$image['webp']['status'] = self::STATUS_SUCCESS;
				}
			}
		}
		if (property_exists($fileData, "AVIF" . $fileType)) {
			$type = "AVIF" . $fileType;
			$size = "AVIF" . $fileSize;

			if ($fileData->$type == 'NC')
			{
				 $image['avif']['status'] = self::STATUS_NOT_COMPATIBLE;
			}
			elseif ($fileData->$type != 'NA') {
				$image['avif']['url'] = $fileData->$type;
				$image['avif']['size'] = $fileData->$size;
				if (false === $this->checkFileSizeMargin($checkFileSize, $fileData->$size)) {
					$image['avif']['status'] = self::STATUS_OPTIMIZED_BIGGER;
				} else {
					$image['avif']['status'] = self::STATUS_SUCCESS;
				}
			}

		}

		return $image;
	}


	/**
	 * Checks whether the optimised file size is within an acceptable margin of the original.
	 *
	 * Returns true when:
	 *  - $resultSize <= $fileSize (already smaller or equal),
	 *  - $fileSize == 0 (no baseline to compare against),
	 *  - the 'shortpixel/api/filesizeMargin' filter returns a negative value (check disabled),
	 *  - the percentage increase is within the margin (default 5 % via that filter), or
	 *  - both settings->useSmartcrop and settings->smartCropIgnoreSizes are true.
	 *
	 * Returns false when the optimised result is larger than the original by more than
	 * the allowed margin percentage.
	 *
	 * @param int $fileSize   Original file size in bytes (used as the baseline).
	 * @param int $resultSize Optimised file size in bytes.
	 * @return bool True if the result is acceptable; false if the result is too large.
	 */
	private function checkFileSizeMargin($fileSize, $resultSize)
	{
		// If the original filesize is bigger, it means we made it smaller, rejoice and allow.
		if ($fileSize >= $resultSize)
			return true;

		// Fine suppose, but crashes the increase
		if ($fileSize == 0)
			return true;

		$percentage = apply_filters('shortpixel/api/filesizeMargin', 5);

		// If the percentage is lower than 0, stop checking. This is a way to short-circuit this check in case optimized images always should be used.
		if ($percentage < 0) {
			return true;
		}


		$increase = (($resultSize - $fileSize) / $fileSize) * 100;

		// If the size bigger is within the defined margins, still use it .
		if ($increase <= $percentage)
			return true;


		if (\wpSPIO()->settings()->useSmartcrop == true && \wpSPIO()->settings()->smartCropIgnoreSizes == true) {
			return true;
		}

		return false;
	}

} // class
