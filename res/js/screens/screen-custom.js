'use strict';
// MainScreen as an option for delegate functions
class ShortPixelScreen extends ShortPixelScreenItemBase
{
    isCustom = true;
    isMedia = true;
		type = 'custom';
    folderTree = null;
    currentSelectedPath = null;
		stopSignal = false;

    Init()
  	{
  		super.Init();

      this.InitFolderSelector();
			this.InitScanButtons();
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

		// Check if the processor needs to start when items are being added / folders refreshed
		CheckProcessorStart()
		{
			// If automedia is active, see if something is to process.
			if (this.processor.IsAutoMediaActive())
			{
				 this.processor.SetInterval(-1);
				 this.processor.RunProcess();
			}
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
          if (data.folder.message)
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

			 this.CheckProcessorStart();
    }

		InitScanButtons()
		{
			 var scanButtons = document.querySelectorAll('.scan-button');
			 var self = this;

			 if (null !== scanButtons)
			 {
				 	scanButtons.forEach(function (scanButton) {
						scanButton.addEventListener('click', self.StartFolderScanEvent.bind(self));
					});
			 }

			 var stopButton = document.querySelector('.scan-actions .stop-button');
			 if (null !== stopButton)
			 {
				  stopButton.addEventListener('click', this.StopScanEvent.bind(this));
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
      this.Hide(shade);

      // @todo FadeOut function here
      var picker = document.querySelector('.shortpixel-modal.modal-folder-picker');
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
					 var elementHeading = document.querySelector('.list-overview .heading');

           if (null !== element)
           {
                element.insertAdjacentHTML('beforebegin', data.folder.result.itemView);
           }
					 else if (null !== elementHeading) // In case list is empty.
					 {
						 		elementHeading.insertAdjacentHTML('afterend',  data.folder.result.itemView);
								var noitems = document.querySelector('.list-overview .no-items');
								if (null !== noitems)
									noitems.remove();
					 }

        }
        this.CloseFolderModal();

				this.CheckProcessorStart();

    }

    StartFolderScanEvent(event)
    {
			 var element = event.target;
			 this.stopSignal = false;
			 this.ToggleScanInterface(true);

			 var force = false;
			 if ('mode' in element.dataset)
			 {
				  var force = true;
			 }

		  var reportElement = document.querySelector('.scan-area .result-table');
			while(reportElement.firstChild)
			{
				 reportElement.firstChild.remove();
			}

			var args = [];
			args.force = force;

			if (true === force)
			{
				var data = {};
				data.type = 'folder';
				data.screen_action = 'resetScanFolderChecked';
				data.callback = 'shortpixel.folder.ScanFolder';

				window.addEventListener('shortpixel.folder.ScanFolder', this.ScanFolder.bind(this, args), {'once':true});
				this.processor.AjaxRequest(data);

			}
			else {
				this.ScanFolder(args);
			}

    }

		ScanFolder(args)
		{
			if (true === this.stopSignal)
			{
				 return false;
			}
			var data = {};
			data.type = 'folder';
			data.screen_action = 'scanNextFolder';
			data.callback = 'shortpixel.folder.ScannedDirectoryEvent';

			if (true === args.force)
			{
				 data.force = args.force;
			}

			window.addEventListener('shortpixel.folder.ScannedDirectoryEvent', this.ScannedDirectoryEvent.bind(this, args), {'once':true});
			this.processor.AjaxRequest(data);
		}

		ScannedDirectoryEvent(args, event)
		{
			var data = event.detail;
			data = data.folder;

			var reportElement = document.querySelector('.scan-area .result-table');
			if ( null === reportElement)
			{
				 console.error('Something wrong with reporting element');
				 return false;
			}

			if (data.is_done === true)
			{
				// @todo Probably emit some done status here
				var div = document.createElement('div');
				div.classList.add('message');
				div.textContent = data.result.message;
				this.ToggleScanInterface(false);

				reportElement.appendChild(div);
			}
			else if (data.result)
			{
					var div = document.createElement('div');
					var span_path = document.createElement('span');
					var span_filecount = document.createElement('span');
					var span_message = document.createElement('span');

					span_path.textContent = data.result.path;
					span_filecount.textContent = data.result.new_count;
					span_message.textContent = data.result.message;

					div.appendChild(span_path);
					div.appendChild(span_filecount);
					div.appendChild(span_message);
					reportElement.appendChild(div);

					var self = this;
					setTimeout( function () { self.ScanFolder(args) }, 200);
			}
		}

		StopScanEvent(event)
		{
			 this.stopSignal = true;

			var reportElement = document.querySelector('.scan-area .result-table');
 			if ( null === reportElement)
 			{
 				 console.error('Something wrong with reporting element');
 				 return false;
 			}

			var div = document.createElement('div');
			div.classList.add('message');
			div.textContent = this.strings.stopActionMessage;

			reportElement.appendChild(div);

			this.ToggleScanInterface(false);

		}

		ToggleScanInterface(show)
		{
				if (typeof show === 'undefined')
				{
					 var show = true;
				}

				var divs = document.querySelectorAll('.scan-actions > div');

				divs.forEach(function(div){
						if (div.classList.contains('action-scan') && true === show)
						{
								div.classList.add('not-visible');
						}
						else if (div.classList.contains('action-scan') && false === show) {
								div.classList.remove('not-visible');
						}
						else if ( div.classList.contains('action-stop') && true === show)
						{
							div.classList.remove('not-visible');
						}
						else {
							div.classList.add('not-visible');
						}
				});

				var output = document.querySelector('.scan-area .output');
				if (null !== output && true === show)
				{
					 output.classList.remove('not-visible');
				}
		}

} // class
