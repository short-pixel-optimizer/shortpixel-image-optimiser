<?php
namespace Tests\Model;
//use WP_Mock\Tools\TestCase as TestCase;
use \ShortPixel\Model\AccessModel as AccessModel;
use ShortPixel\Controller\QuotaController as QuotaController;

use Factory\DataFactory\QuotaDataFactory as QuotaDataFactory;

use Tests\SPIOTestCase;
use Tests\testInstance;
use Tests\WordPressFunction;

//use function wpSPI;

use \WP_Mock;
use \Mockery;

class AccessModelTest extends SPIOTestCase
{
    use testInstance; // Instanced class, test it.
  //  use WordPressFunction;

    static $class = 'ShortPixel\Model\AccessModel';

    public function testNoticeIsAllowed()
    {
        $fs = wpSPIO()->filesystem();

        $notice = new \stdClass;
        $accessModel = $this->getClassObject();

        $user = $this->wpUser();
        WP_Mock::userFunction('wp_get_current_user')->once()->andReturn($user);
        WP_Mock::userFunction('wp_get_current_user')->once()->andReturn($user);

        $bool = $accessModel->noticeIsAllowed($notice);

        $this->assertIsBool($bool);
        $this->assertTrue($bool);

        $bool = $accessModel->noticeIsAllowed($notice);
        $this->assertIsBool($bool);
        $this->assertFalse($bool);
    }

    public function testUserisAllowed()
    {
      $user = $this->wpUser();
      $accessModel = $this->getClassObject();

      WP_Mock::userFunction('wp_get_current_user')->once()->andReturn($user);
      WP_Mock::userFunction('wp_get_current_user')->once()->andReturn($user);

      $bool = $accessModel->userIsAllowed('test');

      $this->assertIsBool($bool);
      $this->assertTrue($bool);

      $bool = $accessModel->userIsAllowed('test');
      $this->assertIsBool($bool);
      $this->assertFalse($bool);
    }

    public function testIsFeatureAvailable()
    {
        $quota = new \stdClass;

        $mock = \Mockery::mock('overload:ShortPixel\Controller\QuotaController');
        $mock->shouldReceive('getInstance')->andReturn($mock);

        $factory = new QuotaDataFactory('spio');
        $data1 = $factory->set('unlimited', 'HARK')->returnData('object');
        $data2 = $factory->set('unlimited', true)->returnData('object');

        $mock->shouldReceive('getQuota')->once()->andReturn($data1);
        $mock->shouldReceive('getQuota')->once()->andReturn($data2);

        $accessModel = $this->getClassObject();

        // Test with something generally available / default
        $bool = $accessModel->isFeatureAvailable('webp');

        $this->assertisBool($bool);
        $this->assertTrue($bool);

        // Test with normal quota subscription - avif should be av.
        $bool = $accessModel->isFeatureAvailable('avif');
        $this->assertisBool($bool);
        $this->assertTrue($bool);

        // Test with unlimited account - avif should *not* beincluded
        //
        $bool = $accessModel->isFeatureAvailable('avif');
        $this->assertisBool($bool);
        $this->assertFalse($bool);
    }

    private function wpUser()
    {
        $user = \Mockery::mock(\WP_User::class);
        $user->shouldReceive('has_cap')->andReturn(true, false);
        return $user;
    }
/*
    private function mockQuota
    {

    } */

}
