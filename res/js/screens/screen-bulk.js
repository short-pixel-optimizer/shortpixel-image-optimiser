'use strict';

var ShortPixelScreen = function (MainScreen, processor)
{

  this.isCustom = true;
  this.isMedia = true;
  this.processor = processor;

  this.panels = [];
  this.currentPanel = 'dashboard';

  this.debugCounter = 0;

	this.averageOptimization = 0;
	this.numOptimizations = 0;

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
			window.addEventListener('shortpixel.reloadscreen', this.ReloadScreen.bind(this));
      /*window.addEventListener('shortpixel.process.stop', function (Event)
      {
        Event.preventDefault();
        this.processor.StopProcess.bind(this.processor)
      }.bind(this) ); */

      var processData = ShortPixelProcessorData.startData;
      var initMedia = processData.media.stats;
      var initCustom = processData.custom.stats;
      var initTotal = processData.total.stats;
      var isPreparing = false;
      var isRunning = false;
      var isFinished = false;

      if (initMedia.is_preparing == true || initCustom.is_preparing == true )
        isPreparing = true;
      else if (initMedia.is_running == true || initCustom.is_running == true )
        isRunning = true;
      else if ( (initMedia.is_finished == true && initMedia.done > 0)  || (initCustom.is_finished == true && initCustom.done > 0) )
        isFinished = true;

        this.UpdateStats(initMedia, 'media'); // write UI.
        this.UpdateStats(initCustom, 'custom');
        this.UpdateStats(initTotal, 'total');
        this.CheckPanelData();

      if (isPreparing)
      {
        this.SwitchPanel('selection');
      }
      else if (isRunning)
      {
        this.SwitchPanel('process');
        this.processor.PauseProcess(); // when loading, default start paused before resume.
      }
      else if (isFinished)
      {
         this.processor.StopProcess({ waiting: true });

         if (initMedia.done > 0 || initCustom.done > 0)
         {

           this.SwitchPanel('finished');
         }
         else
         {
           this.SwitchPanel('dashboard');
         }
         //this.SwitchPanel('process');  // needs to run a process and get back stats another try.
      }
      else if (initMedia.in_queue > 0 || initCustom.in_queue > 0)
      {
        this.SwitchPanel('summary');
      }
      else
      {
         this.processor.StopProcess({ waiting: true }); // don't go peeking in the queue. // this doesn't work since its' before the init Worker.
         this.SwitchPanel('dashboard');
      }

			if (this.processor.isManualPaused)
			{
      		var event = new CustomEvent('shortpixel.processor.paused', { detail : {paused: 	this.processor.isManualPaused }});
			}

			// This var is defined in admin_scripts, localize.
			if ( typeof shortPixelScreen.panel !== 'undefined')
			{
				 this.SwitchPanel(shortPixelScreen.panel);
			}
console.log("Screen Init Done", initMedia, initCustom);

  }
  this.LoadPanels = function()
  {
      var elements = document.querySelectorAll('section.panel');
      var self = this;
      elements.forEach(function (panel, index)
      {
          var panelName  = panel.getAttribute('data-panel');
          self.panels[panelName] = panel;
      });

  }
  this.LoadActions = function()
  {
      var actions = document.querySelectorAll('[data-action]');
      var self = this;

      actions.forEach(function (action, index)
      {
				  var eventName = (action.getAttribute('data-event')) ? action.getAttribute('data-event') : 'click';

					action.addEventListener(eventName, self.DoActionEvent.bind(self));
					if (action.children.length > 0)
					{
						 for(var i = 0; i < action.children.length; i++)
						 {
							  action.children[i].addEventListener(eventName, self.DoActionEvent.bind(self));
						 }
					}



      });
  },
	this.DoActionEvent = function(event)
	{
		var element = event.target;
		var actionName = element.getAttribute('data-action');
		var isPanelAction = (actionName == 'open-panel');

		 console.log('Do Action ' + actionName);

		if (isPanelAction)
		{
			 var doPanel = element.getAttribute('data-panel');
			 this.SwitchPanel(doPanel);
		}
		else
		{
				if (typeof this[actionName] == 'function')
				{
						this[actionName].call(this,event);
				}
		}

	},
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

    // This happens when out of quota.
    if (loader == null)
      return;

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
      for (var panelName in this.panels)
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
  this.CreateBulk = function()
  {
     console.log('Start Bulk');
     var data = {screen_action: 'createBulk', callback: 'shortpixel.PrepareBulk'}; //

     data.mediaActive = (document.getElementById('media_checkbox').checked) ? true : false;
     data.customActive = (document.getElementById('custom_checkbox').checked) ? true : false;
     data.webpActive = (document.getElementById('webp_checkbox').checked) ? true : false;
     data.avifActive = (document.getElementById('avif_checkbox').checked) ? true : false;

		 if (typeof (document.getElementById('thumbnails_checkbox')) !== 'undefined')
		 		data.thumbsActive = (document.getElementById('thumbnails_checkbox').checked) ? true : false;


     //this.SwitchPanel('selection');
     this.UpdatePanelStatus('loading', 'selection');

     // Prepare should happen after selecting what the optimize.
     window.addEventListener('shortpixel.PrepareBulk', this.PrepareBulk.bind(this), {'once': true} );
     this.processor.AjaxRequest(data);
  }
  this.PrepareBulk = function(event)
  {
      //Remove pause
      if (typeof event == 'object')
        event.preventDefault(); // stop handler in checkResponse.


      this.processor.SetInterval(200); // do this faster.
      // Show stats
      if (! this.processor.CheckActive())
      {
         this.processor.ResumeProcess();
        //this.processor.isManualPaused = false; // force run
      }
      this.processor.RunProcess();
      return false;

      // Run process.run process from now for prepare ( until prepare done? )
  }
  this.QueueStatus = function(qStatus, data)
  {
      if (qStatus == 'PREPARING_DONE' || qStatus == 'PREPARING_RECOUNT')
      {
          console.log('Queue status: preparing done');
          this.UpdatePanelStatus('loaded', 'selection');
          this.SwitchPanel('summary');
          this.processor.SetInterval(-1); // back to default.

      }
      if (qStatus == 'QUEUE_EMPTY')
      {
          if (data.total.stats.total > 0)
          {
            this.SwitchPanel('finished'); // if something actually was done.
            this.processor.StopProcess();
          }
          else
          {
              this.SwitchPanel('dashboard'); // seems we are just at the begin.
              this.processor.StopProcess();

          } // empty queue, no items, start.
      }

  }
  this.HandleImage = function(resultItem, type)
  {
      console.log('HandleImage', resultItem, type);

      var result = resultItem.result;
      if ( this.processor.fStatus[resultItem.fileStatus] == 'FILE_DONE')
      {
          this.UpdateData('result', result);

					var originalImage = document.querySelector('.image-source img');
					var optimizedImage =  document.querySelector('.image-result img');

					// reset database to avoid mismatches.
					originalImage.src = originalImage.dataset.placeholder;
					optimizedImage.src =  optimizedImage.dataset.placeholder;

          if (result.original)
          {
                originalImage.src  = result.original;
          }
          if (result.optimized)
          {
                optimizedImage.src  = result.optimized;
          }

          if ( (result.orginal || result.optimized) && document.querySelector('.image-preview').classList.contains('hidden'))
          {
            document.querySelector('.image-preview').classList.remove('hidden');
          }

          if (result.improvements.totalpercentage)
          {
							// Opt-Circle-Image is average of the file itself.
              var circle = document.querySelector('.opt-circle-image');

              var total_circle = 289.027;
              if(result.improvements.totalpercentage >0 ) {
                  total_circle = Math.round(total_circle-(total_circle*result.improvements.totalpercentage/100));
              }

              for( var i = 0; i < circle.children.length; i++)
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

							this.AddAverageOptimization(result.improvements.totalpercentage);
          }
      }
  }

	this.AddAverageOptimization = function(num)
	{
			this.numOptimizations++;
			this.averageOptimization += num;

			var total = this.averageOptimization / this.numOptimizations;

			// There are circles on process and finished.
			var circles = document.querySelectorAll('.opt-circle-average');

			circles.forEach(function (circle)
			{
				var total_circle = 289.027;
				if( total  >0 ) {
						total_circle = Math.round(total_circle-(total_circle * total /100));
				}

				for(var i = 0; i < circle.children.length; i++)
				{
					 var child = circle.children[i];
					 if (child.classList.contains('path'))
					 {
							child.style.strokeDashoffset = total_circle + 'px';
					 }
					 else if (child.classList.contains('text'))
					 {
							child.textContent = Math.round(total) + '%';
					 }
				}
			}); // circles;
	}
  this.DoSelection = function() // action to update response.
  {
      // @todo Check the future of this function, since checking this is now createBulk.
      var data = {screen_action: 'applyBulkSelection'}; //
      data.callback = 'shortpixel.applySelectionDone';

      data.mediaActive = (document.getElementById('media_checkbox').checked) ? true : false;
      data.customActive = (document.getElementById('custom_checkbox').checked) ? true : false;
      data.webpActive = (document.getElementById('webp_checkbox').checked) ? true : false;
      data.avifActive = (document.getElementById('avif_checkbox').checked) ? true : false;

      window.addEventListener('shortpixel.applySelectionDone', function (e) { this.SwitchPanel('summary'); }.bind(this) , {'once': true} );
      this.processor.AjaxRequest(data);

  }
