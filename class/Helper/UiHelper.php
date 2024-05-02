<?php
namespace ShortPixel\Helper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


use ShortPixel\Model\AccessModel as AccessModel;

class UiHelper
{

	private static $outputMode = 'admin';

	private static $knowledge_url = 'https://shortpixel.com/knowledge-base/search?query='; // the URL of all knowledge.

	public static function setOutputHandler($name)
	{
		 	self::$outputMode = $name;
	}

  public static function renderBurgerList($actions, $imageObj)
  {
    $output = "";
    $id = $imageObj->get('id');
    $primary = isset($actions['optimizethumbs']) ? 'button-primary' : '';

    $output .= "<div class='sp-column-actions '>
                    <div class='sp-dropdown'>
                        <button onclick='ShortPixel.openImageMenu(event);' class='sp-dropbtn button dashicons dashicons-menu $primary' title='ShortPixel Actions'></button>";
    $output .= "<div id='sp-dd-$id' class='sp-dropdown-content'>";

    foreach($actions as $actionName => $actionData)
    {
        $link = ($actionData['type'] == 'js') ? 'javascript:' . $actionData['function'] : $actionData['function'];
        $output .= "<a href='" . $link . "' class='" . esc_attr($actionName) . "' >" . esc_html($actionData['text']) . "</a>";

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
      $output .= __('Reduced by','shortpixel-image-optimiser') . ' <strong>' . self::formatNumber($percent,2) . '%</strong> ';
    }
    if (intval($percent) < 5)
      $output .= __('Bonus processing','shortpixel-image-optimiser');

    $type = $imageObj->getMeta('compressionType');
    $output .= ' ('. self::compressionTypeToText($type) .')';

    $thumbs = $imageObj->get('thumbnails');
    $thumbsDone = $retinasDone = 0;
    $thumbsTotal = ($thumbs) ? count($thumbs) : 0;

    $retinas = $imageObj->get('retinas');

    $webpsTotal = $imageObj->count('webps');
    $avifsTotal = $imageObj->count('avifs');

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
      //$thumbsDone =  (isset($improvements['thumbnails'])) ? count($improvements['thumbnails']) : 0;
      $thumbsDone = $imageObj->count('optimized', array('thumbs_only' => true));
      $excludedThumbs = $imageObj->count('user_excluded', array('thumbs_only' => true));
    }

