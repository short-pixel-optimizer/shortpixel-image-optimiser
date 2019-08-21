<?php
use \org\bovigo\vfs\vfsStream;

class FileSystemTest extends  WP_UnitTestCase
{

  protected $fs;
  protected $root;

  public function setUp()
  {
    $this->fs = new ShortPixel\FileSystemController();
    $this->root = vfsStream::setup('root', null, $this->getTestFiles() );
  }

  public function tearDown()
  {
      $uploaddir = wp_upload_dir();
      $dir = $uploaddir['basedir'];
  }

  public function setupBackUps()
  {
    $backup = $this->fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
    $result = $backup->check(true);

    //$this->assertTrue($result);
  //  $this->assertDirectoryExists(SHORTPIXEL_BACKUP_FOLDER);
  }

  public function finishBackups()
  {
    $this->removeDir('/tmp/wordpress/wp-content/uploads/ShortpixelBackups/');
  }

  private function removeDir($path)
  {
      if ($path == '' || $path == '/')
        exit('Wrong Path');

	   $files = array_diff(scandir($path), array('.', '..'));
	    foreach ($files as $file) {
		        (is_dir("$path/$file")) ? $this->removeDir("$path/$file") : unlink("$path/$file");
	    }
	      rmdir($path);
  }


  private function getTestFiles()
  {
    $content = file_get_contents(__DIR__ . '/assets/test-image.jpg');

    $ar = array(
        'images' => array('image1.jpg' => $content, 'image2.jpg' => $content, 'image3.png' => $content,
                          'image1.ext.jpg' => $content,
                          'ашдутфьу.jpg' => $content,
                          'اسم الملف.jpg' => $content,

        ),
        'wp-content' => array('uploads' => array('2019' => array('07' => array('wpimg1.jpg' => $content, 'wpimg2.jpg' => $content)))),
    );
    return $ar;
  }

  /** Not testable on VFS due to home-path checks
   * This test is done first since it erares to log file needed to read other tests.
   */
  public function testsetAndGetBackup()
  {
      $this->finishBackups(); // removes directory.
      $this->setupBackUps();
      //$filepath = $this->root->url() . '/images/image3.png';
      $post = $this->factory->post->create_and_get();
      $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/test-image.jpg', $post->ID );

      //vfsStream::newDirectory(SHORTPIXEL_BACKUP_FOLDER, 0755)->at($this->root);

      $file = $this->fs->getFile(get_attached_file($attachment_id));

      $this->assertTrue($file->exists());
      $this->assertTrue($file->is_writable());

      $backupFile = $file->getBackUpFile();

      $this->assertFalse($backupFile);

      $directory = $this->fs->getBackupDirectory($file);

      $this->assertIsObject($directory);
      $this->assertDirectoryExists((string) $directory);

      \ShortPixelApi::backupImage($file, array($file));

      $this->assertFileExists( $directory->getPath() . '/' . $file->getFileName()) ;

    //$URLsAndPATHs = $itemHandler->getURLsAndPATHs(false);
  }

  public function testBasicDirectory()
  {
      $dirpath = $this->root->url() . '/basic';
      $directory = $this->fs->getDirectory($dirpath);

      // First test. Non existing directory should not exist.
      $this->assertFalse($directory->exists(), $directory->getPath());

      // Check should Create a directory.
      $directory->check();

      $this->assertTrue(is_dir($dirpath)); //basically test if vfs is doing is good.
      $this->assertTrue($directory->exists());
      $this->assertDirectoryExists($dirpath);
      $this->assertDirectoryIsReadable($dirpath);
      $this->assertDirectoryIsWritable($dirpath);

      // Test if cast and return are the same
      $this->assertEquals((String) $directory, $directory->getPath());
      $this->assertEquals($directory->getPath(), $dirpath . '/');

      // Test for trailingslash consistency
      $dirpath2 = $this->root->url() . '/basic/';
      $directory2 = $this->fs->getDirectory($dirpath);

      $this->assertEquals($directory2->getPath(), $dirpath2);
      $this->assertEquals($directory2->getPath(), $dirpath . '/');
      $this->assertEquals($directory2->getPath(), $directory->getPath());

      // When feeding a file. Non-existing.
      $dirpath3 = $this->root->url() . '/basic/accidental_file.png';
      $goodpath3 = $this->root->url() . '/basic/';
      $directory3 = $this->fs->getDirectory($dirpath3);

      $this->assertTrue($directory3->exists(), $directory3->getPath());
      $this->assertEquals($goodpath3, $directory3->getPath());

      // When feeding a file, existing.
      $dirpath4 = $this->root->url() . '/images/image1.jpg';
      $goodpath4 = $this->root->url() . '/images/';
      $directory4 = $this->fs->getDirectory($dirpath4);

      $this->assertTrue($directory4->exists());
      $this->assertEquals($goodpath4, $directory4->getPath());

  }

