<?php

class ImageModelTest extends  WP_UnitTestCase
{

  private $fs;

  protected $image;

  public function setUp()
  {
    $this->fs = \wpSPIO()->filesystem();
  //  $this->root = vfsStream::setup('root', null, $this->getTestFiles() );
    // Need an function to empty uploads
  }


  public function testRegularImage()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/image1.jpg', $post->ID ); // this one scales

    $imageObj = $this->fs->getMediaImage($attachment_id);
    $this->image = $imageObj; // for testing more specific functions.

    $this->assertTrue($imageObj->exists());
    $this->assertTrue($imageObj->isProcessable());
    $this->assertTrue($imageObj->is_scaled());


  }

  public function testLargeImage()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/scaled.jpg', $post->ID );

    $imageObj = $this->fs->getMediaImage($attachment_id);

    $this->assertTrue($imageObj->exists());
    $this->assertTrue($imageObj->isProcessable());
    $this->assertTrue($imageObj->is_scaled());
  }

  public function testPDF()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/pdf.pdf', $post->ID );

    $imageObj = $this->fs->getMediaImage($attachment_id);
  }

  public function testNonImage()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/credits.json', $post->ID );

    $imageObj = $this->fs->getMediaImage($attachment_id);
  }


  public function testExcludedSizes();
  public function testExcludedPatterns();
  public function test


} // class
