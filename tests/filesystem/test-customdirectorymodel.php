<?php
use \org\bovigo\vfs\vfsStream;
//use \ShortPixel\Model\Image\ImageModel as ImageModel;
use \ShortPixel\Model\File\FileModel as FileModel;
use \ShortPixel\Model\File\DirectoryOtherMediaModel as DirectoryOtherMediaModel;

class CustomDirectoryModelTest extends WP_UnitTestCase
{

  public function testCreateTable()
  {

    $reflector = new ReflectionClass( 'DirectoryOtherMediaModel' );

    $hasTableMethod = $reflector->getMethod( 'hasFolderTable' );
    $hasTableMethod->setAccessible( true );

    $createMethod = $reflector->getMethod('createFolderTable');
    $createMethod->setAccessible( true );

    $dir = new DirectoryOtherMediaModel(0);

    $bool = $hasTableMethod->invoke($dir);

    $this->assertFalse($bool);


  }

  public function testAddFolders()
  {

  }


  public function testLoadFolder()
  {

  }





}
