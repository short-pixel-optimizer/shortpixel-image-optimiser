<?php
namespace ShortPixel;

use ShortPixel\Controller\ApiKeyController as ApiKeyController;

// Integration class for HelpScout
class HelpScout
{
  public static function outputBeacon()
  {
      return; // this is disabled. 

      global $shortPixelPluginInstance;
      $settings = \wpSPIO()->settings();
      $dismissed = $settings->dismissedNotices ? $settings->dismissedNotices : array();
      if(isset($dismissed['help'])) {
          return;
      }

  //    if ($settings->helpscoutOptin <> 1)


      $keyControl = ApiKeyController::getInstance();
      $apikey = $keyControl->getKeyForDisplay();

    ?>
    <style>
           .shortpixel-hs-blind {
               position: fixed;
               bottom: 4px;
               right: 0;
               z-index: 20003;
               background-color: white;
               width: 87px;
               height: 188px;
               border-radius: 20px 0 0 20px;
               text-align: right;
               padding-right: 15px;
           }
           .shortpixel-hs-blind a {
               color: lightgray;
               text-decoration: none;
           }
           .shortpixel-hs-blind i.dashicons {
               margin-top: -8px;
           }
           .shortpixel-hs-blind .dashicons-minus {
               border: 3px solid;
               border-radius: 12px;
               font-size: 12px;
               font-weight: bold;
               line-height: 15px;
               height: 13px;
               width: 13px;
               display:none;
           }
           .shortpixel-hs-blind .dashicons-dismiss {
               font-size: 23px;
               line-height: 19px;
               display: none;
           }
           .shortpixel-hs-blind:hover .dashicons-minus,
           .shortpixel-hs-blind:hover .dashicons-dismiss {
               display: inline-block;
           }
           .shortpixel-hs-button-blind {
               display:none;
               position: fixed;
               bottom: 115px;right: 0;
               z-index: 20003;
               background-color: white;
               width: 237px;
               height: 54px;
           }
           .shortpixel-hs-tools {
               position: fixed;
               bottom: 116px;
               right: 0px;
               z-index: 20003;
               background-color: #ecf9fc;
               padding: 8px 18px 3px 12px;
               border-radius: 26px 0 0 26px;
               -webkit-box-shadow: 1px 1px 5px 0px rgba(6,109,117,1);
               -moz-box-shadow: 1px 1px 5px 0px rgba(6,109,117,1);
               box-shadow: 1px 1px 10px 0px rgb(172, 173, 173);
           }
           @media (max-width: 767px) {
               .shortpixel-hs-blind {
                   bottom: 8px;
                   height: 194px;
               }
               .shortpixel-hs-button-blind {
                   bottom: 100px;
               }
           }
       </style>
      <div id="shortpixel-hs-blind" class="shortpixel-hs-blind">
          <a href="javascript:ShortPixel.closeHelpPane();">
              <i class="dashicons dashicons-minus" title="<?php _e('Dismiss for now', 'shortpixel-image-optimiser'); ?>   "></i>
          </a>
          <a href="javascript:ShortPixel.dismissHelpPane();">
              <i class="dashicons dashicons-dismiss" title="<?php _e('Never display again', 'shortpixel-image-optimiser'); ?>"></i>
          </a>
      </div>
       <!--<div id="shortpixel-hs-button-blind" class="shortpixel-hs-button-blind"></div>-->
       <div id="shortpixel-hs-tools" class="shortpixel-hs-tools">
           <a href="javascript:shortpixelToggleHS();" class="shortpixel-hs-tools-docs" title="<?php _e('Search through our online documentation.', 'shortpixel-image-optimiser'); ?>">
               <img alt="<?php _e('ShortPixel document icon', 'shortpixel-image-optimiser'); ?>" src="<?php echo( wpSPIO()->plugin_url('res/img/notes-sp.png') );?>" style="margin-bottom: 2px;width: 36px;">
           </a>
       </div>
       <script>
           window.shortpixelHSOpen = 0;//-1;
           function shortpixelToggleHS() {
               //if(window.shortpixelHSOpen == -1) {
               //    HS.beacon.init();
               //}
               if(window.shortpixelHSOpen == 1) {
                   window.Beacon('close');
                   jQuery('#botbutton').addClass('show');
                   jQuery('div.shortpixel-hs-tools').css('bottom', '116px');
                   jQuery('div.shortpixel-hs-blind').css('height', '188px');
                   jQuery('div.shortpixel-hs-blind').css('border-radius', '20px 0 0 20px');
                   jQuery('div.shortpixel-hs-blind a').css('display', 'inline');
                   window.shortpixelHSOpen = 0;
               } else {
                   window.Beacon('open');
                   jQuery('#botbutton').removeClass('show');
                   jQuery('div.shortpixel-hs-tools').css('bottom', '40px');
                   jQuery('div.shortpixel-hs-blind').css('height', '93px');
                   jQuery('div.shortpixel-hs-blind').css('border-radius', '0 0 0 20px');
                   jQuery('div.shortpixel-hs-blind a').css('display', 'none');
                   window.shortpixelHSOpen = 1;
               }
           }
       </script>
       <script type="text/javascript" src="https://quriobot.com/qb/widget/KoPqxmzqzjbg5eNl/V895xbyndnmeqZYd" async defer></script>

    <script>
        <?php
        $screen = get_current_screen();
        if($screen) {
            switch($screen->id) {
                case 'media_page_wp-short-pixel-bulk':
                    echo("var shortpixel_suggestions =              [ '5a5de2782c7d3a19436843af', '5a5de6902c7d3a19436843e9', '5a5de5c42c7d3a19436843d0', '5a9945e42c7d3a75495145d0', '5a5de1c2042863193801047c', '5a5de66f2c7d3a19436843e0', '5a9946e62c7d3a75495145d8', '5a5de4f02c7d3a19436843c8', '5a5de65f042863193801049f', '5a5de2df0428631938010485' ]; ");
                    $suggestions = "shortpixel_suggestions";
                    break;
                case 'settings_page_wp-shortpixel-settings':
                    echo("var shortpixel_suggestions_settings =     [ '5a5de1de2c7d3a19436843a8', '5a6612032c7d3a39e6263a1d', '5a5de1c2042863193801047c', '5a5de2782c7d3a19436843af', '5a6610c62c7d3a39e6263a02', '5a9945e42c7d3a75495145d0', '5a5de66f2c7d3a19436843e0', '5a6597e80428632faf620487', '5a5de5c42c7d3a19436843d0', '5a5de5642c7d3a19436843cc' ]; ");
                    echo("var shortpixel_suggestions_adv_settings = [ '5a5de4f02c7d3a19436843c8', '5a8431f00428634376d01dc4', '5a5de58b0428631938010497', '5a5de65f042863193801049f', '5a9945e42c7d3a75495145d0', '5a9946e62c7d3a75495145d8', '5a5de57c0428631938010495', '5a5de2d22c7d3a19436843b1', '5a5de5c42c7d3a19436843d0', '5a5de5642c7d3a19436843cc' ]; ");
                    echo("var shortpixel_suggestions_cloudflare =   [ '5a5de1f62c7d3a19436843a9', '5a5de58b0428631938010497', '5a5de66f2c7d3a19436843e0', '5a5de5c42c7d3a19436843d0', '5a5de6902c7d3a19436843e9', '5a5de51a2c7d3a19436843c9', '5a9946e62c7d3a75495145d8', '5a5de46c2c7d3a19436843c1', '5a5de1de2c7d3a19436843a8', '5a6597e80428632faf620487' ]; ");
                    $suggestions = "shortpixel_suggestions_settings";
                    break;
                case 'media_page_wp-short-pixel-custom':
                    echo("var shortpixel_suggestions =              [ '5a9946e62c7d3a75495145d8', '5a5de1c2042863193801047c', '5a5de2782c7d3a19436843af', '5a5de6902c7d3a19436843e9', '5a5de4f02c7d3a19436843c8', '5a6610c62c7d3a39e6263a02', '5a9945e42c7d3a75495145d0', '5a5de46c2c7d3a19436843c1', '5a5de1de2c7d3a19436843a8', '5a5de25c2c7d3a19436843ad' ]; ");
                    $suggestions = "shortpixel_suggestions";
                    break;
            }
        }
        ?>
        !function(e,t,n){
            function a(){
                var e=t.getElementsByTagName("script")[0],n=t.createElement("script");n.type="text/javascript",n.async=!0,n.src="https://beacon-v2.helpscout.net",e.parentNode.insertBefore(n,e)
            }
            if(e.Beacon=n=function(t,n,a){
                e.Beacon.readyQueue.push({method:t,options:n,data:a})
            },n.readyQueue=[],"complete"===t.readyState) return a();
            e.attachEvent?e.attachEvent("onload",a):e.addEventListener("load",a,!1)
        }(window,document,window.Beacon||function(){});
        window.Beacon('init', 'e41d21e0-f3c4-4399-bcfe-358e59a860de');

        window.Beacon('identify', {
            email: "<?php $u = wp_get_current_user(); echo($u->user_email); ?>",
                apiKey: "<?php echo($apikey);?>"
        });
        window.Beacon('suggest', <?php echo( $suggestions ) ?>);
    </script>
    <?php
  }
}
