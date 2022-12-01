<?php
namespace ShortPixel\Tests\Model;

use ShortPixel\Tests\SPIO_UnitTestCase as SPIO_UnitTestCase;
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\Converter\MediaLibraryPNGConverter as PNGConverter;

class PNGConverterTest extends SPIO_UnitTestCase
{

  private static $fs;

	protected $model;

	protected static $attachmentAssets = array('png-test.png', 'png-transparant.png', 'desk.png', 'icecream-cropped-scaling.png');


  public function setUp() :void
  {
    $this->settings()::resetOptions();

  //  $this->root = vfsStream::setup('root', null, $this->getTestFiles() );
    // Need an function to empty uploads
  }

  public function testgetPNGImage()
  {
			// First settings
			$this->settings()->png2jpg = 1;

			$image = $this->getMediaImage('png-test.png');


      $converter = new PNGConverter($image);
      $refWPQ = new \ReflectionClass('ShortPixel\Model\Converter\MediaLibraryPNGConverter');
      $getMethod = $refWPQ->getMethod('getPNGImage');
      $getMethod->setAccessible(true);


			// Is Convertable sets required imageModel
			$this->assertTrue( $converter->isConvertable());
      $this->assertInstanceOf('gdImage', $getMethod->invoke($converter));

			$image_trans = $this->getMediaImage('png-transparant.png');
			$converter = new PNGConverter($image_trans);

			$this->assertTrue( $converter->isConvertable());
      $this->assertInstanceOf('gdImage', $getMethod->invoke($converter));

  }


  public function testPNGTransparent()
  {


    $refWPQ = new \ReflectionClass('ShortPixel\Model\Converter\MediaLibraryPNGConverter');
    $transMethod = $refWPQ->getMethod('isTransParent');
    $transMethod->setAccessible(true);

		$image = $this->getMediaImage('png-test.png');
		$converter = new PNGConverter($image);
		//$this->assertTrue( $converter->isConvertable());
		$this->assertFalse( $transMethod->invoke($converter));


		$image_trans = $this->getMediaImage('png-transparant.png');
		$converter = new PNGConverter($image_trans);
		//$this->assertTrue( $converter->isConvertable());
    $this->assertTrue ( $transMethod->invoke($converter));

  }

  public function testConversionUsual()
  {

    $fs = $this->filesystem();

    $settings = \wpSPIO()->settings();
    $settings->png2jpg = 0; // off

		$image = $this->getMediaImage('png-test.png');
		$converter = new PNGConverter($image);

		$this->assertFalse($converter->convert());

		$settings->png2jpg = 1;
    $settings->backupImages = 0; // test one without backup.

    // Second one should work.  Without Backup.
		$converter = new PNGConverter($image);
    $result = $converter->convert();

    $this->assertTrue($result);

		$attach_id = $this->getAttachmentAsset('png-test.png');
    $image = $this->filesystem()->getMediaImage($attach_id);

    $this->assertEquals('jpg', $image->getExtension());
    $this->assertEquals('png-test.jpg', $image->getFileName());
    $this->assertEquals('png-test', $image->getFileBase());

    $this->assertEquals($image->getFullPath(), get_attached_file($attach_id));

    $post = get_post($attach_id);
    $this->assertEquals('image/jpeg', $post->post_mime_type);

    // @todo Test thumbnails, see if generated
    $imageObj = $this->filesystem()->getMediaImage($this->getAttachmentAsset('png-test.png'));

  }

	public function testConvertTransparent()
	{
		$this->settings()->png2jpg = 1; // no transparency.

		$image_trans = $this->getMediaImage('png-transparant.png');
		$converter = new PNGConverter($image_trans);

		// Should fail.  (transparency)
		$result = $converter->convert();
		$this->assertFalse($result);

		// Test. With Force transparency, with backup.
    $this->settings()->png2jpg = 2;
    $this->settings()->backupImages = 1; // test one with backup.

		$converter = new PNGConverter($image_trans);
		 $result = $converter->convert();

     $this->assertTrue($result);

 		 $image = $this->getMediaImage('png-transparant.png');
		 $attach_id = $this->getAttachmentAsset('png-transparant.png');

     $this->assertEquals('jpg', $image->getExtension());
     $this->assertEquals('png-transparant.jpg', $image->getFileName());
     $this->assertEquals('png-transparant', $image->getFileBase());

     $this->assertEquals($image->getFullPath(), get_attached_file($attach_id));
     $post = get_post($attach_id);
     $this->assertEquals('image/jpeg', $post->post_mime_type);


	}

