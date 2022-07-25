'use strict';
// MainScreen as an option for delegate functions
var ShortPixelScreen = function (MainScreen, processor)
{
    this.isCustom = true;
    this.isMedia = true;
    this.processor = processor;

		this.strings = '';

    this.Init = function()
    {
					window.addEventListener('shortpixel.custom.resumeprocessing', this.processor.ResumeProcess.bind(this.processor));
					window.addEventListener('shortpixel.RenderItemView', this.RenderItemView.bind(this) );

					this.strings = spio_screenStrings;
    },
    this.HandleImage = function(resultItem, type)
    {
        if (type == 'media')  // We don't eat that here.
          return false;

        if (typeof resultItem.result !== 'undefined')
        {
            // This is final, not more messing with this. In results (multiple) defined one level higher than result object, if single, it's in result.
            var item_id = typeof resultItem.item_id !== 'undefined' ? resultItem.item_id : resultItem.result.item_id;
            var message = resultItem.result.message;

            var element = document.getElementById('sp-msg-' + item_id); // empty result box while getting
            if (typeof message !== 'undefined')
            {
               this.UpdateMessage(item_id, message);
            }
            if (element !== null)
            {
              element.innerHTML = '';

            var fileStatus = processor.fStatus[resultItem.fileStatus];

              if (fileStatus == 'FILE_SUCCESS' || fileStatus == 'FILE_RESTORED' || resultItem.result.is_done == true)
              {
                this.processor.LoadItemView({id: item_id, type: type});
              }
              else if (fileStatus == 'FILE_PENDING')
              {
                 element.style.display = 'none';
                //this.UpdateMessage(item_id, )
              }

            }
        }
        else
        {
          console.error('handleImage without Result');
          console.log(resultItem);
        }

				return false;
    }

    this.UpdateMessage = function(id, message, isError)
    {

       var element = document.getElementById('sp-message-' + id);
			 if (typeof isError === 'undefined')
			 	 isError = false;

       this.currentMessage = message;

       if (element == null)
       {
           var parent = document.getElementById('sp-msg-' + id);
           if (parent !== null)
           {
             var element = document.createElement('div');
             element.classList.add('message');
             element.setAttribute('id', 'sp-message-' + id);
             parent.parentNode.insertBefore(element, parent.nextSibling);
           }
       }

       if (element !== null)
       {
				  if (element.classList.contains('error'))
						 element.classList.remove('error');

					console.log('update message '  + message)
          element.innerHTML = message;

					if (isError)
						 element.classList.add('error');
       }
       else
       {
        //console.error('Update Message column not found ' + id);
        this.processor.Debug('Update Message Column not found' + id);
       }
    }
		this.SetMessageProcessing = function(id)
		{
				var message = this.strings.startAction;

				var loading = document.createElement('img');
				loading.width = 20;
				loading.height = 20;
				loading.src = this.processor.GetPluginUrl() + '/res/img/bulk/loading-hourglass.svg';


				message += loading.outerHTML;
				this.UpdateMessage(id, message);
		}

    this.UpdateStats = function(stats, type)
    {
				if ( type !== 'total')
					return;

        var waiting = stats.in_queue + stats.in_process;
        this.processor.tooltip.RefreshStats(waiting);
    }
		this.GeneralResponses = function(responses)
	    {
	       console.log(responses);
	       var self = this;

	       if (responses.length == 0)  // no responses.
	         return;

				 var shownId = []; // prevent the same ID from creating multiple tooltips. There will be punishment for this.

	       responses.forEach(function (element, index)
	       {

					  	if (element.id)
							{
									if (shownId.indexOf(element.id) > -1)
									{
										return; // skip
									}
									else
									{
										shownId.push(element.id);
									}
							}

							var message = element.message;
							if (element.filename)
								message += ' - ' + element.filename;

	            self.processor.tooltip.AddNotice(message);
	            if (self.processor.rStatus[element.code] == 'RESPONSE_ERROR')
	            {

	             if (element.id)
	             {
	               var message = self.currentMessage;
	               self.UpdateMessage(element.id, message + '<br>' + element.message);
	               self.currentMessage = message; // don't overwrite with this, to prevent echo.
	             }
	             else
	             {
	                 var errorBox = document.getElementById('shortpixel-errorbox');
	                 if (errorBox)
	                 {
	                   var error = document.createElement('div');
	                   error.classList.add('error');
	                   error.innerHTML = element.message;
	                   errorBox.append(error);
	                 }
	             }
	            }
	       });

	    }

    // For some reason all these functions are repeated up there ^^
		// HandleError is handling from results / result, not ResponseController. Check if it has negative effects it's kinda off now.
    this.HandleError = function(result)
    {
				// console.log('HANDLE ERROR', result);

          if (result.message && result.item_id)
          {
            this.UpdateMessage(result.item_id, result.message, true);
          }

          this.processor.LoadItemView({id: result.item_id, type: 'media'});
          /*if (result.is_done)
          {
            e = {};
            e.detail = {};
            e.detail.media = {};
            e.detail.media.id = result.item_id;
            e.detail.media.result = result.message;
            this.RenderItemView(e); // remove actions.
         } */
    }

    this.RenderItemView = function(e)
    {
        var data = e.detail;
				console.log('RenderItemView', data);

        if (data.custom)
        {
            var id = data.custom.id;
            var element = document.getElementById('sp-msg-' + id);
            element.outerHTML = data.custom.itemView;

        }
        return true;
    }

    this.RestoreItem = function(id)
    {
        var data = {};
        //e.detail;
        data.id = id;
        data.type = 'custom';
        data.screen_action = 'restoreItem';
        //data.callback = 'this.loadItemView';
        // AjaxRequest should return result, which will go through Handleresponse, then LoadiTemView.
				this.SetMessageProcessing(id);
        this.processor.AjaxRequest(data);
        //var id = data.id;
    }
    this.ReOptimize = function(id, compression)
    {
        var data = {
           id : id ,
           compressionType: compression,
           type: 'custom',
           screen_action: 'reOptimizeItem'
        };

			 if (! this.processor.CheckActive())
			     data.callback = 'shortpixel.custom.resumeprocessing';

 			 	this.SetMessageProcessing(id);
        this.processor.AjaxRequest(data);
    }
    this.Optimize = function (id)
    {

       var data = {
          id: id,
          type: 'custom',
          screen_action: 'optimizeItem'
       }

			 if (! this.processor.CheckActive())
			     data.callback = 'shortpixel.custom.resumeprocessing';

			 this.SetMessageProcessing(id);
       this.processor.AjaxRequest(data);
    }

		this.Init();
} // class