  /* @test Directory Not Writable */
  public function testDirectoryWriteFail()
  {
    $dirpath = $this->root->url() . '/write';
    $dirObj = vfsStream::newDirectory('write', 0000);
    $this->root->addChild($dirObj);

    $directory = $this->fs->getDirectory($dirpath);

    // no directory, no write
    $this->assertDirectoryExists($dirpath);
    $this->assertTrue($directory->exists());
    $this->assertFalse($directory->is_writable());

    $directory->check(true);

    $this->assertTrue($directory->is_writable());
    $this->assertDirectoryIsWritable($dirpath);
  }

  public function testDirectoryRelativePath()
  {
    $uploadDir = wp_upload_dir();
    $basedir = $uploadDir['basedir'];

    // test with known basedir.
    $good_result = 'wp-content/uploads/2019/08/';
    $dirpath = $basedir . '/2019/08/';

    $directory = $this->fs->getDirectory($dirpath);

    $this->assertEquals($good_result, $directory->getRelativePath(), $dirpath);
    $this->assertEquals($good_result, ShortPixelMetaFacade::returnSubDir($dirpath), ShortPixelMetaFacade::returnSubDir($dirpath));

    // Test with non-installation basedir.
    $dirpath2 = '/var/www/nothere/wp-content/uploads/2019/08/';
    $directory2 =  $this->fs->getDirectory($dirpath2);

    $this->assertEquals($good_result, $directory2->getRelativePath());
    $this->assertEquals($good_result, ShortPixelMetaFacade::returnSubDir($dirpath2), ShortPixelMetaFacade::returnSubDir($dirpath2));

    // Test with URL, or whatever.
    $dirpath3 = 's3:/localbucket/wp-content/uploads/2019/08/64NrYkvZOrU-200x300.jpg';
    $directory3 =  $this->fs->getDirectory($dirpath3);

    $this->assertEquals($good_result, $directory3->getRelativePath(), $directory3->getPath());
    $this->assertEquals($good_result, ShortPixelMetaFacade::returnSubDir($dirpath3), ShortPixelMetaFacade::returnSubDir($dirpath3));

    // Test with Upload Dir not based on month/year.
    $dirpath4 = $basedir . '/64NrYkvZOrU-200x300.jpg';
    $good_result4 = 'wp-content/uploads/';
    $directory4 =  $this->fs->getDirectory($dirpath4);

    $this->assertEquals($good_result4, $directory4->getRelativePath(), $directory4->getPath());
    $this->assertEquals($good_result4, ShortPixelMetaFacade::returnSubDir($dirpath4), ShortPixelMetaFacade::returnSubDir($dirpath4));

    // unknown host, based on uploads
    $dirpath5 = '/var/unknown/uploads/2019/08/bla.jpg';
    $good_result5 = 'uploads/2019/08/';
    $directory5 = $this->fs->getDirectory($dirpath5);

    $this->assertEquals($good_result5, $directory5->getRelativePath(), $directory5->getRelativePath());
    $this->assertEquals($good_result5, ShortPixelMetaFacade::returnSubDir($dirpath5), ShortPixelMetaFacade::returnSubDir($dirpath5));

    //upload_dir
  }

