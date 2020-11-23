

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

        this.addNotice('A notice. How lucky you are');
    }

    this.addNotice = function(message)
    {
      var tooltip = this.GetToolTip();
      var toolcontent = tooltip.querySelector('.ab-item');

      var alert = document.createElement('div');
      alert.className = 'toolbar-notice toolbar-notice-error';
      alert.innerHTML = message;

      alertChild = toolcontent.parentNode.insertBefore(alert, tooltip.nextSibling);

      window.setTimeout (this.removeNotice.bind(this), 5000, alertChild);
    }
    this.removeNotice = function(notice)
    {
        notice.style.opacity = 0;
        window.setTimeout(function () { notice.remove() }, 2000);

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
