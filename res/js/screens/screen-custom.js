
// MainScreen as an option for delegate functions
var ShortPixelScreen = function (MainScreen, processor)
{
    this.isCustom = true;
    this.isMedia = false;
    this.processor = processor;


    this.Init = function()
    {

    },
    this.HandleImage = function(resultItem, type)
    {
        if (type == 'media')  // We don't eat that here.
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

            var fileStatus = processor.fStatus[resultItem.fileStatus];

              if (fileStatus == 'FILE_SUCCESS' || fileStatus == 'FILE_RESTORED' || resultItem.result.is_done == true)
              {
                window.addEventListener('shortpixel.RenderItemView', this.RenderItemView.bind(this), {'once': true} );
                this.processor.LoadItemView({id: item_id, type: type});
              }
              else if (fileStatus == 'FILE_PENDING')
              {
                 //element.style.display = 'none';
                //this.UpdateMessage(item_id, )
              }

            }
        }
        else
        {
          console.error('handleImage without Result');
          console.log(resultItem);
        }

    }

    this.UpdateMessage = function(id, message)
    {
       var element = document.getElementById('sp-message-' + id);
       if (element !== null)
       {
          element.innerHTML = message;
       }
       else
        console.error('Update Message coloumn not found ' + id);
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
console.log(data);
        if (data.custom)
        {
            var id = data.custom.id;
            var element = document.getElementById('sp-actions-' + id);
            element.outerHTML = data.custom.result;

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
        this.processor.AjaxRequest(data);
        //var id = data.id;
    },
    this.ReOptimize = function(id, compression)
    {
        var data = {
           id : id ,
           compressionType: compression,
           type: 'custom',
           screen_action: 'reOptimizeItem'
        };

        this.processor.AjaxRequest(data);
    }
    this.Optimize = function (id)
    {
       var data = {
          id: id,
          type: 'custom',
          screen_action: 'optimizeItem'
       }

       this.processor.AjaxRequest(data);
    }


} // class
