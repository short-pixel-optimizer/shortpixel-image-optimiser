<?php
namespace ShortPixel;
?>

<div class="shortpixel-bulk-wrapper">

<?php
//$this->loadView('bulk/part-progressbar');
$this->loadview('bulk/part-dashboard');
$this->loadView('bulk/part-selection');
$this->loadView('bulk/part-summary');
$this->loadView('bulk/part-process');
$this->loadView('bulk/part-results');

if (\wpSPIO()->env()->is_debug)
   $this->loadView('bulk/part-debug'); 
?>



</div> <!-- wrapper -->
