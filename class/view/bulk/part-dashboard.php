<?php
namespace ShortPixel;
?>

<section class='dashboard panel active' data-panel="dashboard" style='display: block'  >

  <?php //$this->loadView('bulk/part-progressbar'); ?>

  <div class="panel-container">


    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      Welcome to the Bulk Processing page. You can add a bulk job by selecting one of the options below
    </h3>

    <div class='interface wrapper'>

      <div class='bulk-wrapper'>
        <button type="button" class="button-primary" id="start-optimize" data-action="StartPrepare" <?php echo ($this->view->error) ? "disabled" : ''; ?>  >
            <span class='dashicons dashicons-controls-play' data-action="StartPrepare">&nbsp;</span> Optimize
        </button>
      </div>

      <p class='description'>Here you can (re)optimize your Media Library, image files from your theme or other media folders that you are using on your site.

   </div>

   <?php if ($this->view->error): ?>
     <div class='bulk error'>
        <h3><?php echo $this->view->errorTitle; ?></h3>
        <?php echo $this->view->errorContent; ?>
     </div>

   <?php endif; ?>
   <?php if (count($this->view->logs) > 0): ?>

   <div class='dashboard-log'>
      <h3><?php _e('Previous Bulks', 'shortpixel_image_optimizer'); ?></h3>
      <?php
        echo "<div class='head'>";
        foreach($this->view->logHeaders as $header)
        {
           echo "<span>$header</span>";
        }
        echo "</div>";
        foreach ($this->view->logs as $logItem):
        {
          echo "<div class='data'>";
          foreach($logItem as $item)
          {
            echo "<span>$item</span>";
          }
          echo "</div>";
        }
        ?>


      <?php endforeach; ?>

   </div>
  <?php endif; ?>

  <?php if (! $this->view->error): ?>
     <div class='shortpixel-bulk-loader' id="bulk-loading" data-status='loading'>
       <div class='loader'>
           <span><img src="<?php echo \wpSPIO()->plugin_url('res/img/spinner2.gif'); ?>" /></span>
           <span>
           <h2>Please wait, ShortPixel is loading</h2>

         </span>

       </div>
     </div>
  <?php endif; ?>
 </div> <!-- panel-container -->
</section> <!-- section -->
