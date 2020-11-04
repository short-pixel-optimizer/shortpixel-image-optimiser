
// MainScreen as an option for delegate functions
var ShortPixelScreen = function (MainScreen)
{
    this.isCustom = false;
    this.isMedia = true;


    this.handleImage = function(result, type)
    {
        if (result.result.is_done == true)
        {
            console.log(result);
            var element = document.getElementById('sp-msg-' + result.item_id); // empty result box while getting
            if (element !== null)
            {
              element.innerHTML = '';
              var event = new CustomEvent('shortpixel.loadItemView', {detail: {'type' : type, 'id': result.id }}); // send for new item view.
              window.addEventListener('shortpixel.RenderItemView', this.renderItemView.bind(this), {'once': true} );
              window.dispatchEvent(event);
            }
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
            element.innerHTML = data.media.result;

        }
        return true;
    }

} // class
