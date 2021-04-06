<?php
namespace ShortPixel\Helper;

use \ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\QuotaController as QuotaController;

class UiHelper
{

  public static function renderBurgerList($actions, $imageObj)
  {
    $output = "";
    $id = $imageObj->get('id');
    $primary = in_array('optimizethumbs', $actions) ? 'button-primary' : '';

    $output .= "<div class='sp-column-actions '>
                    <div class='sp-dropdown'>
                        <button onclick='ShortPixel.openImageMenu(event);' class='sp-dropbtn button dashicons dashicons-menu $primary' title='ShortPixel Actions'></button>";
    $output .= "<div id='sp-dd-$id' class='sp-dropdown-content'>";

    foreach($actions as $actionName => $actionData)
    {
        $link = ($actionData['type'] == 'js') ? 'javascript:' . $actionData['function'] : $actionData['function'];
        $output .= "<a href='" . $link . "' class='$actionName' >" . $actionData['text'] . "</a>";

    }

    $output .= "</div> <!--sp-dropdown-content--> </div> <!--sp-dropdown--> </div> <!--sp-column-actions--> ";

    return $output;
  }

  public static function renderSuccessText($imageObj)
  {
    $output = '';
    //$percent = $imageObj->getMeta('improvement');
    $percent = $imageObj->getImprovement();

    if($percent == 999) return ;

    if ($percent == 999 )
      $output .= __("Reduced by X%(unknown)", 'shortpixel-image-optimiser');

    if ($percent && $percent > 0)
    {
      $output .= __('Reduced by','shortpixel-image-optimiser') . ' <strong>' . $percent . '%</strong> ';
    }
    if (intval($percent) < 5)
      $output .= __('Bonus processing','shortpixel-image-optimiser');

    $type = $imageObj->getMeta('compressionType');
    $output .= ' ('. self::compressionTypeToText($type) .')';

    $thumbs = $imageObj->get('thumbnails');
    $thumbsDone = $retinasDone = 0;
    $thumbsTotal = ($thumbs) ? count($thumbs) : 0;

    $retinas = $imageObj->get('retinas');

    $webps = $imageObj->get('webps');

    $webpsTotal = (is_array($webps)) ? count(array_filter($webps)) : 0;

  /*  if ($thumbs)
    {
      foreach($thumbs as $thumbObj)
      {
        if ($thumbObj->isOptimized())
        {
          $thumbsDone++;
        }
      }
    } */
    if($retinas)
    {
      foreach($retinas as $retinaObj)
      {
         if ($retinaObj->isOptimized())
         {
           $retinasDone++;
         }
      }
    }

    $improvements = $imageObj->getImprovements();
    $thumbTotal = $thumbsDone = 0;
    if ($imageObj->get('thumbnails'))
    {
      $thumbsTotal = count($imageObj->get('thumbnails'));  //
      $thumbsDone =  (isset($improvements['thumbnails'])) ? count($improvements['thumbnails']) : 0;
    }

    if (isset($improvements['thumbnails']))
    {
       $output .= '<div class="thumbnails optimized">';
       if ($thumbsTotal > $thumbsDone)
         $output .= '<div class="totals">' . sprintf(__('+%s of %s thumbnails optimized','shortpixel-image-optimiser'),$thumbsDone,$thumbsTotal) . '</div>';
       elseif ($thumbsDone > 0)
         $output .= '<div class="totals">' . sprintf(__('+%s thumbnails optimized','shortpixel-image-optimiser'),$thumbsDone) . '</div>';

       $output .= "<div class='thumb-wrapper'>";
       foreach($improvements['thumbnails'] as $thumbName => $thumbStat)
       {
           $title =  sprintf(__('%s : %s', 'shortpixel-image-optimiser'), $thumbName, $thumbStat[0] . '%');
           $rating = ceil( round($thumbStat[0]) / 10);

           $blocks_on = str_repeat('<span class="point checked">&nbsp;</span>', $rating);
           $blocks_off = str_repeat('<span class="point">&nbsp;</span>', (10- $rating));


           $output .= "<div class='thumb " . $thumbName . "' title='" . $title . "'>"
                       . $thumbName .
                        "<span class='optimize-bar'>" . $blocks_on . $blocks_off . "</span>
                      </div>";

       }
       $output .=  "</div></div> <!-- thumb optimized -->";
    }

    if ($retinasDone > 0)
      $output .= '<br>' . sprintf(__('+%s Retina images optimized','shortpixel-image-optimiser') , $retinasDone);

    if ($webpsTotal > 0)
      $output .= '<br>' . sprintf(__('+%s Webp images ','shortpixel-image-optimiser') , $webpsTotal);

    if ($imageObj->isOptimized() && $imageObj->isProcessable())
    {
        $optimizable = $imageObj->getOptimizeURLS();
        if (count($optimizable) > 0)
        {
           $output .= '<div class="thumbs-todo"><h4>' . __('To Optimize', 'shortpixel-image-optimiser') . '</h4>';
           foreach($optimizable as $optObj)
           {
              $output .= substr($optObj, strrpos($optObj, '/')+1) . '<br>';
           }
           $output .= '</div>';
        }
    }

    return $output;

  }