    if (isset($improvements['thumbnails']))
    {
       $excluded = ($excludedThumbs > 0) ? sprintf(__('(%s excluded)', 'shortpixel-image-optimiser'), $excludedThumbs) : '';

       $output .= '<div class="thumbnails optimized">';
       if ($thumbsTotal > $thumbsDone)
         $output .= '<div class="totals">' . sprintf(__('+%s of %s thumbnails optimized','shortpixel-image-optimiser'), self::formatNumber($thumbsDone,0), self::formatNumber($thumbsTotal,0)) . ' ' .   $excluded . '</div>';

       elseif ($thumbsDone > 0)
         $output .= '<div class="totals">' . sprintf(__('+%s thumbnails optimized','shortpixel-image-optimiser'), self::formatNumber($thumbsDone, 0)) . ' ' . $excluded . '</div>';

			 $improvs = array();

				 uasort($improvements['thumbnails'], function ($a, $b) {
					 	//return $b[0] <=> $a[0]; // @todo Efficient code to use once PHP 5 support is done.
						if ($a == $b) {
							return 0;
						}
						return ($b < $a) ? -1 : 1;
				 });

			 $cutoff = false;
			 $thumbCount = count($improvements['thumbnails']);
			 if ($thumbCount > 20)
			 {
				  $improvements['thumbnails'] =  array_slice($improvements['thumbnails'], 0, 15, true);
					$cutoff = true;
			 }


			 // Quality Check
			 foreach($improvements['thumbnails'] as $thumbName => $thumbStat)
			 {
				  $stat = $thumbStat[0];
				 	if (is_numeric($stat) && $stat >= 0)
					{
						 $improvs[$thumbName] = $stat; //self::formatNumber($stat,2);
					}
			 }

			 if (count($improvs) > 0)
			 {
		       $output .= "<div class='thumb-wrapper'>";
					 $lowrating = 0;
		       foreach($improvs as $thumbName => $stat)
		       {
						   $statText = self::formatNumber($stat, 2);
		           $title =  sprintf(__('%s : %s', 'shortpixel-image-optimiser'), $thumbName, $statText . '%');
		           $rating = ceil( round($stat) / 10);
							 if (0 == $rating)
							 {
								 	$lowrating++;
									continue;
							 }

		           $blocks_on = str_repeat('<span class="point checked">&nbsp;</span>', $rating);
		           $blocks_off = str_repeat('<span class="point">&nbsp;</span>', (10- $rating));

		           $output .= "<div class='thumb " . $thumbName . "' title='" . $title . "'>"
		                       . "<span class='thumb-name'>" .  $thumbName . '</span>' .
		                        "<span class='optimize-bar'>" . $blocks_on . $blocks_off . "</span>
		                      </div>";
		       }

					 if ($lowrating > 0)
					 {
						 $blocks_off = str_repeat('<span class="point">&nbsp;</span>', 10);

						 $output .= "<div class='thumb'>"
												 . "<span class='thumb-name'>" . sprintf(__('+ %d thumbnails ', 'shortpixel-image-optimiser'), $lowrating) . '</span>' .
													"<span class='optimize-bar'>" . $blocks_off . "</span>
												</div>";
					 }

					 if (true === $cutoff)
					 {
						 $output .= '<div class="thumb"><span class="cutoff">' . sprintf(__('+ %d more', 'shortpixel-image-optimiser'), ($thumbCount - 15)) . '</span></div>';
					 }


		       $output .=  "</div> <!-- /thumb-wrapper -->";
				}
				$output .= "</div> <!-- /thumb optimized -->";
    }

    if ($retinasDone > 0)
    {
      $output .= '<div class="filetype retina">' . sprintf(__('+%s Retina images optimized','shortpixel-image-optimiser') , $retinasDone) . '</div>';
    }
    if ($webpsTotal > 0)
    {
      $output .=  '<div class="filetype webp">' . sprintf(__('+%s Webp images ','shortpixel-image-optimiser') , $webpsTotal) . '</div>';
    }
    if ($avifsTotal > 0)
    {
        $output .=  '<div class="filetype avif">' . sprintf(__('+%s Avif images ','shortpixel-image-optimiser') , $avifsTotal) . '</div>';
    }

