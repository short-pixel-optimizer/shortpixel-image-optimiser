<?php

use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Model\ResponseModel as ResponseModel;

class ResponseControllerTest extends WP_UnitTestCase
{
      public function  setUp()
      {
          ResponseController::clear();
      }

      public function testAddItem()
      {
          $this->assertCount(0, ResponseController::getAll());

          $response = ResponseController::add();

          $this->assertIsObject($response);
          $this->assertCount(1 ,ResponseController::getAll());

      }

      public function testWithAttributes()
      {
          $this->assertCount(0, ResponseController::getAll()); //test if empty

          $response = ResponseController::add()->withMessage('test')->asImportant()->asError();

          $this->assertEquals('test', $response->message);
          $this->assertEquals(ResponseModel::RESPONSE_ERROR, $response->code);
          $this->assertEquals(10, $response->priority);

          $responses = ResponseController::getAll();
          $response = $responses[0];

          $this->assertEquals('test', $response->message);
          $this->assertEquals(ResponseModel::RESPONSE_ERROR, $response->code);
          $this->assertEquals(10, $response->priority);

      }

}
