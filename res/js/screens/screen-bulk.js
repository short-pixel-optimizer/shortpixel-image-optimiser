

var ShortPixelScreen = function (MainScreen, processor)
{

  this.isCustom = true;
  this.isMedia = true;
  this.processor = processor;

  this.panels = [];
  this.currentPanel;

  this.Init = function()
  {
      // Hook up the button and all.
    //  startBulk.addEventListener('click',  this.ButtonStartBulk.bind(this));
      this.LoadPanels();
      this.LoadActions();
      //this.loadNav();
  },
  this.LoadPanels = function()
  {
      elements = document.querySelectorAll('section.panel');
      var self = this;
      elements.forEach(function (panel, index)
      {
          var panelName  = panel.getAttribute('data-panel');
          self.panels[panelName] = panel;
      });

  },
  this.LoadActions = function()
  {
      actions = document.querySelectorAll('[data-action]');
      var self = this;

      actions.forEach(function (action, index)
      {

          action.addEventListener('click', function(event)
          {
            var element = event.target;
            var actionName = element.getAttribute('data-action');
            var isPanelAction = (actionName == 'open-panel');

            if (isPanelAction)
            {
               var doPanel = element.getAttribute('data-panel');
               this.SwitchPanel(doPanel);
            }
            else
            {

                if (typeof this[actionName] == 'function')
                {
                    this[actionName].call(this);
                }
            }
          }.bind(self));
      });
  },
  this.UpdatePanelStatus = function(status, panelName)
  {
     if (typeof panelName !== 'undefined')
      var panel = this.panels[panelName];
     else
      var panel = this.panels[this.currentPanel];
     panel.setAttribute('data-status', status);
  }
  this.SwitchPanel = function(panelName)
  {
      if (! this.panels[panelName])
      {
        console.error('Panel ' + panelName + ' does not exist?');
        return;
      }
      else if (this.currentPanel == panelName)
      {
        return; // no switching needed.
      }

      this.panels.forEach(function(panel, index)
      {
         panel.style.opacity = 0;
         panel.style.visibility = 'hidden';
      });

      var panel = this.panels[panelName];

      panel.style.opacity = 1;
      panel.style.visibility = 'visible';

    //  panel.querySelectorAll('')

      this.currentPanel = panelName;

    //   var panel = this.panels[panelName];

       if ( panel.getAttribute('data-loadPanel') !== null)
       {

           this[panel.getAttribute('data-loadPanel')].call(this);
       }

  },
  this.StartPrepare = function()
  {
     console.log('Start Bulk');
     var data = {screen_action: 'createBulk', callback: 'shortpixel.prepareBulk'}; //

     // Prepare should happen after selecting what the optimize.
     window.addEventListener('shortpixel.prepareBulk', this.PrepareBulk.bind(this), {'once': true} );
     this.processor.AjaxRequest(data);


  },
  this.PrepareBulk = function()
  {
      //Remove pause
      this.processor.SetInterval(500); // do this faster.
      // Show stats
      if (this.processor.isManualPaused == true)
        this.processor.isManualPaused = false; // force run

      this.processor.RunProcess();

      // Run process.run process from now for prepare ( until prepare done? )
  },
  this.QueueStatus = function(qStatus)
  {
      if (qStatus == 'PREPARING_DONE' || qStatus == 'PREPARING_RECOUNT')
      {
          console.log('Queue status: preparing done');
          this.UpdatePanelStatus('loaded', 'selection');
          this.processor.SetInterval(-1); // back to default.
          //this.SwitchPanel('selection');

      }
    /*  elseif (qStatus == '')
      {

      } */
  }
  this.HandleImage = function(resultItem, type)
  {
      console.log('HandleImage');
      console.log(resultItem, type);


  },

  this.UpdateMessage = function(id, message)
  {
     console.log('UpdateMessage');

  },

  this.UpdateStats = function(stats, type)
  {
      console.log('updating Stats');
      var elements = document.querySelectorAll('[data-stats-' + type + ']');

      if (elements)
      {
          elements.forEach(function (element, index)
          {
                var el = element.getAttribute('data-stats-' + type);
                if (el == null)
                  return;
                var index = el.indexOf('-');
                if (index > -1)
                {
                   var first  = el.substr(0, index);
                   var second = el.substr(index+1);

                   var value = stats[first][second];
                }
                else
                {
                   var value = stats[el];
                }

                element.innerHTML = value;
          });
      }
  },
  this.HandleError = function()
  {

  },
  this.StartBulk = function() // Open panel action
  {


  }
  this.PauseBulk = function (event)
  {
    if (processor.isManualPaused == false)
    {
       processor.isManualPaused = true;
       localStorage.setItem('tooltipPause','true');
       this.processor.tooltip.ProcessPause();
    }

    this.processor.tooltip.ToggleIcon();
  }
  this.StopBulk = function(event)
  {
      this.PauseBulk(event);
      // do something here to nuke the thing
  }






  this.Init();



} // Screen
