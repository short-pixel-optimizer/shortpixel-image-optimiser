/**
 * Short Pixel WordPress Plugin javascript
 */
// init checks bulkProcess on each page. initSettings is when the settings View is being loaded.
jQuery(document).ready(function(){ShortPixel.init(); });

function delayedInit() {
    if(typeof ShortPixel !== 'undefined' && ShortPixel.didInit == false) {

        console.error('ShortPixel: Delayed Init. Check your installation for errors');
        ShortPixel.init();
    } else {
        setTimeout(delayedInit, 10000);
    }
}
setTimeout(delayedInit, 10000);

var ShortPixel = function() {

	 var updateTimer;

	// The InitSettings usually runs before these settings, making everything complicated (@todo)
    function init() {

        if (typeof ShortPixel.API_IS_ACTIVE !== 'undefined') return; //was initialized by the 10 sec. setTimeout, rare but who knows, might happen on very slow connections...

        //are we on media list?
        if( jQuery('table.wp-list-table.media').length > 0) {
            //register a bulk action
            jQuery('select[name^="action"] option:last-child').before('<option value="shortpixel-optimize">' + _spTr.optimizeWithSP
                + '</option><option value="shortpixel-optimize"> → ' + _spTr.optimize
                + '</option><option value="shortpixel-lossy"> → ' + _spTr.redoLossy
                + '</option><option value="shortpixel-glossy"> → ' + _spTr.redoGlossy
                + '</option><option value="shortpixel-lossless"> → ' + _spTr.redoLossless
                + '</option><option value="shortpixel-restore"> → ' + _spTr.restoreOriginal
								+ '</option><option value="shortpixel-smartcrop"> → ' + _spTr.redoSmartcrop
								+ '</option><option value="shortpixel-smartcropless"> → ' + _spTr.redoSmartcropless
                + '</option><option value="shortpixel-mark-completed"> → ' + _spTr.markCompleted
                + '</option>');
        }

        // Extracting the protected Array from within the 0 element of the parent array
        ShortPixel.setOptions(ShortPixelConstants[0]);

				/*if (jQuery('#shortpixel-form-request-key').length > 0)
				{
					  jQuery('#pluginemail').on('change, keyup', jQuery.proxy(this.updateSignupEmail, this));
						jQuery('#request_key').on('mouseenter', jQuery.proxy(this.updateSignupEmail, this));
						jQuery('#request_key').on('click', jQuery.proxy(this.newApiKey, this));
				} */

        if (window.ShortPixelProcessor)
				{
          window.ShortPixelProcessor.Load(ShortPixel['HAS_QUOTA']);
				}
        this.didInit = true;

				// Move footer notices to the top, where it should be.
				$headerEnd = jQuery( '.wp-header-end' );
				jQuery( 'div.shortpixel-notice' ).not( '.inline, .below-h2' ).insertAfter( $headerEnd );

				var settingsPage = document.querySelector('.is-shortpixel-settings-page');
				if (settingsPage !== null)
				{
					  this.initSettings();
				}
    }
    function setOptions(options) {
        for(var opt in options) {
            ShortPixel[opt] = options[opt];
        }
    }
/*
    function isEmailValid(email) {
				var regex = /^\S+@\S+\.\S+$/;
				return regex.test(email);
    }
*/
    function updateSignupEmail() {

				clearTimeout( ShortPixel.updateTimer );

				ShortPixel.updateTimer = setTimeout( function() {

        var email = jQuery('#pluginemail').val().trim();
				var $submit = jQuery('#request_key');
				var isValid = ShortPixel.isEmailValid(email)
        if(isValid) {
            jQuery('#request_key').removeClass('disabled');
						$submit.removeClass('disabled');
						$submit.removeAttr('disabled');
        }
				else
				{
						$submit.attr('disabled', true);
					  $submit.addClass('disabled');
				}
        jQuery('#request_key').attr('href', jQuery('#request_key').attr('href').split('?')[0] + '?pluginemail=' + email);
			}, 1000);
    }

    // Settings part-general
    function validateKey(button){
        jQuery('#valid').val('validate');

        jQuery(button).parents('form').submit();
    }

    jQuery("#key").on('keypress', function(e) {
        if(e.which == 13) {
            jQuery('#valid').val('validate');
        }
    });


    // Settings part-general
    function enableResize(elm) {
        if(jQuery(elm).is(':checked')) {
            jQuery("#width,#height").prop("disabled", false);
            SpioResize.lastW = false; //to trigger the animation
            jQuery(".resize-type-wrap").show(800, window.SpioResize.run);
        } else {
            jQuery("#width,#height").prop("disabled", true);
            window.SpioResize.hide();
            jQuery(".resize-type-wrap").hide(800);
        }
    }


    function setupGeneralTab() {
				// @todo Make something workable out of this
        var rad = 0;

        /*if (typeof document.wp_shortpixel_options !== 'undefined')
          rad = document.wp_shortpixel_options.compressionType;
        */
				if (document.getElementById('compressionType-database') !== null)
					var savedCompression = document.getElementById('compressionType-database').value;
				else
					var savedCompression = null;

        for(var i = 0, prev = null; i < rad.length; i++) {
            rad[i].onclick = function() {

                if(this !== prev) {
                    prev = this;
                }

							 if (this.value == savedCompression)
  					 		jQuery('#compression-type warning').removeClass('display');
							else
							  jQuery('#compression-type warning').addClass('display');
            };
        }

        ShortPixel.enableResize("#resize");

        jQuery("#resize").on('change', function(){ enableResize(this); });
        jQuery(".resize-sizes").on('blur', function(e){
            var elm = jQuery(e.target);

            if(ShortPixel.resizeSizesAlert == elm.val())
              return; // returns if check in progress, presumed.

            ShortPixel.resizeSizesAlert = elm.val();
            var minSize = jQuery("#min-" + elm.attr('name')).val();
            var niceName = jQuery("#min-" + elm.attr('name')).data('nicename');
            if(elm.val() < Math.min(minSize, 1024)) { // @todo is this correct? This will always be < 1024, and give first error
                if(minSize > 1024) {
                    alert( SPstringFormat(_spTr.pleaseDoNotSetLesser1024,niceName) );
                } else {
                    alert( SPstringFormat(_spTr.pleaseDoNotSetLesserSize, niceName, niceName, minSize) );
                }
                e.preventDefault();
                //elm.val(this.defaultValue);
                elm.focus();
            }
            else {
                this.defaultValue = elm.val();
            }
        });

        jQuery('.shortpixel-confirm').on('click', function(event){
            var choice = confirm(event.target.getAttribute('data-confirm'));
            if (!choice) {
                event.preventDefault();
                return false;
            }
            return true;
        });


    }

    /* function apiKeyChanged() {
        jQuery(".wp-shortpixel-options .shortpixel-key-valid").css("display", "none");
        jQuery(".wp-shortpixel-options button#validate").css("display", "inline-block");
    } */



    function checkThumbsUpdTotal(el) {
        var total = jQuery("#" +(el.checked ? "total" : "main")+ "ToProcess").val();
        jQuery("div.bulk-play span.total").text(total);
        jQuery("#displayTotal").text(total);
    }

    function initSettings() {
      //  ShortPixel.adjustSettingsTabs();
        ShortPixel.setupGeneralTab(); // certain alerts.



        jQuery("article.sp-tabs a.tab-link").on('click', function(e){
            var theID = jQuery(e.target).data("id");
            ShortPixel.switchSettingsTab( theID );
        });

				// Init active tab
			/*	var activeTab = document.querySelector('section.sel-tab');
				if (activeTab !== null);
				ShortPixel.switchSettingsTab(activeTab.getAttribute('id')); */
    }

    // Switch between settings tabs.
    /*function switchSettingsTab(target){

        var tab = target.replace("tab-",""),
            beacon = "",
            section = jQuery("section#" +target);

				if (section.length == 0)
				{
					 tab = 'settings'; // if tab does not exist.
				}
        jQuery('input[name="display_part"]').val(tab);
        var uri = window.location.href.toString();
        if (uri.indexOf("?") > 0) {
            var clean_uri = uri.substring(0, uri.indexOf("?"));
            clean_uri += '?' + jQuery.param({'page':'wp-shortpixel-settings', 'part': tab});
          //  window.history.replaceState({}, document.title, clean_uri);
        }

        if(section.length > 0){
            jQuery("section").removeClass("sel-tab");
            jQuery('section .wp-shortpixel-tab-content').fadeOut(50);
            jQuery(section).addClass("sel-tab");

            jQuery(section).find('.wp-shortpixel-tab-content').fadeIn(50, ShortPixel.adjustSettingsTabs);

						var event = new CustomEvent('shortpixel.ui.settingsTabLoad', { detail : {tabName: tab, section: section }});
						window.dispatchEvent(event);

        }
    } */

    // Fixes the height of the current active tab.
  /*  function adjustSettingsTabsHeight(){
        jQuery('.wso.banner').css('opacity', 1);
    } */

  /*  function closeHelpPane() {
        jQuery('#shortpixel-hs-button-blind').remove();
        jQuery('#shortpixel-hs-tools').remove();
        jQuery('#hs-beacon').remove();
        jQuery('#botbutton').remove();
        jQuery('#shortpixel-hs-blind').remove();
    } */

    function checkQuota() {
        var data = {
          action:'shortpixel_check_quota',
          nonce: ShortPixelConstants[0].nonce_ajaxrequest,
          return_json: true
        };

        jQuery.post(ShortPixel.AJAX_URL, data, function(result) {
            window.location.href = result.redirect;
        });
    }

    function percentDial(query, size) {
        jQuery(query).knob({
            'readOnly': true,
            'width': size,
            'height': size,
            'fgColor': '#1CAECB',
            'format' : function (value) {
                 return value + '%';
            }
        });
    }

  /*function browseContent(browseData) {
        browseData.action = 'shortpixel_browse_content';

        var browseResponse = "";
        jQuery.ajax({
            type: "POST",
            url: ShortPixel.AJAX_URL,
            data: browseData,
            success: function(response) {
                 browseResponse = response;
            },
            async: false
        });
        return browseResponse;
    } */


    function newApiKey(event) {
				event.preventDefault();
        ShortPixel.updateSignupEmail();

        if(!jQuery("#tos").is( ":checked" )) {
            event.preventDefault();
            jQuery("#tos-robo").fadeIn(400,function(){jQuery("#tos-hand").fadeIn();});
            jQuery("#tos").click(function(){
                jQuery("#tos-robo").css("display", "none");
                jQuery("#tos-hand").css("display", "none");
            });
            return;
        }
				if (jQuery('#request_key').is(':disabled'))
				{
					return false;
				}
        jQuery('#request_key').addClass('disabled');
        jQuery('#pluginemail_spinner').addClass('is-active');

				jQuery('#shortpixel-form-request-key').submit();

    }

    function proposeUpgrade() {
        //first open the popup window with the spinner
        jQuery("#shortPixelProposeUpgrade .sp-modal-body").addClass('sptw-modal-spinner');
        jQuery("#shortPixelProposeUpgrade .sp-modal-body").html("");
        jQuery("#shortPixelProposeUpgradeShade").css("display", "block");
        jQuery("#shortPixelProposeUpgrade").removeClass('shortpixel-hide');
        jQuery("#shortPixelProposeUpgradeShade").on('click', this.closeProposeUpgrade);
        //get proposal from server
        var browseData = { 'action': 'shortpixel_propose_upgrade', nonce: ShortPixelConstants[0].nonce_ajaxrequest};
        jQuery.ajax({
            type: "POST",
            url: ShortPixel.AJAX_URL,
            data: browseData,
            success: function(response) {
                jQuery("#shortPixelProposeUpgrade .sp-modal-body").removeClass('sptw-modal-spinner');
                jQuery("#shortPixelProposeUpgrade .sp-modal-body").html(response);
            },
						complete: function(response, status)
						{

						}
        });
    }

    function closeProposeUpgrade() {
        jQuery("#shortPixelProposeUpgradeShade").css("display", "none");
        jQuery("#shortPixelProposeUpgrade").addClass('shortpixel-hide');
        if(ShortPixel.toRefresh) {
            ShortPixel.checkQuota();
        }
    }

    // used in bulk restore all interface
    function checkRandomAnswer(e)
    {
        var value = jQuery(e.target).val();
        var answer = jQuery('input[name="random_answer"]').val();
        var target = jQuery('input[name="random_answer"]').data('target');

        if (value == answer)
        {
          jQuery(target).removeClass('disabled').prop('disabled', false);
          jQuery(target).removeAttr('aria-disabled');

        }
        else
        {
            jQuery(target).addClass('disabled').prop('disabled', true);
        }

    }

    function openImageMenu(e) {
            e.preventDefault();
            //install (lazily) a window click event to close the menus
            if(!this.menuCloseEvent) {
                jQuery(window).on('click', function(e){
                    if (!e.target.matches('.sp-dropbtn')) {
                        jQuery('.sp-dropdown.sp-show').removeClass('sp-show');
                    }
                });
                this.menuCloseEvent = true;
            }
            var shown = e.target.parentElement.classList.contains("sp-show");
            jQuery('.sp-dropdown.sp-show').removeClass('sp-show');
            if(!shown) e.target.parentElement.classList.add("sp-show");
    }

// @todo Comparer should probably move to screen-base js
    function loadComparer(id, type) {
        this.comparerData.origUrl = false;
         if(this.comparerData.cssLoaded === false) {
            jQuery('<link>')
                .appendTo('head')
                .attr({
                    type: 'text/css',
                    rel: 'stylesheet',
                    href: this.WP_PLUGIN_URL + '/res/css/twentytwenty.min.css'
                });
            this.comparerData.cssLoaded = 2;
        }
        if(this.comparerData.jsLoaded === false) {
             jQuery.getScript(this.WP_PLUGIN_URL + '/res/js/jquery.twentytwenty.min.js', function(){
                ShortPixel.comparerData.jsLoaded = 2;

            });
            this.comparerData.jsLoaded = 1;
        }

        if(this.comparerData.origUrl === false) {
               if (typeof type == 'undefined')
                  var type = 'media';  // default.
            jQuery.ajax({
                type: "POST",
                url: ShortPixel.AJAX_URL,
								data: { action: 'shortpixel_ajaxRequest', screen_action : 'getComparerData', id : id, type: type, nonce: ShortPixelProcessorData.nonce_ajaxrequest },
                success: function(response) {
                  //  data = JSON.parse(response);

                    jQuery.extend(ShortPixel.comparerData, response);
                    if(ShortPixel.comparerData.jsLoaded == 2) {
                        ShortPixel.displayComparerPopup(ShortPixel.comparerData.width, ShortPixel.comparerData.height, ShortPixel.comparerData.origUrl, ShortPixel.comparerData.optUrl);
                    }
                }
            });
            this.comparerData.origUrl = '';
        }
    }

    function displayComparerPopup(width, height, imgOriginal, imgOptimized) {
        //image sizes
        var origWidth = width;
        //depending on the sizes choose the right modal
        var sideBySide = (height < 150 || width < 350);
        var modal = jQuery(sideBySide ? '#spUploadCompareSideBySide' : '#spUploadCompare');
        var modalShade = jQuery('.sp-modal-shade');

        if(!sideBySide) {
            jQuery("#spCompareSlider").html('<img alt="' +  _spTr.originalImage + '" class="spUploadCompareOriginal"/><img alt="' +  _spTr.optimizedImage + '" class="spUploadCompareOptimized"/>');
        }
        //calculate the modal size
        width = Math.max(350, Math.min(800, (width < 350 ? (width + 25) * 2 : (height < 150 ? width + 25 : width))));
        height = Math.max(150, (sideBySide ? (origWidth > 350 ? 2 * (height + 45) : height + 45) : height * width / origWidth));

        var marginLeft = '-' + Math.round(width/2); // center

        //set modal sizes and display
        jQuery(".sp-modal-body", modal).css("width", width);
        jQuery(".shortpixel-slider", modal).css("width", width);
        modal.css("width", width);
        modal.css('marginLeft',  marginLeft + 'px');
				modal.removeClass('shortpixel-hide');
        jQuery(".sp-modal-body", modal).css("height", height);
        modal.show();
        //modal.parent().css('display', 'block');
        modalShade.show();

        if(!sideBySide) {
            jQuery("#spCompareSlider").twentytwenty({slider_move: "mousemove"});
        }

        // Close Options
        jQuery(".sp-close-button").on('click',  { modal: modal}, ShortPixel.closeComparerPopup);
        jQuery(document).on('keyup.sp_modal_active', { modal: modal}, ShortPixel.closeComparerPopup );
        jQuery('.sp-modal-shade').on('click', { modal: modal},  ShortPixel.closeComparerPopup, );

        //change images srcs
        var imgOpt = jQuery(".spUploadCompareOptimized", modal);
        jQuery(".spUploadCompareOriginal", modal).attr("src", imgOriginal);
        //these timeouts are for the slider - it needs a punch to work :)
        setTimeout(function(){
            jQuery(window).trigger('resize');
        }, 1000);
        imgOpt.load(function(){
            jQuery(window).trigger('resize');
        });
        imgOpt.attr("src", imgOptimized);

    }

    function closeComparerPopup(e) {

				e.data.modal.addClass('shortpixel-hide');
        jQuery('.sp-modal-shade').hide();
        jQuery(document).unbind('keyup.sp_modal_active');
        jQuery('.sp-modal-shade').off('click');
        jQuery(".sp-close-button").off('click');

    }

    function convertPunycode(url) {
        var parser = document.createElement('a');
        parser.href = url;
        if(url.indexOf(parser.protocol + '//' + parser.hostname) < 0) {
            return parser.href;
        }
        return url.replace(parser.protocol + '//' + parser.hostname,  parser.protocol + '//' + parser.hostname.split('.').map(function(part) {return sp_punycode.toASCII(part)}).join('.'));
    }



    return {
        init                : init,
        didInit             : false,
        setOptions          : setOptions,
        //isEmailValid        : isEmailValid,
        updateSignupEmail   : updateSignupEmail,
        validateKey         : validateKey,
        enableResize        : enableResize,
        setupGeneralTab     : setupGeneralTab,
  //      apiKeyChanged       : apiKeyChanged,
        checkThumbsUpdTotal : checkThumbsUpdTotal,
        initSettings        : initSettings,
      //  switchSettingsTab   : switchSettingsTab,
      //  closeHelpPane       : closeHelpPane,
        checkQuota          : checkQuota,
        percentDial         : percentDial,
      //  initFolderSelector  : initFolderSelector,
    //    browseContent       : browseContent,
        newApiKey           : newApiKey,
        proposeUpgrade      : proposeUpgrade,
        closeProposeUpgrade : closeProposeUpgrade,
  //      includeUnlisted     : includeUnlisted,
        checkRandomAnswer : checkRandomAnswer,
      //  recheckQuota        : recheckQuota,
        openImageMenu       : openImageMenu,
        menuCloseEvent      : false,
        loadComparer        : loadComparer,
        displayComparerPopup: displayComparerPopup,
        closeComparerPopup  : closeComparerPopup,
        convertPunycode     : convertPunycode,
      //  checkExifWarning    : checkExifWarning,
      //  checkBackUpWarning  : checkBackUpWarning,
			//	checkSmartCropWarning: checkSmartCropWarning,
        comparerData        : {
            cssLoaded   : false,
            jsLoaded    : false,
            origUrl     : false,
            optUrl      : false,
            width       : 0,
            height      : 0
        },
        toRefresh       : false,
        resizeSizesAlert: false,
        returnedStatusSearching: 0, // How often this status has come back in a row from server.

    }
}(); // End of ShortPixel

