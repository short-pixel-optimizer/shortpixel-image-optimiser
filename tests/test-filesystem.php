<?php
use org\bovigo\vfs\vfsStream;

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
    //  $this->removeDir($dir);
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
    $ar = array(
        'images' => array('image1.jpg' => '1234', 'image2.jpg' => '1234', 'image3.png' => '1345'),
    );
    return $ar;
  }

  public function testBasicDirectory()
  {
      $dirpath = $this->root->url() . '/basic';
      $directory = $this->fs->getDirectory($dirpath);

      // First test. Non existing directory should not exist.
      $this->assertFalse($directory->exists());

      // Check should Create a directory.
      $directory->check();

      $this->assertTrue($directory->exists());
      $this->assertDirectoryExists($dirpath);
      $this->assertDirectoryIsReadable($dirpath);
      $this->assertDirectoryIsWritable($dirpath);

      // Test if cast and return are the same
      $this->assertEquals((String) $directory, $directory->getPath());

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

  public function testFileBasic()
  {
      $filepath = $this->root->url() . '/images/image1.jpg';
      $filedir = $this->root->url() . '/images/';

      $this->assertFileExists($filepath);

      $file = $this->fs->getFile($filepath);

      $this->assertTrue($file->exists());
      $this->assertEquals($file->getFullPath(), $filepath);
      $this->assertEquals($file->getExtension(), 'jpg');
      $this->assertEquals($file->getFileName(), 'image1.jpg');

      $this->assertEquals( (string) $file->getFileDir(), $filedir);


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

  /** Not testable on VFS due to home-path checks */
  public function testsetAndGetBackup()
  {

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

      \ShortPixelApi::backupImage($file, array());

      $this->assertFileExists( $directory->getPath . '/' . $file->getFileName()) ;

    //$URLsAndPATHs = $itemHandler->getURLsAndPATHs(false);

  }



}
