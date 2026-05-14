<?php
namespace ShortPixel;

/**
 * Utility class for generating the plugin's Composer-style autoloader JSON manifest.
 *
 * Used during development/build processes to regenerate the plugin.json file that
 * maps namespaces and individual files for the PSR-4 autoloader.
 *
 * @package ShortPixel
 */
class BuildAutoLoader
{

  /**
   * Builds and writes the plugin.json autoloader manifest to class/plugin.json.
   *
   * Constructs a Composer-style package descriptor with PSR-4 namespace mapping
   * and the explicit file list returned by getFiles(), then serialises it as JSON.
   *
   * @return void
   */
  public static function buildJSON()
  {
    echo 'Building Plugin.JSON';
    $plugin = array(
        'name' => 'ShortPixel/Plugin',
        'description' => 'ShortPixel AutoLoader',
        'type' => 'function',
        'autoload' => array('psr-4' => array('ShortPixel' => 'class'),
            'files' => self::getFiles(),
        ),
      );

    $f = fopen('class/plugin.json', 'w');
    $result = fwrite($f, json_encode($plugin));

    if ($result === false)
      echo "!!! Error !!! Could not write Plugin.json";

    fclose($f);
  }

  /**
   * Returns the list of plugin PHP files that must be explicitly included by the autoloader.
   *
   * Combines main plugin files, model files, and external integration files into a
   * single flat array. Entries for disabled integrations are commented out inline.
   *
   * @return string[] Array of relative file paths to include.
   */
  public static function getFiles()
  {
    $main = array(
    );

    $models = array(
    );

    $externals = array(
      'class/external/cloudflare.php',
      'class/external/nextgen/nextGenController.php',
      'class/external/nextgen/nextGenViewController.php',
      'class/external/visualcomposer.php',
			'class/external/offload/Offloader.php',
      'class/external/offload/wp-offload-media.php',
			'class/external/offload/virtual-filesystem.php',
      'class/external/offload/InfiniteUploads.php',
      'class/external/wp-cli/wp-cli-base.php',
			'class/external/wp-cli/wp-cli-single.php',
			'class/external/wp-cli/wp-cli-bulk.php',
      'class/external/image-galleries.php',
      'class/external/pantheon.php',
			'class/external/spai.php',
			'class/external/cache.php',
			'class/external/uncode.php',
			'class/external/query-monitor.php',
			'class/external/Woocommerce.php',
      'class/external/themes/total-theme.php',
      'class/external/MediaFileRenamer.php',
      'class/external/formidable.php',
      'class/external/wpml.php', 
      
    );

    echo "Build Plugin.JSON ";
    return array_merge($main,$models,$externals);
  }

}
