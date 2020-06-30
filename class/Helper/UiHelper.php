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
//<?php if($thumbsRemain) { echo('button-primary')
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
    $percent = $imageObj->getMeta('improvement');

    if($percent == 999) return ;

    if ($percent == 999 )
      $output .= __("Reduced by X%(unknown)", 'shortpixel-image-optimizer');

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

    $webpsTotal = (is_array($webps)) ? count($webps) : 0;

    if ($thumbs)
    {
      foreach($thumbs as $thumbObj)
      {
        if ($thumbObj->isOptimized())
        {
          $thumbsDone++;
        }
      }
    }
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

    if ($thumbsTotal > $thumbsDone)
      $output .= '<br>' . sprintf(__('+%s of %s thumbnails optimized','shortpixel-image-optimiser'),$thumbsDone,$thumbsTotal);
    elseif ($thumbsDone > 0)
      $output .= '<br>' . sprintf(__('+%s thumbnails optimized','shortpixel-image-optimiser'),$thumbsDone);

    if ($retinasDone > 0)
      $output .= '<br>' . sprintf(__('+%s Retina images optimized','shortpixel-image-optimiser') , $retinasDone);

    if ($webpsTotal > 0)
      $output .= '<br>' . sprintf(__('+%s Webp images ','shortpixel-image-optimiser') , $webpsTotal);


    return $output;

  }
/*
  public static function renderMessage($message)
  {
    $output = "<div class='sp-column-info'>" . $message . "</div>";

    return $output;
  }
 */
  public static function compressionTypeToText($type)
  {
     if ($type == ImageModel::COMPRESSION_LOSSLESS )
       return __('Lossless', 'shortpixel-image-optimizer');

     if ($type == ImageModel::COMPRESSION_LOSSY )
         return __('Lossy', 'shortpixel-image-optimizer');

     if ($type == ImageModel::COMPRESSION_GLOSSY )
         return __('Glossy', 'shortpixel-image-optimizer');

      return $type;
  }

  public static function getListActions($mediaItem)
  {
      $list_actions = array();
      $id = $mediaItem->get('id');

      if ($mediaItem->isOptimized())
      {
          $optimizable = $mediaItem->getOptimizePaths();

           if (count($optimizable) > 0 && $mediaItem->isOptimized() )
           {
             $action = self::getAction('optimizethumbs', $id);
             $action['text']  = sprintf(__('Optimize %s  thumbnails','shortpixel-image-optimiser'),count($optimizable));
             $list_actions['optimizethumbs'] = $action;

          }

          if ($mediaItem->hasBackup())
          {
            $list_actions[] = self::getAction('compare', $id);

           switch($mediaItem->getMeta('type'))
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
    elseif (! $mediaItem->isProcessable())
    {
       $text = __('n/a','shortpixel_image_optimiser');
    }
    elseif (! $mediaItem->exists())
    {
       $text = __('Image does not exist.','shortpixel-image-optimiser');
    }
    elseif ($mediaItem->getMeta('status') < 0)
    {
      $text = $mediaItem->getMeta('errorMessage');
    }
    elseif ($mediaItem->isOptimized())
    {
       $text = UiHelper::renderSuccessText($mediaItem);
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
         $action['function'] = 'manualOptimization(' . $id . ')';
         $action['type']  = 'js';
         $action['text'] = __('Optimize Now', 'shortpixel-image-optimiser');
         $action['display'] = 'button';
      break;
      case 'optimizethumbs':
          $action['function'] = 'optimizeThumbs(' . $id . ')';
          $action['type'] = 'js';
          $action['text']  = '';
          $action['display'] = 'inline';
      break;

      case 'retry':
         $action['function'] = 'manualOptimization(' . $id .', false)';
         $action['type']  = 'js';
         $action['text'] = __('Retry', 'shortpixel-image-optimiser') ;
         $action['display'] = 'button';
     break;

     case 'restore':
         $action['function'] = 'admin.php?action=shortpixel_restore_backup&attachment_ID=' . $id;
         $action['type'] = 'link';
         $action['text'] = __('Restore backup','shortpixel-image-optimiser');
         $action['display'] = 'inline';
     break;

     case 'compare':
        $action['function'] = 'ShortPixel.loadComparer(' . $id . ')';
        $action['type'] = 'js';
        $action['text'] = __('Compare', 'shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;
     case 'reoptimize-glossy':
        $action['function'] = 'reoptimize(' . $id . ', glossy)';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize Glossy','shortpixel-image-optimiser') ;
        $action['display'] = 'inline';
     break;
     case 'reoptimize-lossy':
        $action['function'] = 'reoptimize(' . $id . ', lossy)';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize Lossy','shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;

     case 'reoptimize-lossless':
        $action['function'] = 'reoptimize(' . $id . ', lossless)';
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


}
