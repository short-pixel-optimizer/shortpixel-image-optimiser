<?php

namespace Tests\Model;

use ShortPixel\Model\FrontImage;
use Tests\SPIOTestCase;

/**
 * Class FrontImageTest.
 *
 * @covers \ShortPixel\Model\FrontImage
 */
class FrontImageTest extends SPIOTestCase
{

    public function testLoadImageDom(): void
    {
        $content = $this->getRegularSourceSet();

        // LoadImageDom is called in construct, so we don't do that.
        $front = new FrontImage($content);

        $property = $this->getInaccessibleProperty($front, 'raw');
        $this->assertEquals($content, $property->getValue());

        $property = $this->getInaccessibleProperty($front, 'is_parsable');
        $this->assertTrue($property->getValue());

        $property = $this->getInaccessibleProperty($front, 'id');
        $this->assertEquals('test', $property->getValue());

        $property = $this->getInaccessibleProperty($front, 'alt');
        $this->assertEquals('alt-test', $property->getValue());

        $property = $this->getInaccessibleProperty($front, 'src');
        $this->assertEquals('http://example.com/wp-content/uploads/2023/01/image-1024x678.jpg', $property->getValue());

        $property = $this->getInaccessibleProperty($front, 'srcset');
        $this->assertIsString($property->getValue());

        $property = $this->getInaccessibleProperty($front, 'class');
        $this->assertEquals('wp-image', $property->getValue());

        $property = $this->getInaccessibleProperty($front, 'width');
        $this->assertEquals('1024', $property->getValue());

        $property = $this->getInaccessibleProperty($front, 'height');
        $this->assertEquals('678', $property->getValue());

        $property = $this->getInaccessibleProperty($front, 'style');
        $this->assertIsNull($property->getValue());

        $property = $this->getInaccessibleProperty($front, 'sizes');
        $this->assertEquals('(max-width: 1024px) 100vw, 1024px', $property->getValue());

        $property = $this->getInaccessibleProperty($front, 'attributes');
        $this->assertNotIsNull($property->getValue());
        $this->assertIsArray($property->getValue());


        /** @todo This test is incomplete. */
        //$this->markTestIncomplete();
    }

    public function testHasBackground(): void
    {
        $content = $this->getImageWithBackGround();
        $front = new FrontImage($content);
        $this->assertTrue($front->hasBackground());

        $content = $this->getRegularSourceSet();
        $front = new FrontImage($content);
        $this->assertFalse($front->hasBackground());

    }

    public function testHasPreventClasses(): void
    {
        /** @todo This test is incomplete. */
        $this->markTestIncomplete();
    }

    public function testHasSource(): void
    {
        /** @todo This test is incomplete. */
        $this->markTestIncomplete();
    }

    public function testIsParseable(): void
    {
        /** @todo This test is incomplete. */
        $this->markTestIncomplete();
    }

    public function testGetImageData(): void
    {
        /** @todo This test is incomplete. */
        $this->markTestIncomplete();
    }

    public function testGetImageBase(): void
    {
        /** @todo This test is incomplete. */

        $content = $this->getNoImageHTML();
        $front = new FrontImage($content);

        // Test Null
        $this->assertIsNull($front->getImageBase());

        // Test
        $content = $this->getRegularSourceSet();
        $front = new FrontImage($content);
        $this->assertIsString($front->getImageBase());

        

        $this->markTestIncomplete();
    }

    public function testParseReplacement(): void
    {
        /** @todo This test is incomplete. */
        $this->markTestIncomplete();
    }

    // A dump from what FrontController / Convert feeds to FrontImage via WP
    private function getRegularSourceSet()
    {
      $image = '<img decoding="async" fetchpriority="high" id="test" width="1024" height="678" src="http://example.com/wp-content/uploads/2023/01/image-1024x678.jpg" alt="alt-text" class="wp-image" srcset="http://example.com/wp-content/uploads/2023/01/image-1024x678.jpg 1024w, http://example.com/wp-content/uploads/2023/01/image-559x370.jpg 559w, http://example.com/wp-content/uploads/2023/01/image-768x509.jpg 768w, http://example.com/wp-content/uploads/2023/01/image-1536x1017.jpg 1536w, http://example.com/wp-content/uploads/2023/01/image-2048x1356.jpg 1812w, http://example.com/wp-content/uploads/2023/01/image-600x397.jpg 600w, http://example.com/wp-content/uploads/2023/01/image-scaled.jpg 1811w" sizes="(max-width: 1024px) 100vw, 1024px" />';
      return $image;
    }

    // Test data which should not be fed to the model
    private function getNoImageHTML()
    {
        $html = '<h2 class="wp-block-heading">Title Block</h2><p>Gutenberg test block. Some generated content etc</p>';
        return $html;
    }


    // Image with a defined style background on it.
    private function getImageWithBackGround()
    {
       $html = '<img decoding="async" fetchpriority="high" id="test" width="1024" height="678" src="http://example.com/wp-content/uploads/2023/01/image-1024x678.jpg" alt="alt-text" style="background: url(\'http://example.com/wp-content/uploads/2023/01/image-1024x678.jpg\';")';
       return $html;
    }
}
