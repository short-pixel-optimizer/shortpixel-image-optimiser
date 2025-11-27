<?php
namespace ShortPixel;

use PHPCSExtra\Universal\Sniffs\CodeAnalysis\NoEchoSprintfSniff;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}



?>

<section class='dashboard panel active' data-panel="dashboard" style='display: block'  >
  <div class="panel-container">

  <!--
  <div class="bulk-welcome">
    <h3 class="heading">
      <?php printf(esc_html__('ShortPixel Bulk Image Optimization', 'shortpixel-image-optimiser')); ?>
    </h3>
    <?php echo UIHelper::getIcon('res/images/illustration/bulk_welcome.svg'); ?>
  </div>
-->
    <div class='bulk-wrapper'>

      <?php 
      $link = (false !== $view->dashboard_link) ? sprintf('title="%s" href="%s" target="_blank"', $view->dashboard_message, $view->dashboard_link) : ''; 
      ?>
      <a class='top-circle' <?php echo $link ?>>
          <div class='the-circle' style='background-image: url("<?php echo $view->dashboard_icon ?>");'>&nbsp;</div>
      </a>
      <?php //if (false !== $view->dashboard_title): ?>
       <h3 class='title-offer'><?php echo ( (false !== $view->dashboard_title) ? $view->dashboard_title : "Ready to start optimizing?"); ?></h3>
      <?php //endif; ?>



        <button type="button" class="button-primary button start" id="start-optimize" data-action="open-panel" data-panel="selection" <?php echo ($this->view->error) ? "disabled" : ''; ?>  >
            <?php esc_html_e('Start Optimization','shortpixel-image-optimiser'); ?>
        </button>

			<div class='dashboard-text'>
         <a class='button button-primary' type="button" href="<?php echo admin_url('options-general.php?page=wp-shortpixel-settings&part=help'); ?>" target="_blank">
         <span class='icon white'><?php echo UIHelper::getIcon('res/images/icon/help-circle.svg', ['width' => '16']); ?></span> 
         <span><?php _e('Help','shortpixel-image-optimiser'); ?></span>
         </a> 
         <a class='button' type='button' href="https://wordpress.org/support/plugin/shortpixel-image-optimiser/reviews/#new-post" target="_blank">
            <span class='icon'><?php echo UIHelper::getIcon('res/images/icon/heart.svg', ['width' => '16']); ?></span> 
            <span><?php _e('Rate ShortPixel', 'shortpixel-image-optimiser'); ?></span>
         </a>
      </div>


   <?php if ($this->view->error): ?>
     <div class='bulk error'>
        <h3><?php echo esc_html($this->view->errorTitle); ?></h3>
        <p><?php echo $this->view->errorContent; ?></p>
        <?php if (property_exists($this->view, 'errorText')): ?>
            <p class='text'><?php echo esc_html($this->view->errorText) ?></p>
        <?php endif; ?>
     </div>
   <?php endif; ?>

   </div> <!-- bulk-wrappeur -->


   <?php if (count($this->view->logs) > 0): ?>

	 <div id="LogModal" class="shortpixel-modal shortpixel-hide dashboard-modal">
		 <span class="close" data-action="CloseModal" data-id="LogModal">x</span>
	 	  <div class='title'></div>
			<div class="content sptw-modal-spinner"><div class='table-wrapper'></div></div>
	 </div>
	 <div id="LogModal-Shade" class='sp-modal-shade'></div>
   
    <div class='wrap-collap log-wrapper'> 

    <input type="checkbox" id="bulk-history" class='collap-checkbox'>       

    <label for="bulk-history">     
        <h3>
        <span class='icon white'><?php echo UIHelper::getIcon('res/images/icon/history.svg'); ?></span> 
          <?php esc_html_e('Bulk History', 'shortpixel_image_optimizer'); ?>
          <span class='collap-arrow'><?php echo UIHelper::getIcon('res/images/icon/chevron.svg'); ?></span> 
        </h3>
    </label>

   <div class='collap-content'>
    <div class='dashboard-log'>
        <?php
          echo "<div class='head'>";
          foreach($this->view->logHeaders as $header)
          {
            echo "<span>" . esc_attr($header) . "</span>";
          }
          echo "</div>";
          foreach ($this->view->logs as $logItem):
          {
              echo "<div class='data " . esc_attr($logItem['type']) . "'>";

              echo "<span>" . esc_html($logItem['images'])  . '</span>';
              echo "<span>" . $logItem['errors'] . '</span>';

                echo '<span class="checkmark_green date">' . sprintf(esc_html__('%sCompleted%s on %s','shortpixel-image-optimiser'), '','', esc_html($logItem['date'])) . '</span>';

              echo "<span>" . esc_html($logItem['bulkName']) . '</span>';

            echo "</div>";
          }
          ?>


        <?php endforeach; ?>

    </div> <!-- dashboardlog table --> 
    </div> <!-- content -->
        </div> <!-- wrap-collap -->
  <?php endif; ?>


  <?php if (! $this->view->error): ?>
     <div class='shortpixel-bulk-loader' id="bulk-loading" data-status='loading'>
       <div class='loader'>
				 	 <span class="svg-spinner"><?php $this->loadView('snippets/part-svgloader', false); ?></span>

           <span>
           <h2><?php esc_html_e('Please wait, ShortPixel is loading'); ?></h2>

         </span>

       </div>
     </div>
  <?php endif; ?>
 </div> <!-- panel-container -->
</section> <!-- section -->
