<?php
namespace ShortPixel;

?>
<section class='panel selection' data-panel="selection" data-loadpanel="startBulkProcess">
  <header>
      [Image]
      <h2>ShortPixel Bulk Optimization - Select images</h2>
  </header>

  <p>Welcome to the bulk optimization wizard, where you will be able to select the images that ShortPixel will optimize in the background for you.</p>

   <?php $this->loadView('bulk/part-progressbar'); ?>


   <div class="media-library optiongroup">
      <input type="input" class="switch">  Your Media Library
      <div class='option'>
        Original Images  <span class="number">0</span>
      </div>
      <div class='option'>
        Thumbnails <span class="number">0</span>
      </div>
   </div>

   <div class="theme-images optiongroup not-implemented">
      <input type="input" class="switch">  Your Media Library
      <div class='option'>
        Original Images  <span class="number">0</span>
      </div>
      <div class='option'>
        Thumbnails <span class="number">0</span>
      </div>
   </div>


   <div class='optiongroup'>
      <input type="checkbox" class="switch"> Also Webp.
   <nav><button class="button-primary" type="button" data-action="open-panel" data-panel="summary" >Next</button></nav>
</section>