    if ($imageObj->isSomethingOptimized() && $imageObj->isProcessable())
    {
        list($urls, $optimizable) = $imageObj->getCountOptimizeData('thumbnails');
				list($webpUrls, $webpCount)   =  $imageObj->getCountOptimizeData('webp');
				list($avifUrls, $avifCount)   =  $imageObj->getCountOptimizeData('avif');


				$maxList = 10;

			 if (count($urls) > $maxList)
			 {
				  $urls = array_slice($urls, 0, $maxList, true);
					$urls[] = '...';
			 }
			 if (count($webpUrls) > $maxList)
			 {
				  $webpUrls = array_slice($webpUrls, 0, $maxList, true);
					$webpUrls[] = '...';
			 }
			 if (count($avifUrls) > $maxList)
			 {
				  $avifUrls = array_slice($avifUrls, 0, $maxList, true);
					$avifUrls[] = '...';
			 }

        if ($optimizable > 0)
        {
           $output .= '<div class="thumbs-todo"><h4>' . sprintf(__('%d images to optimize', 'shortpixel-image-optimiser'), $optimizable) . '</h4>';
             $output .= "<span>";
               foreach($urls as $optObj)
               {
								 if ($optObj === '...')
									$output .= $optObj;
								 else
                  $output .= substr($optObj, strrpos($optObj, '/')+1) . '<br>';
               }
             $output .= "</span>";
           $output .= '</div>';
        }

        if ($webpCount > 0 )
        {

           $output .= '<div class="thumbs-todo"><h4>' . sprintf(__('%d Webp files to create', 'shortpixel-image-optimiser'), $webpCount) . '</h4>';
             $output .= "<span>";
               foreach($webpUrls as $optObj)
               {
								  if ($optObj === '...')
									 $output .= $optObj;
									else
                  	$output .= self::convertImageTypeName(substr($optObj, strrpos($optObj, '/')+1), 'webp') . '<br>';
               }
             $output .= "</span>";
           $output .= '</div>';
        }
        if ($avifCount > 0)
        {
            $output .= '<div class="thumbs-todo"><h4>' . sprintf(__('%d Avif files to create', 'shortpixel-image-optimiser'), $avifCount) . '</h4>';
              $output .= "<span>";
                foreach($avifUrls as $optObj)
                {
                   $output .= self::convertImageTypeName(substr($optObj, strrpos($optObj, '/')+1), 'avif') . '<br>';
                }
              $output .= "</span>";
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

		  $keyControl = ApiKeyController::getInstance();
			if (! $keyControl->keyIsVerified())
			{
				return array(); // nothing
			}

      $quotaControl = QuotaController::getInstance();

			$access = AccessModel::getInstance();
			if (! $access->imageIsEditable($mediaItem))
			{
				 return array();
			}

			if ($id === 0)
				return array();

      if ($mediaItem->isSomethingOptimized() )
      {
						list($u, $optimizable) = $mediaItem->getCountOptimizeData('thumbnails');
						list($u, $optimizableWebp)   =  $mediaItem->getCountOptimizeData('webp');
						list($u, $optimizableAvif)   =  $mediaItem->getCountOptimizeData('avif');

           if ($mediaItem->isProcessable() && ! $mediaItem->isOptimizePrevented())
           {
             $action = self::getAction('optimizethumbs', $id);
             if ($optimizable > 0)
             {
							 $total = $optimizable + $optimizableWebp + $optimizableAvif;
							 if ($optimizableWebp > 0 || $optimizableAvif > 0)
							 	   $itemText = __('items', 'shortpixel-image-optimiser');
								else {
									 $itemText = __('thumbnails', 'shortpixel-image-optimiser');
								}
               $action['text']  = sprintf(__('Optimize %s  %s','shortpixel-image-optimiser'),$total, $itemText);
             }
             else
             {
                 if ($optimizableWebp > 0 && $optimizableAvif > 0)
                   $text  = sprintf(__('Optimize %s webps and %s avif','shortpixel-image-optimiser'),$optimizableWebp, $optimizableAvif);
                 elseif ($optimizableWebp > 0)
                   $text  = sprintf(__('Optimize %s webps','shortpixel-image-optimiser'),$optimizableWebp);
                 else
                    $text  = sprintf(__('Optimize %s avifs','shortpixel-image-optimiser'),$optimizableAvif);
                 $action['text'] = $text;
             }
             $list_actions['optimizethumbs'] = $action;
          }


          if ($mediaItem->isRestorable())
          {
            if ($mediaItem->get('type') == 'custom')
            {
                if ($mediaItem->getExtension() !== 'pdf') // no support for this
                  $list_actions['comparer'] = self::getAction('compare-custom', $id);
            }
            else
            {
                // PDF without thumbnail can't be compared.
                $showCompare = true;
                if ($mediaItem->getExtension() == 'pdf')
                {
  				            if (! $mediaItem->getThumbnail('full'))
  					               $showCompare = false;
  				            elseif(! $mediaItem->getThumbnail('full')->hasBackup())
  					             $showCompare = false;
  			         }

  				       if ($showCompare)
                   $list_actions['comparer'] = self::getAction('compare', $id);
            }
			 			if ($mediaItem->isRestorable())
						{

							 $compressionType = $mediaItem->getMeta('compressionType');
		           switch($compressionType)
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

							 if ($mediaItem->get('type') === 'media')
							 {
							 		$list_actions['reoptimize-smartcrop'] = self::getAction('reoptimize-smartcrop', $id, array('compressionType' => $compressionType));
							 		$list_actions['reoptimize-smartcropless'] = self::getAction('reoptimize-smartcropless', $id, array('compressionType' => $compressionType));
								}
		          		$list_actions['restore'] = self::getAction('restore', $id);
							} // isRestorable
						else
						{

						}
        } // hasBackup

				if (\wpSPIO()->env()->is_debug && $mediaItem->get('type') == 'media')
				{
					 $list_actions['redo_legacy'] = self::getAction('redo_legacy', $id);
				}
      } //isOptimized

      if(! $quotaControl->hasQuota())
      {
         $remove = array('reoptimize-lossy' => '', 'reoptimize-glossy' => '', 'reoptimize-lossless' => '', 'optimizethumbs' => '');
         $list_actions = array_diff_key($list_actions, $remove);

      }
      return $list_actions;
  }

  public static function getActions($mediaItem)
  {
    $actions = array();
    $id = $mediaItem->get('id');
    $quotaControl = QuotaController::getInstance();
		$optimizeController = new OptimizeController();

		$keyControl = ApiKeyController::getInstance();
		if (! $keyControl->keyIsVerified())
		{
			return array(); // nothing
		}

		$access = AccessModel::getInstance();
		if (! $access->imageIsEditable($mediaItem))
		{
			 return array();
		}

		if ($id === 0)
			return array();

    if(! $quotaControl->hasQuota())
    {
       $actions['extendquota'] = self::getAction('extendquota', $id);
       $actions['checkquota'] = self::getAction('checkquota', $id);
    }
    elseif($mediaItem->isProcessable() && ! $mediaItem->isSomethingOptimized() && ! $mediaItem->isOptimizePrevented() && ! $optimizeController->isItemInQueue($mediaItem))
    {
       $actions['optimize'] = self::getAction('optimize', $id);
       $actions['markCompleted']  = self::getAction('markCompleted', $id);
    }
    elseif ($mediaItem->isUserExcluded() && false === $mediaItem->isSomethingOptimized())
    {
      $actions['optimize'] = self::getAction('forceOptimize', $id);
    }


    return $actions;
  }

  public static function getStatusText($mediaItem)
  {
    $keyControl = ApiKeyController::getInstance();
    $quotaControl = QuotaController::getInstance();
		$optimizeController = new OptimizeController();
    $settings = \wpSPIO()->settings();

    $text = '';

    if (! $keyControl->keyIsVerified())
    {
      $text = __('Invalid API Key. <a href="options-general.php?page=wp-shortpixel-settings">Check your Settings</a>','shortpixel-image-optimiser');
    }
		// This basically happens when a NextGen gallery is not added to Custom Media.
		elseif ($mediaItem->get('id') === 0)
		{
			 if ($mediaItem->isProcessable(true) === false)
			 {
				 $text = __('Not Processable: ','shortpixel_image_optimiser');
				 $text  .= $mediaItem->getProcessableReason();
			 }
			 else {
         if (\wpSPIO()->env()->has_nextgen && false == $settings->includeNextGen)
         {
           $text = __('This image was not found in our database. Enable "Optimize nextgen galleries" in the settings, or add this folder manually. ', 'shortpixel-image-optimiser');
         }
         else {
           $text = __('This image was not found in our database. Refresh folders, or add this gallery', 'shortpixel-image-optimiser');
         }
			 }
		}
    elseif ($mediaItem->isSomethingOptimized())
    {
       $text = UiHelper::renderSuccessText($mediaItem);
    }
    elseif (false === $mediaItem->isProcessable()  )
    {
       $text = __('Not Processable: ','shortpixel_image_optimiser');
       $text  .= $mediaItem->getProcessableReason();
    }
    elseif (! $mediaItem->exists())
    {
       $text = __('File does not exist.','shortpixel-image-optimiser');
    }
    elseif ($mediaItem->getMeta('status') < 0)
    {
      $text = $mediaItem->getMeta('errorMessage');
    }
		elseif( $optimizeController->isItemInQueue($mediaItem) === true)
		{
			 $text = '<p>' . __('This item is waiting to be processed', 'shortpixel-image-optimiser') . '</p>';
			 $action = self::getAction('cancelOptimize', $mediaItem->get('id'));

			 $text .= '<p><a href="javascript:' . $action['function'] . '">' . $action['text'] . '</a></p>';
		}

    if ($mediaItem->isOptimizePrevented() !== false)
    {
          $retry = self::getAction('retry', $mediaItem->get('id'));
          $unmark = self::getAction('unMarkCompleted', $mediaItem->get('id'));
					$redo_legacy = false;

					if ($mediaItem->get('type') == 'media')
					{
	 					$was_converted = get_post_meta($mediaItem->get('id'), '_shortpixel_was_converted', true);
						$updateTs = 1656892800; // July 4th 2022 - 00:00 GMT

						if ($was_converted < $updateTs)
						{
							$meta = $mediaItem->getWPMetaData();
							if (is_array($meta) && isset($meta['ShortPixel']))
							{
								$redo_legacy = self::getAction('redo_legacy', $mediaItem->get('id'));
							}
						}
					}

          $status = $mediaItem->getMeta('status');
          $text = ''; // reset text

          if (ImageModel::FILE_STATUS_MARKED_DONE == $status)
          {
            $text .= "<div class='shortpixel-image-notice'>" . esc_html($mediaItem->getReason('processable'));

            $text .= "<p class='shortpixel-error-reset'>" . sprintf(__('%s Click to unmark as completed %s', 'shortpixel-image-optimiser'), '<a href="javascript:' . $unmark['function'] . '">', '</a>') . '</p>';
            $text .= '</div>';
          }
          else {
            $text .= "<div class='shortpixel-image-error'>" . esc_html($mediaItem->getReason('processable'));
            $text .= "<span class='shortpixel-error-reset'>" . sprintf(__('After you have fixed this issue, you can %s click here to retry %s', 'shortpixel-image-optimiser'), '<a href="javascript:' . $retry['function'] . '">', '</a>') . '</span>';
            $text .= '</div>';
          }



					if ($redo_legacy !== false)
					{
						$text .= "<div class='shortpixel-image-error'><span class='shortpixel-error-reset'>";

						$text .= sprintf(esc_html__('It seems you have older converted legacy data, which might cause this issue. You can try to %s %s %s . If nothing changes, this is not the cause. ','shortpixel-image-optimiser'), '<a href="javascript:' . $redo_legacy['function'] . '">', $redo_legacy['text'], '</a>');
						$text .= "</span></div>";
					}

      }

    return $text;
  }

  // Defines all possible actions in the Ui
  public static function getAction($name, $id, $args = array())
  {
     $action = array('function' => '', 'type' => '', 'text' => '', 'display' => '');
     $keyControl = ApiKeyController::getInstance();

		 $compressionType = isset($args['compressionType']) ? $args['compressionType'] : null;

    switch($name)
    {
      case 'optimize':
         $action['function'] = 'window.ShortPixelProcessor.screen.Optimize(' . $id . ')';
         $action['type']  = 'js';
         $action['text'] = __('Optimize Now', 'shortpixel-image-optimiser');
         $action['display'] = 'button';
      break;
      case 'forceOptimize':
        $action['function'] = 'window.ShortPixelProcessor.screen.Optimize(' . $id . ', true)';
        $action['type']  = 'js';
        $action['text'] = __('Override exclusions and optimize now', 'shortpixel-image-optimiser');
        $action['display'] = 'button';
      break;
			case 'cancelOptimize':
				 $action['function'] = 'window.ShortPixelProcessor.screen.CancelOptimizeItem(' . $id . ')';
				 $action['type']  = 'js';
				 $action['text'] = __('Cancel item optimization', 'shortpixel-image-optimiser');
				 $action['display'] = 'button';
			break;
      case 'markCompleted':
          $action['function'] = 'window.ShortPixelProcessor.screen.MarkCompleted(' . $id . ')';
          $action['type']  = 'js';
          $action['text'] = __('Mark as Completed', 'shortpixel-image-optimiser');
          $action['display'] = 'button-secondary';
          $action['layout'] = 'paragraph';
          $action['title'] = __('This will cause the plugin to skip this image for optimization', 'shortpixel-image-optimiser');
      break;
      case 'unMarkCompleted':
          $action['function'] = 'window.ShortPixelProcessor.screen.UnMarkCompleted(' . $id . ')';
          $action['type']  = 'js';
          $action['text'] = __('Click to unmark this item as done', 'shortpixel-image-optimiser');
          $action['display'] = 'js';

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
		 case 'redo_legacy':
		 			$action['function'] = 'window.ShortPixelProcessor.screen.RedoLegacy(' . $id . ');';
		 			$action['type']  = 'js';
		 			$action['text'] = __('Redo Conversion', 'shortpixel-image-optimiser') ;
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
		 case 'reoptimize-smartcrop':
        $action['function'] = 'window.ShortPixelProcessor.screen.ReOptimize(' . $id . ',' . $compressionType . ',' . ImageModel::ACTION_SMARTCROP . ')';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize with SmartCrop','shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;
		 case 'reoptimize-smartcropless':
        $action['function'] = 'window.ShortPixelProcessor.screen.ReOptimize(' . $id . ',' . $compressionType . ',' . ImageModel::ACTION_SMARTCROPLESS . ')';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize without SmartCrop','shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;
     case 'extendquota':
        $action['function'] = 'https://shortpixel.com/login/'. $keyControl->getKeyForDisplay();
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

	public static function getConvertErrorReason($error)
	{
		switch($error)
		{
			case -1: //ERROR_LIBRARY:
				$reason = __('PNG Library is not present or not working', 'shortpixel-image-optimiser');
			break;
			case -2: //ERROR_PATHFAIL:
				$reason = __('Could not create path', 'shortpixel-image-optimiser');
			break;
			case -3: //ERROR_RESULTLARGER:
				$reason  = __('Result file is larger','shortpixel-image-optimiser');
			break;
			case -4: // ERROR_WRITEERROR
				$reason = __('Could not write result file', 'shortpixel-image-optimiser');
			break;
			case -5: // ERROR_BACKUPERROR
				$reason = __('Could not create backup', 'shortpixel-image-optimiser');
			break;
			case -6:  // ERROR_TRANSPARENT
				$reason = __('Image is transparent', 'shortpixel-image-optimiser');
			break;
			default:
				$reason = sprintf(__('Unknown error %s', 'shortpixel-image-optimiser'), $error);
			break;
		}


		$message = sprintf(__('Not converted: %s ', 'shortpixel-image-optimiser'), $reason);
		return $message;
	}

	public static function getKBSearchLink($subject)
	{
			return esc_url(self::$knowledge_url . sanitize_text_field($subject));
	}

	// @param MediaLibraryModel Object $imageItem
	// @param String $size  Preferred size
	// @param String Preload The preloader tries to guess what the preview might be for a more smooth process. Ignore optimize / backup
	public static function findBestPreview($imageItem, $size = 800, $preload = false)
	{
		 	$closestObj = $imageItem;

			// set the standard.
			if ($imageItem->getExtension() == 'pdf') // try not to select non-showable extensions.
				$bestdiff = 0;
			else
				$bestdiff = abs($imageItem->get('width') - $size);

			$thumbnails = $imageItem->get('thumbnails');

			if (! is_array($thumbnails))
			{
				 return $closestObj; // nothing more to do.
			}

			foreach($thumbnails as $thumbnail)
			{
				 if (! $preload && (! $thumbnail->isOptimized() || ! $thumbnail->hasBackup()))
				 	continue;

					$diff = abs($thumbnail->get('width') - $size);
					if ($diff < $bestdiff)
					{
						 $closestObj = $thumbnail;
						 $bestdiff = $diff;
					}
			}

			return $closestObj;
	}

  public static function formatTS($ts)
  {
      //$format = get_option('date_format') .' @ ' . date_i18n(get_option('time_format');
			if (function_exists('wp_date'))
			{
      	$date = wp_date(get_option('date_format'), $ts);
				$date .= ' @ ' . wp_date(get_option('time_format'), $ts);
			}
			else
			{
      	$date = date_i18n(get_option('date_format'), $ts);
				$date .= ' @ ' . date_i18n(get_option('time_format'), $ts);

			}
      return $date;
  }

  public static function formatBytes($bytes, $precision = 2) {
      $units = array('B', 'KB', 'MB', 'GB', 'TB');

      $bytes = max($bytes, 0);
      $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
      $pow = min($pow, count($units) - 1);

      $bytes /= pow(1024, $pow);

      return number_format_i18n(round($bytes, $precision), $precision) . ' ' . $units[$pow];
  }

	public static function formatNumber($number, $precision = 2)
	{
			global $wp_locale;
			$decimalpoint = isset($wp_locale->number_format['decimal_point']) ? $wp_locale->number_format['decimal_point'] : '.';
			$number =  number_format_i18n( (float) $number, $precision);

 			$hasDecimal = (strpos($number, $decimalpoint) === false) ? false : true;

			// Don't show trailing zeroes if number is a whole unbroken number. -> string comparison because number_format_i18n returns string.
			if ($decimalpoint !== false && $hasDecimal && substr($number, strpos($number, $decimalpoint) + 1) === '00')
			{
				 $number = substr($number, 0, strpos($number, $decimalpoint));
			}
			// Some locale's have no-breaking-space as thousands separator. This doesn't work well in JS / Cron Shell so replace with space.
			$number = str_replace('&nbsp;', ' ', $number);
		  return $number;
	}

	public static function formatDate( $date ) {

	if ( '0000-00-00 00:00:00' === $date->format('Y-m-d ') ) {
			$h_time = '';
	} else {
			$time   = $date->format('U'); //get_post_time( 'G', true, $post, false );
			if ( ( abs( $t_diff = time() - $time ) ) < DAY_IN_SECONDS ) {
					if ( $t_diff < 0 ) {
							$h_time = sprintf( __( '%s from now' ), human_time_diff( $time ) );
					} else {
							$h_time = sprintf( __( '%s ago' ), human_time_diff( $time ) );
					}
			} else {
					$h_time = $date->format( 'Y/m/d' );
			}
	}

	return $h_time;
}

	protected static function convertImageTypeName($name, $type)
	{
		if ($type == 'webp')
		{
			$is_double = \wpSPIO()->env()->useDoubleWebpExtension();
		}
		if ($type == 'avif')
		{
			$is_double = \wpSPIO()->env()->useDoubleAvifExtension();
		}

		if ($is_double)
		{
			 return $name . '.' . $type;
		}
		else
		{
			 return substr($name, 0, strrpos($name, '.')) . '.' . $type;
		}

	}

  /* Strings on settings page that need to be available for both JS and PHP  */
  public static function getSettingsStrings($name = false)
  {

      $strings = array(
      );

      $exclusion_types = array(
          'name' => __('Name', 'shortpixel-image-optimiser'),
          'path' => __('Path', 'shortpixel-image-optimiser'),
          'size' => __('Size', 'shortpixel-image-optimiser'),
      );

      $exclusion_apply = array(
           'all' => __('All', 'shortpixel-image-optimiser'),
           'only-thumbs' => __('Only Thumbnails', 'shortpixel-image-optimiser'),
           'only-custom' =>  __('Only Custom Media Images', 'shortpixel-image-optimiser'),
           'selected-thumbs' => __('Selected Images', 'shortpixel-image-optimiser'),
      );

      $strings['exclusion_types'] = $exclusion_types;
      $strings['exclusion_apply'] = $exclusion_apply;

      if ($name !== false && isset($strings[$name]))
      {
         return $strings[$name];
      }

      return $strings;
  }



} // class
