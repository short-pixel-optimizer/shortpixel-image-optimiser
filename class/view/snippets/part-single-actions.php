<?php
foreach($this->view->actions as $actionName => $action):

  $layout = isset($action['layout']) ? $action['layout'] : false;


  if (isset($action['display']))
  {
     $display = $action['display'];
     $classes = $actionName;
     switch($display)
     {
         case 'button':
           $classes = " button-smaller button-primary $actionName ";
         break;
         case 'button-secondary':
            $classes = " button-smaller button $actionName ";
         break;
     }
  }

  $link = ($action['type'] == 'js') ? 'javascript:' . $action['function'] : $action['function'];

  $title = isset($action['title']) ? ' title="' . $action['title'] . '" ' : '';

  if ($layout && $layout == 'paragraph')
  {
     echo "<P>";
  }
  ?>
  <a href="<?php echo $link ?>" <?php echo $title ?> class="<?php echo esc_attr($classes) ?>"><?php echo esc_html($action['text']) ?></a>

  <?php
    if ($layout && $layout == 'paragraph')
    {
       echo "</P>";
    }
  ?>


  <?php
endforeach;
