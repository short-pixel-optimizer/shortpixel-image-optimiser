

var ShortPixelScreen = function (MainScreen, processor)
{

  this.isCustom = true;
  this.isMedia = true;
  this.processor = processor;

  this.panels = [];
  this.currentPanel = 'dashboard';

  this.debugCounter = 0;

  this.Init = function()
  {
    // Hook up the button and all.
      this.LoadPanels();
      this.LoadActions();

    //  console.log(ShortPixelScreenBulk);
      window.addEventListener('shortpixel.processor.paused', this.TogglePauseNotice.bind(this));
      window.addEventListener('shortpixel.processor.responseHandled', this.CheckPanelData.bind(this));
      window.addEventListener('shortpixel.bulk.onUpdatePanelStatus', this.EventPanelStatusUpdated.bind(this));
      window.addEventListener('shortpixel.bulk.onSwitchPanel', this.EventPanelSwitched.bind(this));


      var processData = ShortPixelProcessorData.startData;
      var initMedia = processData.media.stats;
      var initCustom = processData.custom.stats;
      isPreparing = false;
      isRunning = false;
      isFinished = false;

      if (initMedia.is_preparing == true || initCustom.is_preparing == true )
        isPreparing = true;
      else if (initMedia.is_running == true || initCustom.is_running == true )
        isRunning = true;
      else if (initMedia.is_finished == true || initCustom.is_finished == true )
        isFinished = true;

      if (isPreparing)
      {
        this.SwitchPanel('selection');
      }
      else if (isRunning)
      {
        //this.SwitchPanel('process');
        this.processor.PauseProcess(); // when loading, default start paused before resume.
      }
      else if (isFinished)
      {
         //this.SwitchPanel('process');  // needs to run a process and get back stats another try.
      }
      else
      {
         this.processor.StopProcess(); // don't go peeking in the queue.
         this.SwitchPanel('dashboard');
      }
console.log(initMedia, isPreparing, isRunning, isFinished);

  }
  this.LoadPanels = function()
  {
      elements = document.querySelectorAll('section.panel');
      var self = this;
      elements.forEach(function (panel, index)
      {
          var panelName  = panel.getAttribute('data-panel');
          self.panels[panelName] = panel;
      });

  }
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
  }
  this.UpdatePanelStatus = function(status, panelName)
  {
     if (typeof panelName !== 'undefined')
      var panel = this.panels[panelName];
     else
      var panel = this.panels[this.currentPanel];

      var currentStatus = panel.getAttribute('data-status');
      panel.setAttribute('data-status', status);

      var event = new CustomEvent('shortpixel.bulk.onUpdatePanelStatus', { detail : {status: status, oldStatus: currentStatus, panelName: panelName}});
      window.dispatchEvent(event);
  }
  this.ToggleLoading = function(loading)
  {
    if (typeof loading == 'undefined' || loading == true)
      var loading = true;
    else
      var loading = false;

    var loader = document.getElementById('bulk-loading');
    if (loading)
      loader.setAttribute('data-status', 'loading');
    else
      loader.setAttribute('data-status', 'not-loading');

  }
  this.SwitchPanel = function(targetName)
  {
     console.debug('Switching Panel ' + targetName);
      this.ToggleLoading(false);
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

         panel.classList.remove('active');
         panel.style.display = 'none';
      };

      var panel = this.panels[targetName];

      panel.style.display = 'block';
      // This should be the time of transition needed.
  //    panel.classList.add('active');

    // This non-delay makes the transition fade in properly.
    setTimeout(function() { panel.classList.add('active'); }, 0);

       var oldCurrentPanel = this.currentPanel; // for event
       this.currentPanel = targetName;

       if ( panel.getAttribute('data-loadPanel') !== null)
       {

           this[panel.getAttribute('data-loadPanel')].call(this);
       }

       var event = new CustomEvent('shortpixel.bulk.onSwitchPanel', { detail : {panelLoad: targetName, panelUnload: oldCurrentPanel}});
       window.dispatchEvent(event);

  }
  this.StartPrepare = function()
  {
     console.log('Start Bulk');
     var data = {screen_action: 'createBulk', callback: 'shortpixel.prepareBulk'}; //

     // Prepare should happen after selecting what the optimize.
     window.addEventListener('shortpixel.prepareBulk', this.PrepareBulk.bind(this), {'once': true} );
     this.processor.AjaxRequest(data);
  }
  this.PrepareBulk = function()
  {
      //Remove pause
      this.SwitchPanel('selection');
      this.ToggleLoading(false);
      this.processor.SetInterval(500); // do this faster.
      // Show stats
      if (this.processor.isManualPaused == true)
      {
         this.processor.ResumeProcess();
        //this.processor.isManualPaused = false; // force run
      }
      this.processor.RunProcess();

      // Run process.run process from now for prepare ( until prepare done? )
  }
  this.QueueStatus = function(qStatus, data)
  {
      if (qStatus == 'PREPARING_DONE' || qStatus == 'PREPARING_RECOUNT')
      {
          console.log('Queue status: preparing done');
          this.UpdatePanelStatus('loaded', 'selection');
          this.SwitchPanel('selection');
          this.processor.SetInterval(-1); // back to default.

          //this.SwitchPanel('selection');

      }
      if (qStatus == 'QUEUE_EMPTY')
      {
          if (data.total.stats.total > 0)
          {
            this.SwitchPanel('finished'); // if something actually was done.
          }
          else
          {
              this.SwitchPanel('dashboard');
          } // empty queue, no items, start.
    //      this.UpdatePanelStatus('queueDone', 'process');

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
      if ( this.processor.fStatus[resultItem.fileStatus] == 'FILE_DONE')
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


  }
  this.UpdateMessage = function(id, message)
  {
     console.log('UpdateMessage');

  }
  this.UpdateStats = function(stats, type)
  {
      this.UpdateData('stats', stats, type);

  }
  // dataName refers to domain of data i.e. stats, result. Those are mentioned in UI with data-stats-media="total" or data-result
  this.UpdateData = function(dataName, data, type)
  {
      console.log('updating Data :',  dataName, type);
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
                    var value = false;
                }
                else
                {
                   if (typeof data[el] !== 'undefined')
                    var value = data[el];
                   else
                    var value =  false;
                }

                if (presentation)
                {
                  if (value !== false)
                  {
                    if (presentation == 'css.width.percentage')
                      element.style.width = value + '%';
                    if (presentation == 'inputval')
                    {
                      element.value = value;
                    }
                  }
                }
                else
                {
                  if (value !== false)
                    element.textContent = value;
                }

          });
      }
  },
  this.HandleError = function(response)
  {
    console.error(response);
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
  /*this.StopBulk = function(event)
  {
      this.PauseBulk(event);
      // do something here to nuke the thing
  }, */
  this.FinishBulk = function(event)
  {
    console.log('Finishing');
    var data = {screen_action: 'finishBulk'}; //
    this.processor.AjaxRequest(data);
    this.SwitchPanel('dashboard');

  }

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

  },
  this.EventPanelStatusUpdated = function(event)
  {
     var status = event.detail.status;
     var oldStatus = event.detail.oldStatus;
     var panelName = event.detail.panelName;

     if (panelName == 'selection' && status == 'loaded')
     {
        //this.CheckSelectionScreen();
     }
     console.log('Status Updated', event.detail);
  }
  this.EventPanelSwitched = function(event)
  {
     console.log('Panel Switched', event.detail);
     var panelLoad = event.detail.panelLoad;
     var panelUnload = event.detail.panelUnload;

     if (panelUnload == 'selection')
     {
        this.UpdatePanelStatus('loading', 'selection');
     }
  }
  /* Checks number data and shows / hide elements based on that
  * data-check-visibility - will hide/show check against the defined data-control
  * data-control name must be a data-check- item at the number element - must be with value only.
  * data-check-control element will check against another number.
  * data-control must be 0 /higher than data-check-control to  get the check positive.

  */
  this.CheckPanelData = function() // function to check and hide certain areas if data is not happy.
  {
      // Element that should be hidden if referenced number is 0,NaN or elsewhat
      var panelControls = document.querySelectorAll('[data-control]');
      var self = this;

      panelControls.forEach(function (element, index)
      {

          var control = element.getAttribute('data-control');
          var hasCompareControl = element.hasAttribute('data-control-check');


          var checker = document.querySelector('[' + control + ']');

          // basic check if value > 0
          if (checker == null)
          {
            console.error('Control for ' + element.innerHTML + ' didn\'t find reference value element ');
            return;
          }

          var value = parseInt(checker.innerHTML);
          if ( hasCompareControl)
          {
            var compareControl = document.querySelector('[' + element.getAttribute('data-control-check') + ']');
            var compareValue = parseInt(compareControl.innerHTML);
            self.debugCounter++;
            console.log('hasCompareControl ', self.debugCounter);
          }
          if (isNaN(value))
          {
             var check = false;  // NaN can't play.
          }
          else if (hasCompareControl)
          {
             compareControl = document.querySelector('[' +  + ']');

             if (value > compareValue )
                var check = true;
             else
               var check = false;
          }
          else if (value <= 0)
          {
             var check = false;   // check failed.
          }
          else
          {
             var check = true;  // check succeeds
          }

          if (element.hasAttribute('data-check-visibility'))
          {
            var visibility = element.getAttribute('data-check-visibility'); // if check succeeds, show.
            if (visibility == null || visibility == 'true' || visibility == '')
               visibility = true;
            else // if check succeeds, hide.
               visibility = false;

            var hasHidden = element.classList.contains('hidden');
            if (check && hasHidden && visibility)
              element.classList.remove('hidden');
            else if (! check && ! hasHidden && visibility)
              element.classList.add('hidden');
            else if (check && ! visibility && ! hasHidden)
              element.classList.add('hidden');
            else if (! check && ! visibility && hasHidden)
              element.classList.remove('hidden');
          }


      });

  }

  //this.CheckSelectionScreen()  = function()

  this.Init();

} // Screen
