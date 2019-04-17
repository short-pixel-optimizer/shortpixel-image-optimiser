<?php
namespace ShortPixel;

HelpScout::outputBeacon($view->data->apiKey);
define('SHORTPIXEL_HIDE_API_KEY', true);

?>
<div class="wrap">
<h1><?php _e('ShortPixel Plugin Settings','shortpixel-image-optimiser');?></h1>
<p class='top-menu'>

    <a href="https://shortpixel.com/<?php
    echo(($view->data->apiKey ? "login/".(defined("SHORTPIXEL_HIDE_API_KEY") ? '' : $view->data->apiKey) : "pricing"));
    ?>" target="_blank">
        <?php _e('Upgrade now','shortpixel-image-optimiser');?>
    </a> | <a href="https://shortpixel.com/pricing>#faq" target="_blank"><?php _e('FAQ','shortpixel-image-optimiser');?> </a> |
    <a href="https://shortpixel.com/contact" target="_blank"><?php _e('Support','shortpixel-image-optimiser');?> </a>
</p>

<?php
/* @todo Should be handling notice class notices */
 if($view->notices !== null) {
    //die(ShortPixelVDD($notice['status']));
    switch($notice['status']) {
        case 'error': $extraClass = 'notice-error'; $icon = 'scared'; break;
        case 'success': $extraClass = 'notice-success'; $icon = 'slider'; break;
    }
    ?>
<br/>
<div class="clearfix <?php echo($extraClass);?>" style="background-color: #fff; border-left-style: solid; border-left-width: 4px; box-shadow: 0 1px 1px 0 rgba(0, 0, 0, 0.1); padding: 1px 12px;;width: 95%">
    <img src="<?php echo(plugins_url('/shortpixel-image-optimiser/res/img/robo-' . $icon . '.png'));?>"
         srcset='<?php echo(plugins_url( 'shortpixel-image-optimiser/res/img/robo-' . $icon . '.png' ));?> 1x, <?php echo(plugins_url( 'shortpixel-image-optimiser/res/img/robo-' . $icon . '@2x.png' ));?> 2x'
         class='short-pixel-notice-icon'>
    <p><?php echo($notice['msg']);?></p>
</div>
<?php } ?>

<?php
// @todo Should become part of notices.
if($folderMsg) { ?>
<br/>
<div style="background-color: #fff; border-left: 4px solid #ff0000; box-shadow: 0 1px 1px 0 rgba(0, 0, 0, 0.1); padding: 1px 12px;;width: 95%">
          <p><?php echo($folderMsg);?></p>
</div>
<?php } ?>

<article id="shortpixel-settings-tabs" class="sp-tabs">
    <form name='wp_shortpixel_options' action='options-general.php?page=wp-shortpixel-settings&noheader=true'  method='post' id='wp_shortpixel_options'>
  <?php
    if (! $this->is_verifiedkey)
    {
      $this->loadView('settings/part-nokey');
    }
    else {
      $this->loadView('settings/part-general');
      if ($this->is_verifiedkey)
      {
        $this->loadView('settings/part-advanced');
        $this->loadView('settings/part-cloudflare');
        if ($view->averageCompression !== null)
        {
          $this->loadView('settings/part-statistics');
        }
      }

    }

    ?>
  </form>
</article>

<?php // @todo inline JS ?>
<script>
    jQuery(document).ready(function(){ ShortPixel.initSettings() });
</script>
