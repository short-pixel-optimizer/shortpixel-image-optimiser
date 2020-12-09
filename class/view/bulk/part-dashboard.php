<?php
namespace ShortPixel;
?>

<section class='dashboard panel active' data-panel="dashboard">

  <?php $this->loadView('bulk/part-progressbar'); ?>

  <div class="panel-container">
    <h2>Dashboard</h3>
    <svg>PlaceHodler</svg>
    <button type="button" class="button-primary" id="start-bulk" data-action="open-panel" data-panel="selection">Optimize</button>
    <p>Hereyou can etc</p>

    <button class="button-secondary">Bulk Restore</button> (i) <button class="button-secondary">Remove Metadata</button> (I)

  </div>
</section>
