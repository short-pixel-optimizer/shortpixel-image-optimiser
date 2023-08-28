<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

?>

<div class='sp-column-info' id='sp-msg-<?php echo esc_attr($this->view->id );?>'>
<?php	if (isset($this->view->list_actions))
	{
	   echo $this->view->list_actions;
	}
	?>
<?php if (property_exists($this->view,'text') && strlen($this->view->text) > 0):  ?>
      <p><?php  echo $this->view->text;  ?></p>
<?php endif;

if (isset($this->view->actions)):
  $this->loadView('snippets/part-single-actions', false);


endif;

?>
</div>