// first is string to replace, rest are arguments.
function SPstringFormat() {
  var params = Array.prototype.slice.call(arguments);

  if (params.length === 0)
      return;

   var s = params.shift();

    // skip the first one.
    for (i=0; i< params.length; i++) {
        s = s.replace(new RegExp('\\{' + i + '\\}', 'gm'), params[i]);
    }
    return s;
};
/** This doesn't go well with REACT environments */
/*if (!(typeof String.prototype.format == 'function')) {
    String.prototype.format = stringFormat;
} */


( function( $, w, d ) {
    w.SpioResize = {
        image : {
            width  : 0,
            height : 0
        },
        lag: 2000,
        step1: false,
        step2: false,
        step3: false,
        sizeRule: null,
        initialized: false,
        lastW: false,
        lastH: false,
        lastType: false,
    };

    SpioResize.hide = function() {
        jQuery('.presentation-wrap').css('opacity', 0);
    }

    SpioResize.animate = function(img, step1, frame, step2, rule) {
        img.animate( step1, 1000, 'swing', function(){
            SpioResize.step3 = setTimeout(function(){
                document.styleSheets[0].deleteRule(SpioResize.sizeRule);
                frame.animate(step2, 1000, 'swing', function() {
                    SpioResize.sizeRule = document.styleSheets[0].insertRule(rule);
                })
            }, 600);
        });

    }

    SpioResize.run = function() {
        if(!SpioResize.initialized) {
            var $document = $( d );
            $document.on( 'input change', 'input[name="resizeWidth"], input[name="resizeHeight"]', function(e) {
                clearTimeout(SpioResize.change);
                SpioResize.changeDone = true;
                SpioResize.changeFired = false;
                SpioResize.change = setTimeout( function() {
                    SpioResize.changeFired = true;
                    SpioResize.run();
                }, 1500 );
            } );
            $document.on( 'blur', 'input[name="resizeWidth"], input[name="resizeHeight"]', function(e) {
                if(SpioResize.changeFired) {
                    return;
                }
                clearTimeout(SpioResize.change);
                SpioResize.change = setTimeout( function() {
                    SpioResize.run();
                }, 1500 );
            } );
            $document.on( 'change', 'input[name="resizeType"]', function(e) {
                SpioResize.run();
            });
            SpioResize.initialized = true;
        }

        var w = $('#width').val();
        var h = $('#height').val();
        if(!w || !h) return;
        var type = ($('#resize_type_outer').is(':checked') ? 'outer' : 'inner');
        if(w === SpioResize.lastW && h === SpioResize.lastH && type === SpioResize.lastType) {
            return;
        }
        SpioResize.hide();
        SpioResize.lastW = w;
        SpioResize.lastH = h;
        SpioResize.lastType = type;

        var frame1W = Math.round(120 * Math.sqrt(w / h));
        var frame1H = Math.round(120 * Math.sqrt(h / w));
        var frameAR = frame1W / frame1H;
        if(frame1W > 280) {
            frame1W = 280; frame1H = Math.round(280 / frameAR);
        }
        if(frame1H > 150) {
            frame1H = 150; frame1W = Math.round(150 * frameAR);
        }
        var imgAR = 15 / 8;
        var img = $('img.spai-resize-img');
        img.css('width', '');
        img.css('height', '');
        img.css('margin', '0px');
        var frame = $('div.spai-resize-frame');
        frame.css('display', 'none');
        frame.css('width', frame1W + 'px');
        frame.css('height', frame1H + 'px');
        frame.css('margin', Math.round((156 - frame1H ) / 2) + 'px auto 0');

        clearTimeout(SpioResize.step1); clearTimeout(SpioResize.step2); clearTimeout(SpioResize.step3);
        img.stop(); frame.stop();

        if(SpioResize.sizeRule !== null) {
            document.styleSheets[0].deleteRule(SpioResize.sizeRule);
            SpioResize.sizeRule = null;
        }
        SpioResize.sizeRule = document.styleSheets[0].insertRule('.spai-resize-frame:after { content: "' + w + ' × ' + h + '"; }');
        frame.addClass('spai-resize-frame');

        $('.presentation-wrap').animate( {opacity: 1}, 500, 'swing', function(){
            //because damn chrome is not repainting the frame after we change the sizes otherwise... :(
            frame.css('display', 'block');

            SpioResize.step2 = setTimeout(function(){
                if(type == 'outer') {
                    if(imgAR > frameAR) {
                        var step1 = {
                            height: frame1H + 'px',
                            margin: Math.round((160 - frame1H) / 2) + 'px 0px'
                        };
                        var frameNewW = frame1H * imgAR;
                        var step2 = { width: Math.round(frameNewW) + 'px' };
                        var rule = '.spai-resize-frame:after { content: "' + Math.round(frameNewW * w / frame1W) + ' × ' + h + '"; }';
                    } else {
                        var step1 = {
                            width: frame1W + 'px',
                            margin: Math.round((160 - frame1W / imgAR) / 2) + 'px 0px'
                        };
                        var frameNewH = frame1W / imgAR;
                        var step2 = {
                            height: Math.round(frameNewH) + 'px',
                            margin: Math.round((156 - frameNewH) / 2) + 'px auto 0'
                        };
                        var rule = '.spai-resize-frame:after { content: "' + w + ' × ' + Math.round(frameNewH * w / frame1W) + '"; }';

                    }
                } else {
                    if(imgAR > frameAR) {
                        var step1 = {
                            width: frame1W,
                            margin: Math.round((160 - frame1W / imgAR) / 2) + 'px 0px'
                        };
                        var frameNewH = frame1W / imgAR;
                        var step2 = {
                            height: Math.round(frameNewH) + 'px',
                            margin: Math.round((156 - frameNewH) / 2) + 'px auto 0'
                        };
                        var rule = '.spai-resize-frame:after { content: "' + w + ' × ' + Math.round(frameNewH * w / frame1W) + '"; }';
                    } else {
                        var step1 = {
                            height: frame1H,
                            margin: Math.round((160 - frame1H) / 2) + 'px 0px'
                        };
                        var frameNewW = frame1H * imgAR;
                        var step2 = {
                            width: Math.round(frameNewW) + 'px'
                        };
                        var rule = '.spai-resize-frame:after { content: "' + Math.round(frameNewW * w / frame1W) + ' × ' + h + '"; }';
                    }
                }
                SpioResize.animate(img, step1, frame, step2, rule);
            }, 1000);
        });
    }

    $( function() {
        if($('#resize').is('checked')) {
            SpioResize.run();
        }
    } );
} )( jQuery, window, document );
