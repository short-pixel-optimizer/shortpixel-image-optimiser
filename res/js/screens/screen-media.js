
// MainScreen as an option for delegate functions
var ShortPixelScreen = function (MainScreen, processor)
{
    this.isCustom = false;
    this.isMedia = true;
    this.processor = processor;

    this.currentMessage = '';

    this.Init = function()
    {
          addEventListener('shortpixel.media.resumeprocessing', this.processor.ResumeProcess.bind(this.processor));
    },
    this.HandleImage = function(resultItem, type)
    {
        if (type == 'custom')  // We don't eat that here.
          return;

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
            //  var event = new CustomEvent('shortpixel.loadItemView', {detail: {'type' : type, 'id': result.id }}); // send for new item view.
            var fileStatus = processor.fStatus[resultItem.result.status];

              if (fileStatus == 'FILE_SUCCESS' || fileStatus == 'FILE_RESTORED' || resultItem.result.is_done == true)
              {
                window.addEventListener('shortpixel.RenderItemView', this.RenderItemView.bind(this), {'once': true} );
                this.processor.LoadItemView({id: item_id, type: type});
              }
              else if (fileStatus == 'FILE_PENDING')
              {
                 element.style.display = 'none';
              }
              //window.dispatchEvent(event);
            }
        }
        else
        {
          console.error('handleImage without Result');
          console.log(resultItem);
        }
        /*if (result.message)
        {
           var element = document.getElementById('sp-message-' + result.item_id); // empty result box while getting
           if (element !== null)
           {
               element.innerHTML = result.message;
           }
        } */
    }

    this.UpdateMessage = function(id, message)
    {
       var element = document.getElementById('sp-message-' + id);
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
          element.textContent = message;
       }
       else
       {
        //console.error('Update Message column not found ' + id);
        this.processor.Debug('Update Message Column not found' + id);
       }
    }
    this.QueueStatus = function(qStatus)
    {
/*        if (qStatus == 'QUEUE_EMPTY')
        {
          var data = {
              type: 'media',
              screen_action: 'finishQueue'
           };

          this.processor.AjaxRequest(data);
        } */
    }

    this.UpdateStats = function(stats, type)
    {
      var waiting = stats.in_queue + stats.in_process;
      this.processor.tooltip.RefreshStats(stats.in_queue);
    }
    this.GeneralResponses = function(responses)
    {
       console.log(responses);
       var self = this;

       if (responses.length == 0)  // no responses.
         return;

       responses.forEach(function (element, index)
       {
            self.processor.tooltip.AddNotice(element.message);
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
                   error.textContent = element.message;
                   errorBox.append(error);
                 }
             }
            }
       });

    }
    this.HandleError = function(response)
    {
          console.error(response);
          if (response.result.is_done)
          {
            e = {};
            e.detail = {};
            e.detail.media = {};
            e.detail.media.id = response.item_id;
            e.detail.media.result = '';
            this.RenderItemView(e); // remove actions.
         }
    }

    this.RenderItemView = function(e)
    {
        var data = e.detail;

        if (data.media)
        {
            var id = data.media.id;

            var element = document.getElementById('sp-msg-' + id);
            element.outerHTML = data.media.result;
        }
        return true;
    }

    this.RestoreItem = function(id)
    {
        var data = {};
        //e.detail;
        data.id = id;
        data.type = 'media';
        data.screen_action = 'restoreItem';
        //data.callback = 'this.loadItemView';
        // AjaxRequest should return result, which will go through Handleresponse, then LoadiTemView.
        this.processor.AjaxRequest(data);
        //var id = data.id;
    },
    this.ReOptimize = function(id, compression)
    {
        var data = {
           id : id ,
           compressionType: compression,
           type: 'media',
           screen_action: 'reOptimizeItem'
        };

        this.processor.AjaxRequest(data);
    }
    this.Optimize = function (id)
    {

       var data = {
          id: id,
          type: 'media',
          screen_action: 'optimizeItem'
       }

       if (! this.processor.CheckActive())
         data.callback = 'shortpixel.media.resumeprocessing';

       this.processor.AjaxRequest(data);
    }

    this.Init();

} // class
