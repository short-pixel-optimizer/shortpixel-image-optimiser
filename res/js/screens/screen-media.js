
// MainScreen as an option for delegate functions
var ShortPixelScreen = function (MainScreen, processor)
{
    this.isCustom = false;
    this.isMedia = true;
    this.processor = processor;

    this.init = function()
    {

    },
    this.handleImage = function(result, type)
    {
        if (type == 'custom')  // We don't eat that here.
          return;

        if (typeof result.result !== 'undefined')
        {
            console.log(result);
            var element = document.getElementById('sp-msg-' + result.item_id); // empty result box while getting
            if (typeof result.message !== 'undefined')
            {
               this.updateMessage(id, result.message);
            }
            if (element !== null)
            {
              element.innerHTML = '';
            //  var event = new CustomEvent('shortpixel.loadItemView', {detail: {'type' : type, 'id': result.id }}); // send for new item view.

              window.addEventListener('shortpixel.RenderItemView', this.renderItemView.bind(this), {'once': true} );
              this.processor.LoadItemView({id: result.item_id, type: type});
              //window.dispatchEvent(event);
            }
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

    this.updateMessage = function(id, message)
    {
       var element = document.getElementById('sp-message-' + id);
       if (element !== null)
       {
          element.innerHTML = message;
       }
    }

    this.updateStats = function()
    {

    }
    this.handleError = function()
    {

    }

    this.renderItemView = function(e)
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

    this.restoreItem = function(id)
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
    this.reOptimize = function(id, compression)
    {
        var data = {
           id : id ,
           compressionType: compression,
           type: 'media',
           screen_action: 'reOptimizeItem'
        };

        this.processor.AjaxRequest(data);
    }
    this.optimize = function (id)
    {

       var data = {
          id: id,
          type: 'media',
          screen_action: 'optimizeItem'
       }

       this.processor.AjaxRequest(data);
    }


} // class
