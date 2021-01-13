
// MainScreen as an option for delegate functions
var ShortPixelScreen = function (MainScreen, processor)
{
    this.isCustom = true;
    this.isMedia = true;
    this.processor = processor;

    this.Init = function()
    {

    },
    this.HandleImage = function(result, type)
    {
      /*  if (result.result.is_done == true)
        {
            console.log(result);
            var element = document.getElementById('sp-msg-' + result.item_id); // empty result box while getting
            if (element !== null)
            {
              element.innerHTML = '';
            //  var event = new CustomEvent('shortpixel.loadItemView', {detail: {'type' : type, 'id': result.id }}); // send for new item view.

              window.addEventListener('shortpixel.RenderItemView', this.renderItemView.bind(this), {'once': true} );
              this.processor.LoadItemView({id: result.item_id, type: type});
              //window.dispatchEvent(event);
            }
        } */
    }

    this.UpdateStats = function()
    {

    }
    this.HandleError = function()
    {

    }

    this.RenderItemView = function(e)
    {
      /*  var data = e.detail;

        if (data.media)
        {
            var id = data.media.id;
            var element = document.getElementById('sp-msg-' + id);
            element.outerHTML = data.media.result;

        }
        return true; */
    }


} // class
