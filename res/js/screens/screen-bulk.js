

var ShortPixelScreen = function (MainScreen, processor)
{

  this.isCustom = true;
  this.isMedia = true;
  this.processor = processor;

  this.panels = [];

  this.init = function()
  {
      // Hook up the button and all.
    //  startBulk.addEventListener('click',  this.ButtonStartBulk.bind(this));
      this.loadPanels();
      this.loadActions();
      //this.loadNav();
  },
  this.loadPanels = function()
  {
      elements = document.querySelectorAll('section.panel');
      var self = this;
      elements.forEach(function (panel, index)
      {
          var panelName  = panel.getAttribute('data-panel');
          self.panels[panelName] = panel;
      });

  },
  this.loadActions = function()
  {
      actions = document.querySelectorAll('[data-action]');
      var self = this;

      actions.forEach(function (action, index)
      {

          action.addEventListener('click', function(event)
          {
            console.log(this.switchPanel);
            var element = event.target;
            var isPanelAction = (element.getAttribute('data-action') == 'open-panel');

            if (isPanelAction)
            {
               var doPanel = element.getAttribute('data-panel');
               this.switchPanel(doPanel);
            }
          }.bind(self));
      });
  },
  this.switchPanel = function(panelName)
  {
      if (! this.panels[panelName])
      {
        console.error('Panel ' + panelName + ' does not exist?');
        return;
      }

      this.panels.forEach(function(panel, index)
      {
         //var panel = this.panels[i];
         //console.log(panel);
         panel.style.opacity = 0;
         panel.style.visibility = 'hidden';
      });


      this.panels[panelName].style.opacity = 1;
      this.panels[panelName].style.visibility = 'visible';

       var panel = this.panels[panelName];

       if ( panel.getAttribute('data-loadPanel') !== null)
       {
           //var func = function() {  panel.getAttribute()}
        //   var
           this[panel.getAttribute('data-loadPanel')].call(this);
       }

  },
  this.startBulkProcess = function()
  {
     console.log('Start Bulk');
     console.log(this);

  }
  this.handleImage = function(resultItem, type)
  {


  },

  this.updateMessage = function(id, message)
  {


  },

  this.updateStats = function()
  {

  },
  this.handleError = function()
  {

  },
  this.buttonStartBulk = function()
  {
      //this.AjaxRequest
  }










  this.init();



} // Screen
