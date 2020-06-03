<?php

class DummyTestClassTest extends \Codeception\TestCase\WPTestCase
{
    /**
     * @var \UnitTester
     */
//    protected $tester;
//
//    public function setUp()
//    {
//        // Before...
//        parent::setUp();
//
//        // Your set up methods here.
//    }
//
//    public function tearDown()
//    {
//        // Your tear down methods here.
//
//        // Then...
//        parent::tearDown();
//    }

    // Tests
    public function test_it_works()
    {
//        $post = static::factory()->post->create_and_get();
//
//        $this->assertInstanceOf(\WP_Post::class, $post);
	    assertTrue(1 == 2 - 1);
    }
}
