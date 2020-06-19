<?php
namespace ShortPixel\Helper;

use \ShortPixel\Model\Image\ImageModel as ImageModel;


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
    $percent = $imageObj->get('improvement');
    if($percent == 999) return ;

    if ($percent == 999 )
      $output .= __("Reduced by X%(unknown)", 'shortpixel-image-optimizer');

    if ($percent && $percent > 0)
    {
      $output .= __('Reduced by','shortpixel-image-optimiser') . ' <strong>' . $percent . '%</strong> ';
    }
    if (intval($percent) < 5)
      $output .= __('Bonus processing','shortpixel-image-optimiser');

    $type = $imageObj->getMeta('type');
    $output .= ' ('. self::compressionTypeToText($type) .')';

    $thumbs = $imageObj->get('thumbnails');
    $thumbsDone = $retinasDone = 0;
    $thumbsTotal = ($thumbs) ? count($thumbs) : 0;

    $retinas = $imageObj->get('retinas');

//echo "<PRE>"; var_dump($thumbs); echo "</PRE>";
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


}
