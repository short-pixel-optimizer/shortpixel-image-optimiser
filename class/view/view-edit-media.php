<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>
<div id='shortpixel-data-<?php echo( esc_attr($view->id) );?>' class='column-wp-shortPixel view-edit-media'>
<?php // Debug Data
if (! is_null($view->debugInfo) && is_array($view->debugInfo) && count($view->debugInfo) > 0 ):  ?>
      <div class='debugInfo' id='debugInfo'>

        <a class='debugModal' data-modal="debugInfo" ><?php esc_html_e('Debug Window', 'shortpixel-image-optimiser') ?></a>
        <div class='content wrapper'>

          <?php
          foreach($view->debugInfo as $index => $item):
          /* In Time, replace debug with a better loop, with named vars and all.  Also the big items should be switchable view-wiese ( hidden by default ) to help with the clutter
          foreach($view->debugInfo as $index => $item):
              $name = $item[0];
              $value = $item[1];
              $is_big = (is_object($value) || is_array($value)) ? true : false;

          if ($is_big)
          {
             printf('<div><label><strong>%s</strong> <input type="checkbox"> <span><pre>%s</pre></span></label></div>', $name, print_r($value, true));
          }
          else {
             printf('<div><span><strong>%s</strong></span><span>%s</span></div>', $name, $value);
          } */
        ?>
          <ul class="debug-<?php echo esc_attr($index) ?>">
            <li><strong><?php echo $item[0]; ?></strong>
              <?php
              if (is_array($item[1]) || is_object($item[1]))
              {
                echo "<PRE>" . print_r($item[1], true) . "</PRE>";
              }
              else
                echo $item[1];
              ?>
            </li>
          </ul>
          <?php endforeach; ?>
          <p>&nbsp;</p>
       </div>
    </div>
  <?php endif; ?>

  <?php if (property_exists($this->view, 'text')): ?>
  <div class='sp-column-info'>
		<?php
			    // burger if needed.
			    echo '<p>' . $this->view->list_actions . '</p>'; ?>
		<p><?php  echo $this->view->text;  ?></p></div>

<?php endif; ?>


  <div class='sp-column-stats'>
    <?php
    // single actions
    if (isset($this->view->actions)):
       $this->loadView('snippets/part-single-actions', false);

    endif;

    ?>

    <?php if (property_exists($view, 'stats') && count($view->stats) > 0): ?>
    <ul class='edit-media-stats'>
    <?php foreach($view->stats as $index => $data)
    { ?>
       <li><span><?php echo $data[0] ?></span> <span><?php echo $data[1] ?></span></li>
    <?php } ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

  <div id="sp-message-<?php echo( esc_attr($this->view->id) ); ?>" class='spio-message'>
  <?php if (! is_null($view->status_message)): ?>
  <?php echo esc_html($view->status_message); ?>
  <?php endif; ?>
  </div>

  <div id='shortpixel-errorbox' class="errorbox">&nbsp;</div>

<?php $this->loadView('snippets/part-comparer'); ?>
