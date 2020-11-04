
//Module pattern
window.ShortPixelProcessor =
{
  //  spp: {},
    isActive: false,
    interval: 2000,
    screen: null,
    tooltip: null,
    isBulkPage: false,
    localSecret: null,
    remoteSecret: null,
    worker: null,
    timer: null,
    nonce: [],
    qStatus: {
       4:  'QUEUE_WAITING',
       10: 'QUEUE_EMPTY',
    },

    Load: function()
    {
        this.isBulkPage = ShortPixelProcessorData.isBulkPage;
        this.localSecret = localStorage.bulkSecret;
        this.remoteSecret = ShortPixelProcessorData.bulkSecret;

        this.nonce['process'] = ShortPixelProcessorData.nonce_process;
        this.nonce['exit'] = ShortPixelProcessorData.nonce_process;
        this.nonce['itemview'] = ShortPixelProcessorData.nonce_itemview;

        //console.log(ShortPixelProcessorData);

        if (this.remoteSecret == false || this.isBulkPage) // if remoteSecret is false, we are the first process. Take it.
        {
           this.localSecret = this.remoteSecret = Math.random().toString(36).substring(7);
           localStorage.bulkSecret = this.localSecret;
           this.isActive = true;
        }
        else if (this.remoteSecret == this.localSecret) // There is a secret, we are the processor.
        {
           this.isActive = true;
        }
        else
        {
           console.debug('Processor not active - ' + this.remoteSecret + ' - ' + this.localSecret);
        }

        if (this.isActive)
        {
          //  console.debug('loading worker');
            this.LoadWorker();
            this.RunProcess();
        }

        if (typeof ShortPixelScreen == 'undefined')
        {
           console.error('Missing Screen for feedback!');

        }
        else
          this.screen = new ShortPixelScreen({});

        this.tooltip = new ShortPixelToolTip();


      //  console.log(this);
    },
    LoadWorker: function()
    {
        if (window.Worker)
        {
            var ajaxURL = ShortPixel.AJAX_URL;
            var nonce = '';

            this.worker = new Worker(ShortPixelProcessorData.workerURL);
            this.worker.postMessage({'action': 'init', 'data' : [ajaxURL, this.localSecret]});
            this.worker.onmessage = this.CheckResponse.bind(this);

            window.addEventListener('beforeunload', this.ShutDownWorker.bind(this));
            window.addEventListener('shortpixel.loadItemView', this.LoadItemView.bind(this));
        }
    },
    ShutDownWorker: function()
    {
        this.worker.postMessage({'action' : 'shutdown', 'nonce': this.nonce['exit'] });
        this.worker.terminate();

        window.removeEventListener('beforeunload', this.ShutDownWorker.bind(this));
        window.removeEventListener('shortpixel.loadItemView', this.LoadItemView.bind(this));
    },
    Process: function()
    {
        //$(document).on timeout - check function.
        //console.log(this);
        this.tooltip.DoingProcess();
        this.worker.postMessage({action: 'process', 'nonce' : this.nonce['process']});

    },
    RunProcess: function()
    {
        if (this.timer)
          window.clearTimeout(this.timer);

        this.timer = window.setTimeout(this.Process.bind(this), this.interval);
    },
    CheckResponse: function(message)
    {
      console.log('checkResponse');

      //console.log(e.status);
      var data = message.data;

      if (data.status == false)
      {
          console.log('Network Error ' + data.message);
      }
      else if (data.status == true && data.response) // data status is from shortpixel worker, not the response object
      {
          if (! this.screen)
          {
             console.error('Missing screen - can\'t report results');
             return false;
          }

          var response = data.response;
          if ( response.callback)
          {
              var event = new CustomEvent(response.callback, {detail: response});
              window.dispatchEvent(event);
          }

           // Check the screen if we are custom or media ( or bulk ) . Check the responses for each of those.
           if (this.screen.isCustom && typeof response.custom == 'object' && response.custom !== null)
           {
               this.HandleResponse(response.custom, 'custom');
           }
           if (this.screen.isMedia && typeof response.media == 'object' && response.media !== null)
           {
                this.HandleResponse(response.media, 'media');
           }



      }

    },
    HandleResponse: function(response, type)
    {
        if (response.has_error == true)
        {
           this.screen.handleError(response.message);
        }

        // Perhaps if optimization, the new stats and actions should be generated server side?

         // If there are items, give them to the screen for display of optimization, waiting status etc.
         if (response.results !== null)
         {
             for (i = 0; i < response.results.length; i++)
             {
                var imageResult = response.results[i];
                this.screen.handleImage(response.results[i], type);
             }
         }


         // Queue status?
         if (response.stats)
         {
            this.tooltip.RefreshStats(response.stats);
         }

         if (response.result == null && response.results == null)
         {
             if (this.qStatus[response.status] == 'QUEUE_WAITING')
             {
                this.RunProcess(); // run another queue with timeout
             }
             else if (this.qStatus[response.status] == 'QUEUE_EMPTY')
             {
                 console.debug('Empty Queue');
                 this.tooltip.ProcessEnd();
                 this.ShutDownWorker();
             }
         }

         // Check for errors like Queue / Key / Maintenance / etc  (is_error true, pass message to screen)


         // If all is fine, there is more in queue, enter back into queue.
    },
    LoadItemView: function(event)
    {
      var data = event.detail;
      var nonce = this.nonce['itemview'];
      this.worker.postMessage({action: 'getItemView', 'nonce' : this.nonce['itemview'], 'data': { 'id' : data.id, 'type' : data.type, 'callback' : 'shortpixel.RenderItemView'}});

    }

    //re/turn spp;
}
