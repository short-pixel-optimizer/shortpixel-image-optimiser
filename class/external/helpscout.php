<?php
namespace ShortPixel;

// Integration class for HelpScout
class HelpScout
{
  public static function outputBeacon($apiKey)
  {
    ?>
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
        !function(e,o,n){ window.HSCW=o,window.HS=n,n.beacon=n.beacon||{};var t=n.beacon;t.userConfig={
            color: "#1CBECB",
            icon: "question",
            instructions: "Send ShortPixel a message",
            topArticles: true,
            poweredBy: false,
            showContactFields: true,
            showName: false,
            showSubject: true,
            translation: {
                searchLabel: "What can ShortPixel help you with?",
                contactSuccessDescription: "Thanks for reaching out! Someone from our team will get back to you in 24h max."
            }

        },t.readyQueue=[],t.config=function(e){this.userConfig=e},t.ready=function(e){this.readyQueue.push(e)},o.config={docs:{enabled:!0,baseUrl:"//shortpixel.helpscoutdocs.com/"},contact:{enabled:!0,formId:"278a7825-fce0-11e7-b466-0ec85169275a"}};var r=e.getElementsByTagName("script")[0],c=e.createElement("script");
            c.type="text/javascript",c.async=!0,c.src="https://djtflbt20bdde.cloudfront.net/",r.parentNode.insertBefore(c,r);
        }(document,window.HSCW||{},window.HS||{});

        window.HS.beacon.ready(function(){
            HS.beacon.identify({
                email: "<?php $u = wp_get_current_user(); echo($u->user_email); ?>",
                apiKey: "<?php echo($apiKey);?>"
            });
            HS.beacon.suggest( <?php echo( $suggestions ) ?> );
        });
    </script>
    <?php
  }
}