  public static function compressionTypeToText($type)
  {
     if ($type == ImageModel::COMPRESSION_LOSSLESS )
       return __('Lossless', 'shortpixel-image-optimiser');

     if ($type == ImageModel::COMPRESSION_LOSSY )
         return __('Lossy', 'shortpixel-image-optimiser');

     if ($type == ImageModel::COMPRESSION_GLOSSY )
         return __('Glossy', 'shortpixel-image-optimiser');

      return $type;
  }

  public static function getListActions($mediaItem)
  {
      $list_actions = array();
      $id = $mediaItem->get('id');

      $quotaControl = QuotaController::getInstance();

      if(! $quotaControl->hasQuota())
        return array();

      if ($mediaItem->isOptimized())
      {
           $optimizable = $mediaItem->getOptimizeURLS();

           if (count($optimizable) > 0 )
           {
             $action = self::getAction('optimizethumbs', $id);
             $action['text']  = sprintf(__('Optimize %s  thumbnails','shortpixel-image-optimiser'),count($optimizable));

             $list_actions['optimizethumbs'] = $action;
          }

          if ($mediaItem->hasBackup())
          {
            if ($mediaItem->get('type') == 'custom')
                $list_actions[] = self::getAction('compare-custom', $id);
            else
              $list_actions[] = self::getAction('compare', $id);

           switch($mediaItem->getMeta('compressionType'))
           {
               case ImageModel::COMPRESSION_LOSSLESS:
                 $list_actions['reoptimize-lossy'] = self::getAction('reoptimize-lossy', $id);
                 $list_actions['reoptimize-glossy'] = self::getAction('reoptimize-glossy', $id);
               break;
               case ImageModel::COMPRESSION_LOSSY:
                 $list_actions['reoptimize-lossless'] = self::getAction('reoptimize-lossless', $id);
                 $list_actions['reoptimize-glossy'] = self::getAction('reoptimize-glossy', $id);
               break;
               case ImageModel::COMPRESSION_GLOSSY:
                 $list_actions['reoptimize-lossy'] = self::getAction('reoptimize-lossy', $id);
                 $list_actions['reoptimize-lossless'] = self::getAction('reoptimize-lossless', $id);
               break;
           }

          $list_actions['restore'] = self::getAction('restore', $id);
          }
      }


      return $list_actions;
  }

  public static function getActions($mediaItem)
  {
    $actions = array();
    $id = $mediaItem->get('id');
    $quotaControl = QuotaController::getInstance();

    if(! $quotaControl->hasQuota())
    {
       $actions['extendquota'] = self::getAction('extendquota', $id);
       $actions['checkquota'] = self::getAction('checkquota', $id);
    }
    elseif($mediaItem->isProcessable() && ! $mediaItem->isOptimized())
    {
       $actions['optimize'] = self::getAction('optimize', $id);
    }
    return $actions;
  }

