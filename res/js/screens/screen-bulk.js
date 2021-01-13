

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
      this.LoadPanels();
      this.LoadActions();

      console.log(ShortPixelScreenBulk);
      window.addEventListener('shortpixel.processor.paused', this.TogglePauseNotice.bind(self));

      var initMedia = ShortPixelScreenBulk.media;
      var initCustom = ShortPixelScreenBulk.custom;
      isPreparing = false;
      isRunning = false;

      if (initMedia.is_preparing == true || initCustom.is_preparing == true )
        isPreparing = true;
      else if (initMedia.is_running == true || initCustom.is_running == true )
        isRunning = true;


      if (isPreparing)
      {
        this.SwitchPanel('selection');
      }
      if (isRunning)
      {
        this.SwitchPanel('process');
        this.processor.PauseProcess(); // when loading, default start paused before resume.
      }
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
  this.SwitchPanel = function(targetName)
  {

      if (! this.panels[targetName])
      {
        console.error('Panel ' + targetName + ' does not exist?');
        return;
      }
      else if (this.currentPanel == targetName)
      {
        return; // no switching needed.
      }

  //    this.panels.forEach(function(panel, index)
      for (panelName in this.panels)
      {
         var panel = this.panels[panelName];
         panel.style.opacity = 0;
         panel.style.visibility = 'hidden';
         panel.style.zIndex = -1;
         panel.classList.remove('active');
      };

      var panel = this.panels[targetName];

      panel.style.opacity = 1;
      panel.style.visibility = 'visible';
      panel.style.zIndex = 1;
      panel.classList.add('active');

    //  panel.querySelectorAll('')

      this.currentPanel = targetName;

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
      {
         this.processor.ResumeProcess();
        //this.processor.isManualPaused = false; // force run
      }
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
      if (qStatus == 'QUEUE_EMPTY')
      {
          this.UpdatePanelStatus('queueDone', 'process'); 

      }
    /*  elseif (qStatus == '')
      {

      } */
  }
  this.HandleImage = function(resultItem, type)
  {
      console.log('HandleImage');
      console.log(resultItem, type);
      var result = resultItem.result;
      if ( this.processor.fStatus[result.status] == 'FILE_DONE')
      {
          this.UpdateData('result', result);
          if (result.original)
          {
              var el = document.querySelector('.image-source img');
              if (el)
                el.src  = result.original;
          }
          if (result.optimized)
          {
              var el = document.querySelector('.image-result img');
              if (el)
                el.src  = result.original;
          }

          if (result.improvements.totalpercentage)
          {
              var circle = document.querySelector('.opt-circle-image');

              var total_circle = 289.027;
              if(result.improvements.totalpercentage >0 ) {
                  total_circle = Math.round(total_circle-(total_circle*result.improvements.totalpercentage/100));
              }

              for(i = 0; i < circle.children.length; i++)
              {
                 var child = circle.children[i];
                 if (child.classList.contains('path'))
                 {
                    child.style.strokeDashoffset = total_circle + 'px';
                 }
                 else if (child.classList.contains('text'))
                 {
                    child.textContent = result.improvements.totalpercentage + '%';
                 }
              }

          }
      }


  },

  this.UpdateMessage = function(id, message)
  {
     console.log('UpdateMessage');

  },
  this.UpdateStats = function(stats, type)
  {
      this.UpdateData('stats', stats, type);
  }
  // dataName refers to domain of data i.e. stats, result. Those are mentioned in UI with data-stats-media="total" or data-result
  this.UpdateData = function(dataName, data, type)
  {
      console.log('updating Data :' + dataName);
      if (typeof type == 'undefined')
      {
          var elements = document.querySelectorAll('[data-' + dataName + ']');
          var attribute = 'data-' + dataName;
      }
      else
      {
        var elements = document.querySelectorAll('[data-' + dataName + '-' + type + ']');
        var attribute = 'data-' + dataName + '-' + type;
      }

      if (elements)
      {
          elements.forEach(function (element, index)
          {
                var el = element.getAttribute(attribute);
                var presentation = false;
                if (element.hasAttribute('data-presentation'))
                  presentation = element.getAttribute('data-presentation');

                if (el == null)
                  return;
                var index = el.indexOf('-');
                if (index > -1)
                {
                   var first  = el.substr(0, index);
                   var second = el.substr(index+1);
                   if (typeof data[first] !== 'undefined' && typeof data[first][second] !== 'undefined')
                    var value = data[first][second];
                  else
                    var value = 'n/a';
                }
                else
                {
                   if (typeof data[el] !== 'undefined')
                    var value = data[el];
                   else
                    var value = 'n/a';
                }

                if (presentation)
                {
                    if (presentation == 'css.width.percentage')
                      element.style.width = value + '%';
                }
                else
                  element.textContent = value;

          });
      }
  },
  this.HandleError = function()
  {

  },
  this.StartBulk = function() // Open panel action
  {
      console.log('Starting to Bulk!');
      var data = {screen_action: 'startBulk'}; //

      // Prepare should happen after selecting what the optimize.
      //window.addEventListener('shortpixel.prepareBulk', this.PrepareBulk.bind(this), {'once': true} );
      this.processor.AjaxRequest(data);

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
  },

  this.TogglePauseNotice = function(event)
  {
     console.log(event);
     var data = event.detail;

     var el = document.getElementById('processPaused'); // paused overlay
     var buttonPause  = document.getElementById('PauseBulkButton'); // process window buttons
     var buttonResume = document.getElementById('ResumeBulkButton');

     if(data.paused == true)
     {
        el.style.display = 'block';
        buttonPause.style.display = 'none';
        buttonResume.style.display = 'inline-block';

     }
     else
     {
        el.style.display = 'none';
        buttonPause.style.display = 'inline-block';
        buttonResume.style.display = 'none';

     }

  }




  this.Init();



} // Screen
