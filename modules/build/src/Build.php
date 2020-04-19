<?php
namespace ShortPixel\Build;

use Composer\Script\Event;
use Composer\Installer\PackageEvent;

class Build
{
  private $vendor = 'vendor/shortpixel';
  private $build = 'build/shortpixel';
  static $instance;

  private $vendorDir;
  private $buildDir;
  private $modules;
  private $excludes = array('.', '..', 'build');
  private $targetNamespace;

  private static $composer;

  public static function BuildIt(Event $event)
  {
      $extra = $event->getComposer()->getPackage()->getExtra();
      $targetNamespace = $extra['targetNamespace']; //required
      self::$composer = $event->getComposer();

      echo "PHP BUILD MODULE SCRIPT FOR SHORTPIXEL MODULES" . PHP_EOL;
      $build = new Build($targetNamespace);

  }

  public function __construct($targetNamespace)
  {
      $this->vendorDir = getCwd() . '/' . $this->vendor . '/';
      $this->buildDir = getCwd() . '/' . $this->build . '/';
      $this->targetNamespace = $targetNamespace;
      $this->getModules();
      $this->createBuildDir();

      $this->moveModules();

      $this->reSpace();

      // After reworking the namespaces, update the autoload.
      $this->createPackageLoader();
  }


  private function getModules()
  {
    $modules = array();
    echo '* Checking modules - ' . $this->vendorDir . PHP_EOL;
    foreach (new \DirectoryIterator($this->vendorDir) as $fileInfo) {
      if($fileInfo->isDot()) continue;
        //    echo $fileInfo->getFilename() . "<br>\n";
      $filename = $fileInfo->getFilename();

      if ($fileInfo->isDir() && !in_array($filename, $this->excludes))
      {
          $modules[$filename] = $fileInfo->getRealPath();
      }

    }
    $this->modules = $modules;
    echo "Found Modules - "; print_r($modules);
    echo PHP_EOL;
  }

  private function createBuildDir()
  {
     //
     //  rmdir($this->buildDir);
     if (! file_exists($this->buildDir))
      mkdir($this->buildDir, 0755, true);

  }

  private function moveModules()
  {
    foreach($this->modules as $moduleName => $modulePath)
    {
      echo " -- Start Move - $moduleName " . PHP_EOL;
      $target = $this->buildDir . $moduleName . '/';

      $fs = new \Composer\Util\FileSystem();
      $fs->copy($modulePath, $target);
    }
  }

  private function reSpace()
  {
    echo " Respacing to " . $this->targetNamespace . '-' . $this->buildDir .  PHP_EOL;

    $Directory = new \RecursiveDirectoryIterator($this->buildDir);
    $Iterator = new \RecursiveIteratorIterator($Directory);
    $filter = new \RegexIterator($Iterator, '/^.+\.php$/i');

    $filelist = array();
    $filelist = iterator_to_array($filter, false);

    // @todo Look the respace.
    foreach($filelist as $file)
    {
      $this->reSpaceFile($file);
    }
  }

  private function reSpaceFile($file)
  {
    $contents = file_get_contents($file);
    $contents = str_replace('ShortPixel\\', $this->targetNamespace . '\\', $contents);
    file_put_contents($file, $contents);

  }

  private function createPackageLoader()
  {
    // copy over PackageManager.php to directory

    echo " - Copy PackageLoader - " . PHP_EOL;
    $source = $this->vendorDir . 'build/src/PackageLoader.php';
    $target = $this->buildDir . 'PackageLoader.php';
    $fs = new \Composer\Util\FileSystem();
    $fs->copy($source, $target);

    $this->reSpaceFile($target);

    // create Composer.json file to load PSR-4
    $psr = array();
    foreach($this->modules as $moduleName => $modulePath)
    {
       $json = json_decode(file_get_contents($modulePath ."/composer.json"), true);
       //var_dump($json);
         if(isset($json["autoload"]["psr-4"]))
         {
           foreach($json["autoload"]["psr-4"] as $package => $source)
           {
            $package = str_replace('ShortPixel\\', $this->targetNamespace . '\\', $package);

            /* The required \\ after the targetNamespace is not put in the composer.json because
            * WordPress JSON compat function is not happy with it - leading to crashes */
            $package = rtrim($package, '\\');
            $source = $moduleName . '/' . $source;
            $psr[$package] = $source;
           }
         }
    }

    $this->writeComposerFile($psr);
    $this->writeAutoLoadFile();

    // check if all autoloads are funky there.
  }

  private function writeComposerFile($psr)
  {
    $file = fopen($this->buildDir . 'composer.json', 'w+');

    $composer = array(
        'name' => $this->targetNamespace . '/shortpixelmodules',
        'description' => 'ShortPixel submodules',
        'type' => 'function',
        'autoload' => array('psr-4' => $psr),
    );

    fwrite($file, json_encode($composer));
    fclose($file);
  }

  private function writeAutoLoadFile()
  {
    $file = fopen($this->buildDir . 'autoload.php', 'w+');
    fwrite($file, '<?php
         require_once  (dirname(__FILE__)  . "/PackageLoader.php");
         $loader = new ' . $this->targetNamespace . '\Build\PackageLoader();
         $loader->load(__DIR__);
         ');
    fclose($file);
  }


} // class
