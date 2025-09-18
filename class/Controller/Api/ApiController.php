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

class ApiController extends RequestManager
{
	// Moved these numbers higher to prevent conflict with STATUS
	const ERR_FILE_NOT_FOUND = -902;
	const ERR_TIMEOUT = -903;
	const ERR_SAVE = -904;
	const ERR_SAVE_BKP = -905;
	const ERR_INCORRECT_FILE_SIZE = -906;
	const ERR_DOWNLOAD = -907;
	const ERR_PNG2JPG_MEMORY = -908;
	const ERR_POSTMETA_CORRUPT = -909;
	const ERR_UNKNOWN = -999;

	const DOWNLOAD_ARCHIVE = 7;

	//private $apiEndPoint;
	private $apiDumpEndPoint;

	protected static $temporaryFiles = array();
	protected static $temporaryDirs = array();

	public function __construct()
	{
		$settings = \wpSPIO()->settings();
		$this->apiEndPoint = $settings->httpProto . '://' . SHORTPIXEL_API . '/v2/reducer.php';
		$this->apiDumpEndPoint = $settings->httpProto . '://' . SHORTPIXEL_API . '/v2/cleanup.php';
	}

	/*
	 * @param Object $item Item of stdClass
	 * @return Returns same Item with Result of request
	 */
	public function processMediaItem(QueueItem $qItem, ImageModel $imageModel)
	{
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

	/* Ask to remove the items from the remote cache.
		 @param $item Must be object, with URLS set as array of urllist. - Secretly not a mediaItem - shame
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
	 *
	 **/
	protected function handleResponse(QueueItem $qItem, $response)
	{

		$APIresponse = $this->parseResponse($response);//get the actual response from API, its an array

		// Don't know if it's this or that.
		$status = false;
		if (isset($APIresponse['Status'])) {
			$status = $APIresponse['Status'];
		} elseif (is_array($APIresponse) && isset($APIresponse[0]) && property_exists($APIresponse[0], 'Status')) {
			$status = $APIresponse[0]->Status;
		}

		if (isset($APIresponse['returndatalist'])) {
			$returnDataList = (array) $APIresponse['returndatalist'];
			if (isset($returnDataList['sizes']) && is_object($returnDataList['sizes']))
				$returnDataList['sizes'] = (array) $returnDataList['sizes'];

			if (isset($returnDataList['doubles']) && is_object($returnDataList['doubles']))
				$returnDataList['doubles'] = (array) $returnDataList['doubles'];

			if (isset($returnDataList['duplicates']) && is_object($returnDataList['duplicates']))
				$returnDataList['duplicates'] = (array) $returnDataList['duplicates'];

			if (isset($returnDataList['fileSizes']) && is_object($returnDataList['fileSizes']))
				$returnDataList['fileSizes'] = (array) $returnDataList['fileSizes'];

			unset($APIresponse['returndatalist']);
		} else {
			$returnDataList = [];
		}

		// This is only set if something is up, otherwise, ApiResponse returns array
		if (is_object($status)) {
			// Check for known errors. : https://shortpixel.com/api-docs
			Log::addDebug('Api Response Status :' . $status->Code);
			switch ($status->Code) {
				case -102: // Invalid URL
				case -105: // URL missing
				case -106: // Url is inaccessible
				case -113: // Too many inaccessible URLs
				case -201: // Invalid image format
				case -202: // Invalid image or unsupported format
				case -203: // Could not download file
					return $this->returnFailure(self::STATUS_ERROR, $status->Message);
					break;
				case -403: // Quota Exceeded
				case -301: // The file is larger than remaining quota
					// legacy
					@delete_option('bulkProcessingStatus');
					QuotaController::getInstance()->setQuotaExceeded();

					return $this->returnRetry(self::STATUS_QUOTA_EXCEEDED, __('Quota exceeded.', 'shortpixel-image-optimiser'));
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
				case -500: // API in maintenance.
					//return array("Status" => self::STATUS_MAINTENANCE, "Message" => $APIresponse['Status']->Message);
					return $this->returnRetry(self::STATUS_MAINTENANCE, $status->Message);
			}
		}

		$neededURLS = $qItem->data()->urls; // URLS we are waiting for.

		if (is_array($APIresponse) && isset($APIresponse[0])) //API returned image details
		{

			if (!isset($returnDataList['sizes'])) {
				return $this->returnFailure(self::STATUS_FAIL, __('Item did not return image size information. This might be a failed queue item. Reset the queue if this persists or contact support', 'shortpixel-image-optimiser'));
			}

			$analyze = array('total' => count($neededURLS), 'ready' => 0, 'waiting' => 0);
			$waitingDebug = array();

			$imageList = [];
			$partialSuccess = false;
			$imageNames = array_keys($returnDataList['sizes']);
			$fileNames = array_values($returnDataList['sizes']);

			foreach ($APIresponse as $index => $imageObject) {
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
					if (is_null($fileData) || false === property_exists($fileData, $imageName)) {
						$imageList[$imageName] = $this->handleNewSuccess($qItem, $imageObject, $data);
					} else {
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
						"Code" => (isset($APIresponse[0]->Status->Code) ? $APIresponse[0]->Status->Code : self::ERR_UNKNOWN),
						"Message" => __('There was an error and your request was not processed.', 'shortpixel-image-optimiser')
							. " (" . wp_basename($APIresponse[0]->OriginalURL) . ": " . $APIresponse[0]->Status->Message . ")"
					);
					return $this->returnRetry($err['Code'], $err['Message']);
				} else {
					$err = array(
						"Status" => self::STATUS_FAIL,
						"Message" => __('There was an error and your request was not processed.', 'shortpixel-image-optimiser'),
						"Code" => (isset($APIresponse[0]->Status->Code) ? $APIresponse[0]->Status->Code : self::ERR_UNKNOWN)
					);
					return $this->returnRetry($err['Code'], $err['Message']);
				}
			}

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
	 * When API signals it's done optimizing an image.
	 * @param  Object $item                   Queue Item object with all settings
	 * @param  Object $fileData               API response with image URLS
	 * @param  Array $data                   Data is filename, imagename, filesize (optionally) from returnDataList
	 * @return Array           Array with processed image data (url, size, webp, avif)
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

			return $this->returnFailure(self::STATUS_FAIL, __('Internal error, missing variables'));
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
			if (isset($data['resize']) && 4 == $data['resize'] )
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
	 *  Function to check if the filesize of the imagetype (webp/avif) is smaller, or within bounds of size to be stored. If not, the webp is not downloaded and uses.
	 *
	 * @param  int $fileSize                 Filesize of the original
	 * @param  int $resultSize               Filesize of the optimized image
	 * @return [type]             [description]
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