  public function testMediaLibraryConversion()
  {
		$mediaObj =  $this->getMediaImage('desk.png');

    $this->settings()->png2jpg = 1; // normal, no transparancy.
    $this->settings()->backupImages = 1; // backup on.

    $oldfullpath = $mediaObj->getFullPath();

    $this->assertIsObject($mediaObj);
    $this->assertEquals('png', $mediaObj->getExtension() );

    $this->assertTrue($mediaObj->isProcessable());  // This set png2jpg is setting active.
		$this->assertFalse($mediaObj->getMeta('tried_png2jpg'), 'triedpng2jpg');
    $this->assertTrue($mediaObj->get('do_png2jpg'), 'is' . $mediaObj->get('do_png2jpg'));

    $bool = $mediaObj->convertPNG();
    $this->assertTrue($bool);

    $this->assertFileDoesNotExist($oldfullpath);

    // basically the old object stops existing.
    $this->assertEquals('jpg', $mediaObj->getExtension());
    $this->assertTrue($mediaObj->exists());
    $this->assertTrue($mediaObj->getMeta('did_png2jpg'));
    $this->assertFileDoesNotExist($mediaObj->getFileDir() . $mediaObj->getFileBase() . '.png');
		$this->assertTrue($mediaObj->hasBackup());

    $thumbnails = $mediaObj->get('thumbnails');

    foreach($thumbnails as $thumbObj)
    {
       $this->assertEquals('jpg', $thumbObj->getExtension());
       $this->assertTrue($thumbObj->exists(), $thumbObj->getFullPath());
       $this->assertTrue($thumbObj->getMeta('did_png2jpg'));
       $this->assertFileDoesNotExist($thumbObj->getFileDir() . $thumbObj->getFileBase() . '.png');
			 $this->assertFalse($thumbObj->hasBackup(), $thumbObj->getBackupFile() );
    }

    // Retry on already converted, should fail because converted.
    $bool = $mediaObj->convertPNG();
    $this->assertFalse($bool);
		$this->assertTrue($mediaObj->hasBackup());


    // Restore backup, check if it's jpg.
    $mediaObj->restore();

		$mediaObj =  $this->getMediaImage('desk.png');

    $this->assertEquals('png', $mediaObj->getExtension(), $mediaObj);
    $this->assertTrue($mediaObj->exists());
    $this->assertFalse($mediaObj->getMeta('did_png2jpg'));
    $this->assertFileDoesNotExist($mediaObj->getFileDir() . $mediaObj->getFileBase() . '.jpg');
		$this->assertFalse($mediaObj->hasBackup());

		$thumbnails = $mediaObj->get('thumbnails');

    foreach($thumbnails as $thumbObj)
    {
       $this->assertEquals('png', $thumbObj->getExtension());
       $this->assertTrue($thumbObj->exists());
       $this->assertFalse($thumbObj->getMeta('did_png2jpg'));
       $this->assertFileDoesNotExist($thumbObj->getFileDir() . $thumbObj->getFileBase() . '.jpg');
    }
  }

	//@todo Add here testcases for conversion with scaled.
	/* Here i figured out PNG files never scale :/
	public function testMediaLibraryConversionWithScaled()
	{
		 	$mediaObj =  $this->getMediaImage('icecream-cropped-scaling.png');

			$this->settings()->png2jpg = 2; // burn!.
			$this->settings()->backupImages = 1; // backup on.

			$bool = $mediaObj->convertPNG();
	    $this->assertTrue($bool);
			$this->assetTrue($mediaObj->isScaled());

			var_dump($mediaObj->getBackupFile());

			$this->assertEquals('jpg', $mediaObj->getExtension());
			$this->assertFalse($mediaObj->hasBackup());

			$orFile = $mediaObj->getOriginalFile();

			$this->assertIsObject($orFile);
			$this->assertTrue($orFile->hasBackup());

			$mediaObj->restore();

			$mediaObj =  $this->getMediaImage('icecream-cropped-scaling.png');
			$this->assertEquals('png', $mediaObj->getExtension());

	}

*/


}  // class
