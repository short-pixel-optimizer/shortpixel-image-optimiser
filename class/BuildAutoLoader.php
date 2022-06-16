<?php
namespace ShortPixel;

class BuildAutoLoader
{

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

  public static function getFiles()
  {
    $main = array(
      // 'shortpixel_api.php',
      // 'class/wp-short-pixel.php',
       'class/wp-shortpixel-settings.php',
      // 'class/view/shortpixel_view.php',
       'class/shortpixel-png2jpg.php',
       'class/front/img-to-picture-webp.php',
    );

    $models = array(
        //   'class/Model/shortpixel-entity.php',
        //   'class/Model/shortpixel-meta.php',
        //   'class/Model/shortpixel-folder.php',
    );

/*    $db = array(
      // 'class/db/shortpixel-db.php',
      //  'class/db/wp-shortpixel-db.php',
        'class/db/shortpixel-custom-meta-dao.php',
        'class/db/wp-shortpixel-media-library-adapter.php',
        'class/db/shortpixel-meta-facade.php'
    ); */


    $externals = array(
      'class/external/cloudflare.php',
      'class/external/flywheel.php',
      //'class/external/gravityforms.php',
      //'class/external/helpscout.php',
      'class/external/nextgen/nextGenController.php',
      'class/external/nextgen/nextGenViewController.php',
      //'class/external/securi.php',
      //'class/external/shortpixel_queue_db.php',
      'class/external/visualcomposer.php',
      'class/external/wp-offload-media.php',
      //'class/external/wpengine.php',
      'class/external/wp-cli/wp-cli-base.php',
			'class/external/wp-cli/wp-cli-single.php',
			'class/external/wp-cli/wp-cli-bulk.php',
      'class/external/custom-suffixes.php',
      'class/external/pantheon.php',
			'class/external/spai.php',
    );

    echo "Build Plugin.JSON ";
    return array_merge($main,$models,$externals);
  }

}

/*require_once('shortpixel_api.php');

//entities
require_once('class/model/shortpixel-entity.php');
require_once('class/model/shortpixel-meta.php');
require_once('class/model/shortpixel-folder.php');
//exceptions
//database access
require_once('class/db/shortpixel-db.php');
require_once('class/db/wp-shortpixel-db.php');
require_once('class/db/shortpixel-custom-meta-dao.php');

require_once('class/db/wp-shortpixel-media-library-adapter.php');
require_once('class/db/shortpixel-meta-facade.php');
//view
require_once('class/view/shortpixel_view.php');
*/


//require_once( ABSPATH . 'wp-admin/includes/image.php' );
//include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
