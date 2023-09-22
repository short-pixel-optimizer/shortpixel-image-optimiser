'use strict';
// MainScreen as an option for delegate functions
class ShortPixelScreen extends ShortPixelScreenItemBase
{
    isCustom = true;
    isMedia = true;
		type = 'custom';
    folderTree = null;
    currentSelectedPath = null;

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

      var openModalButton = document.querySelector('.open-selectfolder-modal');
      if (null !== openModalButton)
        openModalButton.addEventListener('click', this.OpenFolderModal.bind(this));

      var closeModalButtons = document.querySelectorAll('.shortpixel-modal input.select-folder-cancel, .sp-folder-picker-shade');

      var self = this;
      closeModalButtons.forEach(function (button, index)
      {
          button.addEventListener('click', self.CloseFolderModal.bind(self));
      });

      var addFolderButton = document.querySelector('.modal-folder-picker .select-folder')
      if (null !== addFolderButton)
      {
        addFolderButton.addEventListener('click', this.AddNewFolderEvent.bind(this));
      }

    }

    OpenFolderModal()
    {
      var shade  = document.querySelector(".sp-folder-picker-shade");
    //  this.FadeIn(shade, 500);
      this.Show(shade);

      var picker = document.querySelector(".shortpixel-modal.modal-folder-picker");
      picker.classList.remove('shortpixel-hide');
      picker.style.display = 'block';

      var picker = document.querySelector(".sp-folder-picker");

      if (null === this.folderTree)
      {
        this.folderTree = new ShortPixelFolderTree(picker, this.processor);
        picker.addEventListener('shortpixel-folder.selected', this.HandleFolderSelectedEvent.bind(this));
      }

      //this.FadeIn(picker, 500);
      this.Show(picker);
    }

    HandleFolderSelectedEvent(event)
    {
        var data = event.detail;
        var relpath = data.relpath;

        var selectedField = document.querySelector('.sp-folder-picker-selected');
        selectedField.textContent = relpath;

        this.currentSelectedPath = relpath;

        if (null !== this.currentSelectedPath)
        {
          var addFolderButton = document.querySelector('.modal-folder-picker .select-folder');
          if (null !== addFolderButton)
          {
            addFolderButton.disabled = false;
          }
        }
    }

    CloseFolderModal()
    {
      var shade  = document.querySelector(".sp-folder-picker-shade");
    //  this.FadeOut(shade, 500);
      this.Hide(shade);

        // @todo FadeOut function here
      var picker = document.querySelector('.shortpixel-modal.modal-folder-picker');
    //  this.FadeOut(picker, 1000);
      this.Hide(picker);
    }

    AddNewFolderEvent(event)
    {

      var data = {};
      data.relpath = this.currentSelectedPath;
      data.type = 'folder';
      data.screen_action = 'addCustomFolder';
      data.callback = 'shortpixel.folder.AddNewDirectory';

      window.addEventListener('shortpixel.folder.AddNewDirectory', this.UpdateFolderViewEvent.bind(this), {'once':true});

      this.processor.AjaxRequest(data);

    }

    UpdateFolderViewEvent(event)
    {
        var data = event.detail;

        if (data.folder.result.itemView)
        {
           var element = document.querySelector('.list-overview .item');
           if (null !== element)
           {
                element.insertAdjacentHTML('beforebegin', data.folder.result.itemView);
           }
        }

        this.CloseFolderModal();

    }

    StartFolderScan()
    {
       
    }



} // class
