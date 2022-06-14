<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;



?>
<div class='sp-column-info' id='sp-msg-<?php echo($this->view->mediaItem->get('id') );?>'>
<?php if (property_exists($this->view,'text') && strlen($this->view->text) > 0):  ?>
      <p><?php  echo $this->view->text;  ?></p>
<?php endif;

if (isset($this->view->actions)):
  foreach($this->view->actions as $actionName => $action):
    $classes = ($action['display'] == 'button') ? " button-smaller button-primary $actionName " : "$actionName";
    $link = ($action['type'] == 'js') ? 'javascript:' . $action['function'] : $action['function'];

    ?>
    <a href="<?php echo $link ?>" class="<?php echo $classes ?>"><?php echo $action['text'] ?></a>

    <?php
  endforeach;

endif;



if (isset($this->view->list_actions))
{
   echo $this->view->list_actions;
}
?>
</div>


<?php //$this->loadView('snippets/part-comparer'); ?>