/*  this.UpdateMessage = function(id, message)
  {
     console.log('UpdateMessage', id, message);

  } */
  this.UpdateStats = function(stats, type)
  {
      this.UpdateData('stats', stats, type);

  }
  // dataName refers to domain of data i.e. stats, result. Those are mentioned in UI with data-stats-media="total" or data-result
  this.UpdateData = function(dataName, data, type)
  {
      console.log('updating Data :',  dataName, data, type);
			self.debugCounter++;

			if (self.debugCounter > 20)
			{
				console.error('loop detected, pausing');
				this.PauseBulk();
			}

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
                    if (presentation == 'append')
                    {
                      element.innerHTML = element.innerHTML + value;
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
  }
	/** HandleError is used for both general error and result errors. The latter have a result object embedded and more information */
  this.HandleError = function(result, type)
  {
    console.error(result);

		var fatal = false;
		var cssClass = '';
		var message = '';

		if (typeof result.result !== 'undefined') // item error
		{
			 if (result.result)
			 {
			 		var item = result.result;
			 		var filename = (typeof item.filename !== 'undefined') ? item.filename : false;
			 		var message = item.message;
			 		var fatal = (item.is_done == true) ? true : false;
			 		if (filename)
			 		{
				  		message += ' (' + filename + ') ';
			 		}
			 }

			 var error = this.processor.aStatusError[result.error];

			 if (error == 'NOQUOTA')
			 {
						 this.ToggleOverQuotaNotice(true);
			 }

		}
		else // unknown.
		{
    	var message = result.message + '(' + result.item_id + ')';
			console.error('Error without item - ' + message);

		}

		if (fatal)
			 cssClass += ' fatal';
		var data = {message: '<div class="'+ cssClass + '">' + message + '</div>'};
		this.UpdateData('error', data, type);

  }

	this.ToggleErrorBox = function(event)
	{

		 var type = event.target.getAttribute('data-errorbox');
		 var checked = event.target.checked;
		 var inputName = event.target.name;

			// There are multiple errorBoxes
			var errorBoxes = document.querySelectorAll('.errorbox.' + type);

			errorBoxes.forEach(function(errorbox)
			{
				if (checked === true)
				{
				 	errorbox.style.opacity = 1;
					errorbox.style.display = 'block';
				}
				else
				{
					errorbox.opacity = 0;
					errorbox.style.display = 'none';
				}
			}); //foreach

			var inputs = document.querySelectorAll('input[name="' + inputName + '"]');
			inputs.forEach(function (inputBox) {
					if (inputBox.getAttribute('data-errorbox') != type)
					{
						 return;
					}
					if (checked != inputBox.checked)
					{
						 // sync other boxes with same name and type
						 inputBox.checked = checked;
					}
			});
	}

  this.StartBulk = function() // Open panel action
  {
      console.log('Starting to Bulk!');
      var data = {screen_action: 'startBulk', callback: 'shortpixel.bulk.started'}; //

      // Prepare should happen after selecting what the optimize.
      //window.addEventListener('shortpixel.prepareBulk', this.PrepareBulk.bind(this), {'once': true} );
      this.processor.AjaxRequest(data);

      // process stops after preparing.
			// ResumeProcess, not RunProcess because that hits the pauseToggles.
			window.addEventListener('shortpixel.bulk.started', function() {
					this.processor.ResumeProcess();
				}.bind(this), {'once': true} );
      //this.processor.ResumeProcess();

      this.SwitchPanel('process');

  }
  this.PauseBulk = function (event)
  {
     this.processor.tooltip.ToggleProcessing(event);
  }

  this.ResumeBulk = function(event)
  {
      this.processor.ResumeProcess();
  }
  this.StopBulk = function(event)
  {
      if (confirm(shortPixelScreen.endBulk))
         this.FinishBulk(event);
  }
  this.FinishBulk = function(event)
  {
		// Screen needs reloading after doing all to reset all / load the logs.
    var data = {screen_action: 'finishBulk', callback: 'shortpixel.reloadscreen'}; //
    this.processor.AjaxRequest(data);
  }
	this.ReloadScreen = function(event)
	{
		 	//window.trigger('shortpixel.process.stop');
			var url = shortPixelScreen.reloadURL;
			location.href = url;

//			this.SwitchPanel('dashboard');

	}

  this.TogglePauseNotice = function(event)
  {
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

				// in case this is overquota situation, on unpause, recheck situation, hide the thing.
				this.ToggleOverQuotaNotice(false);
     }

  }

  // Everything that needs to happen when going overQuota.
	this.ToggleOverQuotaNotice = function(toggle)
	{
		 var overQ = document.getElementById('processorOverQuota');

		 if (toggle)
		 {
				overQ.style.display = 'block';
		 }
		 else
		 {
			   overQ.style.display = 'none';
		 }

	}

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
      // @todo Might not be relevant in new paging order.
     console.log('Panel Switched', event.detail);
     var panelLoad = event.detail.panelLoad;
     var panelUnload = event.detail.panelUnload;

     /*if (panelUnload == 'selection')
     {
        this.UpdatePanelStatus('loading', 'selection');
     } */
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
            console.error('Control named ' + control + ' on ' + element.innerHTML + ' didn\'t find reference value element ');
            return;
          }

          var value = parseInt(checker.innerHTML);
          if ( hasCompareControl)
          {
            var compareControl = document.querySelector('[' + element.getAttribute('data-control-check') + ']');
            var compareValue = parseInt(compareControl.innerHTML);

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
          else if ( element.hasAttribute('data-check-presentation'))
          {
              var presentation = element.getAttribute('data-check-presentation');
              if (presentation == 'disable')
              {
                  if (check)
                    element.disabled = false;
                  else
                    element.disabled = true;
              }
          }


      });

  }

  this.ToggleButton = function(event)
  {
      var checkbox = event.target;
      var target = document.getElementById(checkbox.getAttribute('data-target'));
      if (checkbox.checked)
      {
        target.disabled = false;
        target.classList.remove('disabled');
      }
      else
      {
         target.disabled = true;
         target.classList.add('disabled');
      }
  }

  this.BulkRestoreAll = function (event)
  {
    console.log('Start Restore All');
    var data = {screen_action: 'startRestoreAll', callback: 'shortpixel.startRestoreAll'}; //

    this.SwitchPanel('selection');
    this.UpdatePanelStatus('loading', 'selection');

    // Prepare should happen after selecting what the optimize.
    window.addEventListener('shortpixel.startRestoreAll', this.PrepareBulk.bind(this), {'once': true} );
    window.addEventListener('shortpixel.bulk.onSwitchPanel', this.StartBulk.bind(this), {'once': true});
    this.processor.AjaxRequest(data);
  }

  this.BulkMigrateAll = function (event)
  {
    console.log('Start Migrate All');
    var data = {screen_action: 'startMigrateAll', callback: 'shortpixel.startMigrateAll'}; //

    this.SwitchPanel('selection');
    this.UpdatePanelStatus('loading', 'selection');
  	//this.SwitchPanel('process');

    // Prepare should happen after selecting what the optimize.
    window.addEventListener('shortpixel.startMigrateAll', this.PrepareBulk.bind(this), {'once': true} );
    window.addEventListener('shortpixel.bulk.onSwitchPanel', this.StartBulk.bind(this), {'once': true});
    this.processor.AjaxRequest(data);
  }

	// Opening of Log files on the dashboard
	this.OpenLog = function(event)
	{
		 event.preventDefault();
		 console.log('Borks', event);
    var data = {screen_action: 'loadLogFile', callback: 'shortpixel.showLogModal'};
		data['loadFile'] = event.target.getAttribute('data-file');
		data['type'] = 'log'; // for the answer.

		var modalData = this.GetModal();
		var modal = modalData[0];
		var title = modalData[1];
		var content = modalData[2];
		var wrapper = modalData[3];

		modal.classList.remove('shortpixel-hide');
		title.textContent = '';

		if (wrapper !== null)
			wrapper.innerHTML = '';
		else
			content.innerHTML = ''; //empty

		if (! content.classList.contains('sptw-modal-spinner'))
			content.classList.add('sptw-modal-spinner');

    window.addEventListener('shortpixel.showLogModal', this.ShowLogModal.bind(this), {'once': true});
    this.processor.AjaxRequest(data);
	}

	this.GetModal = function()
	{
			var modal = document.getElementById('LogModal');
			var wrapper = null;
			for (var i = 0; i < modal.children.length; i++)
			{
				 if (modal.children[i].classList.contains('title'))
				 {
				 	var title = modal.children[i];
				 }
				 else if (modal.children[i].classList.contains('content'))
				 {
					 if (modal.children[i].querySelector('.table-wrapper'))
					 {
						 	wrapper = modal.children[i].querySelector('.table-wrapper');
					 }
				   var content = modal.children[i];

				}
			}

			return [modal, title, content, wrapper];
	}

	this.ShowLogModal = function(event)
	{
			var log = event.detail.log;

			if (log.is_error == true)
			{
				console.error(log);
				this.CloseModal();
			}

			var shade = document.getElementById('LogModal-Shade');
			shade.style.display = 'block';
			shade.addEventListener('click', this.CloseModal.bind(this), {'once': true});
			if (shade)
			{
				// var node = shade.cloneNode(true);
				// node.style.display = 'block';
				 //node.id = 'LogModal-Shade-Active';
				 //document.body.appendChild (node);
			}

			var modalData = this.GetModal();
			var modal = modalData[0];
			var title = modalData[1];
			var content = modalData[2];
			var wrapper = modalData[3];

			title.textContent = log.title;

			var logType = log.logType;

			for (var i = 0; i < log.results.length; i++)
			{
				  if (i === 0)
						var html = '<div class="heading">';
					else
						var html = '<div>';

					if (i == 0)
					{
						for (var j = 0; j < log.results[i].length; j++ )
						{
							html += '<span>' + log.results[i][j] + '</span>';
						}
					}
					else if (log.results[i].length >= 3)
					{
						html += '<span>' + log.results[i][0] + '</span>';
						if (logType == 'custom')
							html += '<span>' + log.results[i][1] + '</span>';
						else
							html += '<span><a href="' + log.results[i][2] + '" target="_blank">' + log.results[i][1] + '</a></span>';
						html += '<span>' + log.results[i][3] + '</span>';
					}

					html += '</div>';

					if (wrapper !== null)
						wrapper.innerHTML += html;
					else
						content.innerHTML += html;

					content.classList.remove('sptw-modal-spinner');
			}

	}

	this.CloseModal = function(event)
	{
		 event.preventDefault();
 		 var modal = document.getElementById('LogModal');
		 modal.classList.add('shortpixel-hide');

		 var shade = document.getElementById('LogModal-Shade');
		 shade.style.display = 'none';
	}

  //this.CheckSelectionScreen()  = function()

  this.Init();

} // Screen
