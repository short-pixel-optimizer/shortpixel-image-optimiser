

var ShortPixelToolTip = function()
{

    this.GetToolTip = function()
    {
        return document.querySelector('li.shortpixel-toolbar-processing');
    }

    this.RefreshStats = function(stats)
    {

    }

    this.DoingProcess = function()
    {
        tooltip = this.GetToolTip();
        tooltip.classList.remove('shortpixel-hide');
        tooltip.classList.add('shortpixel-processing');

        var alert = document.createElement('div');
        alert.className = 'notice notice-error';
        alert.innerHTML = "This is a notification";

        tooltip.parentNode.insertBefore(alert, tooltip.nextSibling);
    }

    this.ProcessEnd = function()
    {
        tooltip = this.GetToolTip();
        tooltip.classList.add('shortpixel-hide');
        tooltip.classList.remove('shortpixel-processing');
    }

    this.HandleError = function()
    {

    }

} // tooltip.