  public function testFileBasic()
  {
      $filepath = $this->root->url() . '/images/image1.jpg';
      $filedir = $this->root->url() . '/images/';

      $this->assertFileExists($filepath);

      $file = $this->fs->getFile($filepath);

      $this->assertTrue($file->exists(), $file->getFullPath());
      $this->assertEquals($file->getFullPath(), $filepath);
      $this->assertEquals($file->getExtension(), 'jpg');
      $this->assertEquals($file->getFileName(), 'image1.jpg');
      $this->assertEquals($file->getFileBase(), 'image1');
      $this->assertEquals( (string) $file->getFileDir(), $filedir);

      $filepath2 = $this->root->url() . '/images/image1.ext.jpg';
      $file2 = $this->fs->getFile($filepath2);

      $this->assertTrue($file2->exists());
      $this->assertEquals($file2->getFullPath(), $filepath2);
      $this->assertEquals($file2->getExtension(), 'jpg');
      $this->assertEquals($file2->getFileName(), 'image1.ext.jpg');
      $this->assertEquals($file2->getFileBase(), 'image1.ext');
      $this->assertEquals( (string) $file2->getFileDir(), $filedir);


      // Empty
      $file3 = $this->fs->getFile('');
      $this->assertFileNotExists($file3);
      $this->assertFalse($file3->exists(), $file3->getFullPath());
      $this->assertEquals('', $file3->getFullPath());
      $this->assertEquals('', $file3->getFileName());
      $this->assertEquals('', $file3->getFileBase());
      $this->assertNull($file3->getFileDir());

      // Exists and writable, according to PHP spec.
      $this->assertFalse($file3->is_writable());

      // should not have backup.
      $this->assertFalse($file3->hasBackup());

      // test fail cases for file operations.
      $this->assertFalse($file3->copy($file2));
      $this->assertFileExists($file2->getFullPath());
      $this->assertFalse($file3->move($file2));
      $this->assertFileExists($file2->getFullPath());

      // Empty but with space (trim test)
      $file4 = $this->fs->getFile(' ');
      $this->assertFileNotExists($file4);
      $this->assertFalse($file4->hasBackup());
      $this->assertNull($file4->getFileDir());
  }

  public function testFileCopy()
  {
    $filepath = $this->root->url() . '/images/image1.jpg';
    $targetpath = $this->root->url() . '/images/copy-image1.jpg';
    $filedir = $this->root->url() . '/images/';

    $file = $this->fs->getFile($filepath);
    $targetfile = $this->fs->getFile($targetpath);

    $this->assertTrue($file->exists());
    $this->assertFalse($targetfile->exists());

    // test targetFile setting construct on not exists
    $this->assertEquals($targetfile->getFileName(), 'copy-image1.jpg');
    $this->assertEquals( (string) $targetfile->getFileDir(), $filedir);

    $file->copy($targetfile);

    $this->assertFileExists($targetpath);
    $this->assertTrue($targetfile->exists());

    // check quality of object after copy
    $this->assertEquals(file_get_contents($filepath), file_get_contents($targetpath));

    $this->assertEquals($targetfile->getFullPath(), $targetpath);
    $this->assertEquals($targetfile->getExtension(), 'jpg');
    $this->assertEquals($targetfile->getFileName(), 'copy-image1.jpg');

  }

  public function testFileMove()
  {
    $filepath = $this->root->url() . '/images/image3.png';
    $targetpath = $this->root->url() . '/images/copy-image3.png';
    $filedir = $this->root->url() . '/images/';

    $file = $this->fs->getFile($filepath);
    $targetfile = $this->fs->getFile($targetpath);

    $this->assertFalse($targetfile->exists());
    $this->assertTrue($file->exists());

    $file->move($targetfile);

    $this->assertTrue($targetfile->exists());
    $this->assertFalse($file->exists());

  }

  public function testFileDelete()
  {
    $filepath = $this->root->url() . '/images/image2.jpg';

    $file = $this->fs->getFile($filepath);

    $this->assertFileExists($filepath);
    $this->assertTrue($file->exists());

    $result = $file->delete();

    $this->assertFalse($file->exists());
    $this->assertTrue($result);

  }

