<?php
namespace Tests;
use WP_Mock\Tools\TestCase as TestCase;

use ShortPixel\ShortPixelPlugin as ShortPixelPlugin;
use \WP_Mock;

abstract class SPIOTestCase extends TestCase
{
    protected $backupGlobalsBlacklist = array('instance');

    public function getClassObject()
    {
        if (method_exists(static::$class, 'getInstance'))
        {
            return static::$class::getInstance();
        }
        else {
            return new static::$class;
        }

        trigger_error('GetclassObject did not return something');
    }

    public function dumpVar($var)
    {
      fwrite(STDERR, var_export($var, TRUE));
    }

    public function setUp() : void
    {
      parent::setUp();
      WP_Mock::userFunction('wpSPIO')->andReturn(ShortPixelPlugin::getInstance());
    }

}

trait testInstance
{
  public function testGetInstance()
  {
      //WP_Mock::expectFilter('shortpixel/init/permissions');

      $property = $this->getInaccessibleProperty(static::$class, 'instance');

      $object = static::$class::getInstance();

      $this->assertEquals(static::$class, get_class($object));
      $this->assertIsObject($object);

      $this->assertNotNull($property->getValue());
  }
}

// Testing this. So far didn't help much(?)
/*
trait WordPressFunction
{
    public function wpSPIO()
    {
      return ShortPixelPlugin::getInstance();
    }
}
 */
