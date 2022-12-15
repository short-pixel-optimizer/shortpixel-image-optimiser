<?php
namespace ShortPixel\Tests\Controller;

use ShortPixel\Tests\SPIO_UnitTestCase as SPIO_UnitTestCase;
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ApiController as ApiController;

class ApiControllerTest extends SPIO_UnitTestCase
{

	protected static $reflectionClasses = array(
		 'apiController' => 'ShortPixel\Controller\ApiController'
	);

	public function setUp() :void
  {
		parent::setUp();
    $this->settings()::resetOptions();

  //  $this->root = vfsStream::setup('root', null, $this->getTestFiles() );
    // Need an function to empty uploads
  }

	// Basic Success response of one item.
	protected function getBasicResponse($number = 1)
	{
 		$result = array();
		$dataUrls = array();
		for ($i = 0; $i < $number; $i++)
		{
			$result[] = (object) array(
				 'Status' =>
				(object) array(
					 'Code' => '2',
					 'Message' => 'Success',
				),
				 'OriginalURL' => 'https://example.org/wp-content/uploads/2022/11/image.jpg?ver=1667861617',
				 'LosslessURL' => 'http://api.shortpixel.com/lossless.jpg',
				 'LossyURL' => 'http://api.shortpixel.com/lossy.jpg',
				 'WebPLosslessURL' => 'http://api.shortpixel.com/lossless.webp',
				 'WebPLossyURL' => 'http://api.shortpixel.com/lossy.webp',
				 'AVIFLosslessURL' => 'NA',
				 'AVIFLossyURL' => 'NA',
				 'OriginalSize' => '20000',
				 'LosslessSize' => '10000',
				 'LoselessSize' => '10000',
				 'LossySize' => '5000',
				 'WebPLosslessSize' => 10000,
				 'WebPLoselessSize' => 10000,
				 'WebPLossySize' => 5000,
				 'AVIFLosslessSize' => 'NA',
				 'AVIFLossySize' => 'NA',
				 'TimeStamp' => '2022-12-13 21:07:44',
				 'PercentImprovement' => '16.91',
			 );

			 $dataUrls['image_' . $i] = 'image_' . $i . '.jpg';
		}

	 $rdatalist =
	 					(object) array(
	 						 'sizes' =>
	 						(object) $dataUrls,
	 						 'doubles' =>
	 						array (
	 						),
	 						 'duplicates' =>
	 						(object) array(
	 							 'large_same' => 'large',
	 						),

		);
		$result['returndatalist'] = $rdatalist;
	  return $result; // This changes certain arrays to objects which is important
	}

	protected function getErrorResponse()
	{
			$result = array (
		  0 =>
		  (object) array(
		     'Status' =>
		    (object) array(
		       'Code' => '-203',
		       'Message' => 'Could not download file.',
		    ),
		     'OriginalURL' => 'https://example.org/wp-content/uploads/2022/11/image.jpg?ver=1667861617',
		  ),
		  1 =>
		  (object) array(
		     'Status' =>
		    (object) array(
		       'Code' => '-203',
		       'Message' => 'Could not download file.',
		    ),
		     'OriginalURL' => 'https://example.org/wp-content/uploads/2022/11/image.jpg?ver=1667861617',
		  )
		);
	 return $result; // This changes certain arrays to objects which is important
	}

	protected function getGlobalError()
	{
		 $result = array (
  	 'Status' =>
  		(object) array(
     'Code' => -402,
     'Message' => 'Wrong API Key.',
  	),
	);
 		return $result; // This changes certain arrays to objects which is important
	}

	// Basis of 1 item, + webp. Can be extended by tests.
	// Default test for lossy
	// For now most counts are off here, since it's not important for ApiController.
	protected function getItem($number = 1)
	{
		$item = (object) array( 'compressionType' => '1', 'urls' => array (), 'paramlist' => array ( ) , 'returndatalist' => array ( 'sizes' => array ( 'shortpixel_main_donotuse' => 'image.jpg',), 'doubles' => array ( ), 'duplicates' => array ( ), ), 'counts' => (object) array( 'creditCount' => 2, 'baseCount' => 1, 'avifCount' => 0, 'webpCount' => 1, ), 'item_id' => 1, 'tries' => 5 );

		$baseUrl = 'http://example.org/wp-content/uploads/';

		for ($i =0; $i < $number; $i++)
		{
				$item->urls[] = $baseUrl . 'image_' . $i . '.jpg';
		}
		return $item;
	}

	 public function testGlobalError()
	 {
		 	 $globalError = $this->getGlobalError();
		 	 $response = array('body' => json_encode($globalError));
			 $item = $this->getItem();

			 $apiController = ApiController::getInstance();
			 $method = $this->getProtectedMethod('apiController', 'handleResponse');

			 $result = $method->invoke($apiController, $item, $response);

			 $this->assertEquals(ApiController::STATUS_NO_KEY, $result->apiStatus);
			 $this->assertTrue($result->is_error);
			 $this->assertTrue($result->is_done);

			 //@todo Add some more error codes here and check responses.
			 $globalError->Status->Code = -903;

			 $result = $method->invoke($apiController, $item, $response);

			 
	 }

