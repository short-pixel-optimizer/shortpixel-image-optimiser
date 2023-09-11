'use strict';
// MainScreen as an option for delegate functions
class ShortPixelScreen extends ShortPixelScreenItemBase
{
    isCustom = true;
    isMedia = true;
		type = 'custom';

    Init()
  	{
  		super.Init();

      this.InitFolderSelector();
    //  window.addEventListener()

  	}

    RenderItemView(e)
    {
        var data = e.detail;

        if (data.custom)
        {
            var id = data.custom.id;
            var element = document.getElementById('sp-msg-' + id);
            element.outerHTML = data.custom.itemView;

        }
        return false;
    }

    RefreshFolder(id)
    {
        var data = {};
        data.id = id;
        data.type = 'folder';
        data.screen_action = 'refreshFolder';
        data.callback = 'shortpixel.folder.HandleFolderResult';

        window.addEventListener('shortpixel.folder.HandleFolderResult', this.HandleFolderResult.bind(this), {'once':true});

        // AjaxRequest should return result, which will go through Handleresponse, then LoaditemView.
        //this.SetMessageProcessing(id);
        this.processor.AjaxRequest(data);
    }

    StopMonitoringFolder(id)
    {

       if (confirm('Are you sure you want to stop optimizing this folder? '))
       {
         var data = {};
         data.id = id;
         data.type = 'folder';
         data.screen_action = 'removeCustomFolder';
         data.callback = 'shortpixel.folder.HandleFolderResult';

         window.addEventListener('shortpixel.folder.HandleFolderResult', this.HandleFolderResult.bind(this), {'once':true});

         this.processor.AjaxRequest(data);
       }
    }

    HandleFolderResult(e)
    {
       var data = e.detail;

       if (data.folder)
       {
          var folder_id = data.folder.id;
          if (true === data.folder.is_error)
          {
             var el = document.querySelector('.shortpixel-other-media .item.item-' + folder_id + ' .status');
             if (null !== el)
             {
                el.innerHTML = data.folder.message;
             }
             else {
               console.error('Status Element not found for '  + folder_id);
             }
          }

          if (data.folder.fileCount)
          {
             var el = document.querySelector('.shortpixel-other-media .item.item-' + folder_id + ' .files-number');
             if (null !== el)
             {
                el.innerText = data.folder.fileCount;
             }
             else {
               console.error('FileCount Element not found for '  + folder_id);
             }
          }

          if (data.folder.action == 'remove')
          {
             if (true == data.folder.is_done)
             {
                 var el = document.querySelector('.shortpixel-other-media .item.item-' + folder_id);
                 if ( null !== el)
                 {
                   el.remove();
                 }
                 else {
                   console.error('Row Element not found for '  + folder_id);
                 }

             }
          }

       }
    }

    InitFolderSelector()
    {
/*
      jQuery(".select-folder-button").on('click', function(){

      });
      */

      var openModalButton = document.querySelector('.open-selectfolder-modal');
      openModalButton.addEventListener('click', this.OpenFolderModal.bind(this));

      var closeModalButtons = document.querySelectorAll('.shortpixel-modal input.select-folder-cancel, .sp-folder-picker-shade')

      var self = this;
      closeModalButtons.forEach(function (button, index)
      {
          button.addEventListener('click', this.CloseFolderModal);
      });

      jQuery(".shortpixel-modal input.select-folder-cancel, .sp-folder-picker-shade").on('click', function(){
          jQuery(".sp-folder-picker-shade").fadeOut(100); //.css("display", "none");
          jQuery(".shortpixel-modal.modal-folder-picker").addClass('shortpixel-hide');
          jQuery(".shortpixel-modal.modal-folder-picker").hide();
      });
      jQuery(".shortpixel-modal input.select-folder").on('click', function(e){
          //var subPath = jQuery("UL.jqueryFileTree LI.directory.selected A").attr("rel").trim();

          // @todo This whole thing might go, since we don't display files anymore in folderTree.

          // check if selected item is a directory. If so, we are good.
          var selected = jQuery('UL.jqueryFileTree LI.directory.selected');

          // if not a file might be selected, check the nearest directory.
          if (jQuery(selected).length == 0 )
            var selected = jQuery('UL.jqueryFileTree LI.selected').parents('.directory');

          // fail-saif check if there is really a rel.
          var subPath = jQuery(selected).children('a').attr('rel');

          if (typeof subPath === 'undefined') // nothing is selected
            return;

          subPath = subPath.trim();

          if(subPath) {
              var fullPath = jQuery("#customFolderBase").val() + subPath;
              fullPath = fullPath.replace(/\/\//,'/');
            //  console.debug('FullPath' + fullPath);
              jQuery("#addCustomFolder").val(fullPath);
              jQuery("#addCustomFolderView").val(fullPath);
              jQuery(".sp-folder-picker-shade").fadeOut(100);
              jQuery(".shortpixel-modal.modal-folder-picker").css("display", "none");
              jQuery('#saveAdvAddFolder').removeClass('hidden');
          } else {
              alert("Please select a folder from the list.");
          }
      });
    }

    OpenFolderModal()
    {
      var shade  = document.querySelector(".sp-folder-picker-shade");
      this.FadeIn(shade, 500);
    //  jQuery(".sp-folder-picker-shade").fadeIn(100); //.css("display", "block");

      var picker = document.querySelector(".shortpixel-modal.modal-folder-picker");
      picker.classList.remove('shortpixel-hide');
      //picker.show();

    //  jQuery(".shortpixel-modal.modal-folder-picker").removeClass('shortpixel-hide');
    //  jQuery(".shortpixel-modal.modal-folder-picker").show();


      var picker = document.querySelector(".sp-folder-picker");
      picker.parentElement.style.marginLeft = (-picker.width / 2);


      picker.fileTree({
          script: ShortPixel.browseContent,
          multiFolder: false,
      });
    }

    CloseFolderModal()
    {
        // @todo FadeOut function here 
    }



} // class
