<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


$datastring= '';
if (property_exists($this->view, 'infoData'))
{

	 foreach($this->view->infoData as $key => $data)
	 {
		 	$datastring .= ' data-' . $key . '="' . $data . '"';
	 }
}

?>

<div class='sp-column-info <?php echo property_exists($this->view, 'infoClass') ? $this->view->infoClass : '' ?>'
	 	  <?php echo $datastring; ?>
			id='shortpixel-data-<?php echo esc_attr($this->view->id );?>'>
<?php	if (isset($this->view->list_actions))
	{
	   echo $this->view->list_actions;
	}
	
	?>
<div class='statusText'>
<?php if (property_exists($this->view,'text') && ! is_null($this->view->text) && strlen($this->view->text) > 0):  ?>
    

<?php 	  if (property_exists($this->view, 'ai_icon'))
{
	// ugly workaround. 
	ob_start(); 
 	$this->loadView('view-list-ai-media', false);
	$aiView = ob_get_contents();
	
	$this->view->text = str_replace('<!-- eofsngline -->', $aiView, $this->view->text);
	ob_end_clean(); 

	
	
} ?>
	<?php  echo  $this->view->text;  ?>  

</div>
<?php endif;


if (property_exists($this->view, 'actions')):
  $this->loadView('snippets/part-single-actions', false);
endif;



?>

</div>