	 public function testItemErrors()
	 {
		   $itemError = $this->getErrorResponse();
			 $response = array('body' => json_encode($itemError));
			 $item = $this->getItem();

			 $apiController = ApiController::getInstance();
			 $method = $this->getProtectedMethod('apiController', 'handleResponse');

			 $result = $method->invoke($apiController, $item, $response);

			 $this->assertEquals($result->apiStatus, ApiController::STATUS_ERROR);
			 $this->assertTrue($result->is_error);
			 $this->assertTrue($result->is_done);

	 }

	 // Test Responses with Handle Response.  More Fine-grained tests should be added to the test of function where they are invoked.
	 public function testHandleResponse()
	 {
		  $itemResponse = $this->getBasicResponse();
			$response = array('body' => json_encode($itemResponse));
			$item = $this->getItem();

			$apiController = ApiController::getInstance();
			$method = $this->getProtectedMethod('apiController', 'handleResponse');


			// Success
			$result = $method->invoke($apiController, $item, $response);

			$this->assertEquals(ApiController::STATUS_SUCCESS, $result->apiStatus);
			$this->assertFalse($result->is_error);
			$this->assertTrue($result->is_done);

			// Waiting
			$itemResponse[0]->Status->Code = 1;
			$response = array('body' => json_encode($itemResponse));

			$result = $method->invoke($apiController, $item, $response);

			$this->assertEquals(ApiController::STATUS_UNCHANGED, $result->apiStatus);
		 	$this->assertFalse($result->is_error);
			$this->assertFalse($result->is_done);

			// Test with two items
			$itemResponse = $this->getBasicResponse(2);
			$item = $this->getItem(2);
			//echo "<PRE>"; var_dump($itemResponse); echo "</PRE>";

			$response = array('body' => json_encode($itemResponse));
			$result = $method->invoke($apiController, $item, $response);

			$this->assertEquals(ApiController::STATUS_SUCCESS, $result->apiStatus);
			$this->assertFalse($result->is_error);
			$this->assertTrue($result->is_done);

			$responseModel = ResponseController::getResponseItem($item->item_id);

			$this->assertEquals(2, $responseModel->images_total);
			$this->assertEquals(2, $responseModel->images_done);


			// Partial Success.
			$itemResponse[1]->Status->Code = 1;
			$response = array('body' => json_encode($itemResponse));
			$result = $method->invoke($apiController, $item, $response);

			$this->assertEquals(ApiController::STATUS_PARTIAL_SUCCESS, $result->apiStatus);
			$this->assertFalse($result->is_error);
			$this->assertFalse($result->is_done);


	 }

	 public function testNewSuccess()
	 {

		 $itemResponse = $this->getBasicResponse();
		// $returnDataList = $itemResponse['returnDataList'];
		 $item = $this->getItem();

		 $data = array(
			 'fileName' => 'image.jpg',
			 'imageName' => 'shortpixel_main_donotuse',
		 );

		 $fileData = $itemResponse[0];

		 $apiController = ApiController::getInstance();
		 $method = $this->getProtectedMethod('apiController', 'handleNewSuccess');

		 $image = $method->invoke($apiController, $item, $fileData, $data);

		 // Test Image Data .
		 $this->assertIsArray($image['image']);
		 $this->assertNotFalse($image['image']['url']);
		 $this->assertEquals(ApiController::STATUS_SUCCESS, $image['image']['status']);
		 $this->assertEquals(5000, $image['image']['optimizedSize']);
		 $this->assertEquals(20000, $image['image']['originalSize']);

		 	// Test Webp Data (should be here)
			$this->assertIsArray($image['webp']);
			$this->assertNotFalse($image['webp']['url']);
			$this->assertEquals(5000, $image['webp']['size']);
			$this->assertEquals(ApiController::STATUS_SUCCESS, $image['webp']['status']);

			// Test Avif Data (should not be here)
			$this->assertIsArray($image['avif']);
			$this->assertFalse($image['avif']['url']);
			$this->assertFalse($image['avif']['size']);
			$this->assertEquals(ApiController::STATUS_SKIP, $image['avif']['status']);


			$fileData->OriginalSize = 15000;
			$fileData->LossySize = 20000;
			$fileData->WebPLossySize = 20000;

			$image = $method->invoke($apiController, $item, $fileData, $data);

			$this->assertEquals(ApiController::STATUS_OPTIMIZED_BIGGER, $image['image']['status']);
			$this->assertEquals($fileData->LossySize, $image['image']['optimizedSize']);
			$this->assertEquals($fileData->OriginalSize, $image['image']['originalSize']);

			$this->assertEquals(ApiController::STATUS_OPTIMIZED_BIGGER, $image['webp']['status']);


	 }

}