  // What happens when a relative path is given instead of a full path.
  public function testFileWithRelativePath()
  {
      $uploadDir = wp_upload_dir();
      $basedir = $uploadDir['basedir'];

//      echo "<PRE>"; var_dump($uploadDir); echo "</PRE>";
      $fullfilepath = ABSPATH .  'wp-content/uploads/2019/07/rel_image_virtual.jpg';

      // with starting slash
      $relpath =   '/wp-content/uploads/2019/07/rel_image_virtual.jpg';

      $file = $this->fs->getFile($relpath);
      $this->assertEquals($file->getFullPath(), $fullfilepath);

      // without starting slash
      $relpath2 = 'wp-content/uploads/2019/07/rel_image_virtual.jpg';

      $file = $this->fs->getFile($relpath2);
      $this->assertEquals($file->getFullPath(), $fullfilepath);

      $fulltemppath = '/tmp/3d9c0ec965c7d3f5956bf0ff64a1e657-lossy-nG9hMf.tmp';

      $file = $this->fs->getFile($fulltemppath);

      $this->assertEquals($fulltemppath, $file->getFullPath() );

      $s3path = 's3:/localbucket/wp-content/uploads/2019/08/13063326/AEobOR_BgXA.webp';
      $s3good = '/tmp/wordpress/wp-content/uploads/2019/08/13063326/AEobOR_BgXA.webp';
      $file = $this->fs->getFile($s3path);

      // Flaw
      //$this->assertEquals($s3good, $file->getFullPath());


      // Test with Upload Path something else than
      /*update_option('upload_path', dirname(ABSPATH) . '/uploads');
      $fullfilepath2 = ABSPATH .  '/uploads/2019/07/rel_image_virtual.jpg';
      $file3 = $this->fs->getFile($fullfilepath2);
      */
  }

  // for URL Test - setup env.
  private function urlSetup($url)
  {
    //$home_url = 'https://test.com';
    update_option('homeurl', $url);
    update_option('siteurl', $url);
    // See - https://developer.wordpress.org/reference/functions/_wp_upload_dir/ . Otherwise constant is used.
    update_option('upload_url_path', $url . '/wp-content/uploads');
    $upl = wp_upload_dir(null, false, true);
    //var_dump($upl);
  }
  // What happens when a URL is given instead of a file .
  public function testFileWithUrl()
  {

    $this->urlSetup('https://test.com');

    $fullfilepath = ABSPATH . 'wp-content/uploads/2019/07/wpimg1.jpg';
    $urlpath = 'https://test.com/wp-content/uploads/2019/07/wpimg1.jpg';
    $file = $this->fs->getFile($urlpath);

    $this->assertEquals($fullfilepath, $file->getFullPath());
    $this->assertEquals($urlpath, $this->fs->pathToUrl($file));

    $this->urlSetup('http://test.com');

    $urlpath2 = 'http://test.com/wp-content/uploads/2019/07/wpimg1.jpg';
    $file2 = $this->fs->getFile($urlpath2);
    $this->assertEquals($fullfilepath, $file2->getFullPath());
    $this->assertEquals($urlpath2, $this->fs->pathToUrl($file2));


    $this->urlSetup('http://test.com:8080');

    $urlpath3 = 'http://test.com:8080/wp-content/uploads/2019/07/wpimg1.jpg';
    $file3 = $this->fs->getFile($urlpath3);
    $this->assertEquals($fullfilepath, $file3->getFullPath());
    $this->assertEquals($urlpath3, $this->fs->pathToUrl($file3));
  }

  public function testFileWithCyrillic()
  {
    $fullfilepath = $this->root->url() . '/images/ашдутфьу.jpg';
    $file = $this->fs->getFile($fullfilepath);

    $this->assertTrue($file->exists(), $file->getFullPath());
    $this->assertEquals($file->getFullPath(), $fullfilepath);
    $this->assertEquals($file->getExtension(), 'jpg');
    $this->assertEquals($file->getFileName(), 'ашдутфьу.jpg');
    $this->assertEquals($file->getFileBase(), 'ашдутфьу');
  }

  public function testFileWithArabic()
  {
    $fullfilepath =  $this->root->url() . '/images/اسم الملف.jpg';
    $file = $this->fs->getFile($fullfilepath);

    $this->assertTrue($file->exists(), $file->getFullPath());
    $this->assertEquals($file->getFullPath(), $fullfilepath);
    $this->assertEquals($file->getExtension(), 'jpg');
    $this->assertEquals($file->getFileName(), 'اسم الملف.jpg');
    $this->assertEquals($file->getFileBase(), 'اسم الملف');

  }

  public function testWithWindowsPath()
  {

    $this->markTestSkipped('Not yet implemented');
  }

  public function testNoBackUp()
  {
      $filepath = $this->root->url() . '/images/image3.png';

      $this->assertFileExists($filepath);

      $file = $this->fs->getFile($filepath);
      $directory = $file->getFileDir();

      $this->assertFalse($file->hasBackup());
      $this->assertFalse($file->getBackupFile());

      // Backup functions should not change directory or fullpath .
      $this->assertEquals($file->getFullPath(), $filepath);
      $this->assertEquals($file->getFileDir(), $directory);
  }

}
