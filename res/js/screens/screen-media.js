
// MainScreen as an option for delegate functions
var ShortPixelScreen = function (MainScreen, processor)
{
    this.isCustom = false;
    this.isMedia = true;
    this.processor = processor;


    this.Init = function()
    {

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
        console.error('Update Message coloumn not found ' + id);
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
    this.HandleError = function()
    {

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

       this.processor.AjaxRequest(data);
    }

    this.Init();

} // class