  public static function getStatusText($mediaItem)
  {
    $keyControl = ApiKeyController::getInstance();
    $quotaControl = QuotaController::getInstance();

    $text = '';

    if (! $keyControl->keyIsVerified())
    {
      $text = __('Invalid API Key. <a href="options-general.php?page=wp-shortpixel-settings">Check your Settings</a>','shortpixel-image-optimiser');
    }
    elseif(! $quotaControl->hasQuota())
    {
       $text = __('Quota Exceeded','shortpixel-image-optimiser');

    }
    elseif ($mediaItem->isOptimized())
    {
       $text = UiHelper::renderSuccessText($mediaItem);
    }
    elseif (! $mediaItem->isProcessable() && ! $mediaItem->isOptimized())
    {
       $text = __('Not Processable: ','shortpixel_image_optimiser');
       $text  .= $mediaItem->getProcessableReason();

    }
    elseif (! $mediaItem->exists())
    {
       $text = __('Image does not exist.','shortpixel-image-optimiser');
    }
    elseif ($mediaItem->getMeta('status') < 0)
    {
      $text = $mediaItem->getMeta('errorMessage');
    }

    return $text;
  }

  public static function getAction($name, $id)
  {
     $action = array('function' => '', 'type' => '', 'text' => '', 'display' => '');
     $keyControl = ApiKeyController::getInstance();

    // @todo Needs Nonces on Links
    switch($name)
    {
      case 'optimize':
         $action['function'] = 'window.ShortPixelProcessor.screen.Optimize(' . $id . ')';
         $action['type']  = 'js';
         $action['text'] = __('Optimize Now', 'shortpixel-image-optimiser');
         $action['display'] = 'button';
      break;
      case 'optimizethumbs':
          $action['function'] = 'window.ShortPixelProcessor.screen.Optimize(' . $id . ');';
          $action['type'] = 'js';
          $action['text']  = '';
          $action['display'] = 'inline';
      break;

      case 'retry':
         $action['function'] = 'window.ShortPixelProcessor.screen.Optimize(' . $id . ');';
         $action['type']  = 'js';
         $action['text'] = __('Retry', 'shortpixel-image-optimiser') ;
         $action['display'] = 'button';
     break;

     case 'restore':
         $action['function'] = 'window.ShortPixelProcessor.screen.RestoreItem(' . $id . ');';
         $action['type'] = 'js';
         $action['text'] = __('Restore backup','shortpixel-image-optimiser');
         $action['display'] = 'inline';
     break;

     case 'compare':
        $action['function'] = 'ShortPixel.loadComparer(' . $id . ')';
        $action['type'] = 'js';
        $action['text'] = __('Compare', 'shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;
     case 'compare-custom':
        $action['function'] = 'ShortPixel.loadComparer(' . $id . ',"custom")';
        $action['type'] = 'js';
        $action['text'] = __('Compare', 'shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;
     case 'reoptimize-glossy':
        $action['function'] = 'window.ShortPixelProcessor.screen.ReOptimize(' . $id . ',' . ImageModel::COMPRESSION_GLOSSY . ')';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize Glossy','shortpixel-image-optimiser') ;
        $action['display'] = 'inline';
     break;
     case 'reoptimize-lossy':
        $action['function'] = 'window.ShortPixelProcessor.screen.ReOptimize(' . $id . ',' . ImageModel::COMPRESSION_LOSSY . ')';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize Lossy','shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;

     case 'reoptimize-lossless':
        $action['function'] = 'window.ShortPixelProcessor.screen.ReOptimize(' . $id . ',' . ImageModel::COMPRESSION_LOSSLESS . ')';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize Lossless','shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;

     case 'extendquota':
        $action['function'] = 'https://shortpixel.com/login'. $keyControl->getKeyForDisplay();
        $action['type'] = 'button';
        $action['text'] = __('Extend Quota','shortpixel-image-optimiser');
        $action['display'] = 'button';
     break;
     case 'checkquota':
        $action['function'] = 'ShortPixel.checkQuota()';
        $action['type'] = 'js';
        $action['display'] = 'button';
        $action['text'] = __('Check&nbsp;&nbsp;Quota','shortpixel-image-optimiser');

     break;


   }

   return $action;
  }

  public static function formatTS($ts)
  {
      //$format = get_option('date_format') .' @ ' . date_i18n(get_option('time_format');
      $date = wp_date(get_option('date_format'), $ts);
      $date .= ' @ ' . wp_date(get_option('time_format'), $ts);
      return $date;
  }

  static public function formatBytes($bytes, $precision = 2) {
      $units = array('B', 'KB', 'MB', 'GB', 'TB');

      $bytes = max($bytes, 0);
      $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
      $pow = min($pow, count($units) - 1);

      $bytes /= pow(1024, $pow);

      return round($bytes, $precision) . ' ' . $units[$pow];
  }




} // class
