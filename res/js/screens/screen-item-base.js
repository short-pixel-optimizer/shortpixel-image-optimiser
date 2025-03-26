'use strict';

class ShortPixelScreenItemBase extends ShortPixelScreenBase {

	type; // media / custom
	currentMessage = '';

	constructor(MainScreen, processor) {
		super(MainScreen, processor);
	}

	Init() {
		super.Init();

		window.addEventListener('shortpixel.' + this.type + '.resumeprocessing', this.processor.ResumeProcess.bind(this.processor));
		window.addEventListener('shortpixel.RenderItemView', this.RenderItemView.bind(this));

	}

	/* ResultItem : Object of result output coming from QueueItem result() function . Mostly passed via AjaxController Json output.
	*/
	HandleImage(resultItem, type) {

		if (type != this.type)  // We don't eat that here.
		{
			return false;
		}
		// This is final, not more messing with this. In results (multiple) defined one level higher than result object, if single, it's in result.
		var item_id = resultItem.item_id;
		var message = resultItem.message;

		
		// This is the reporting element ( all the data, via getItemView? )
		var element = this.GetElement(resultItem, 'data');
		var apiName = (typeof resultItem.apiName !== 'undefined') ? resultItem.apiName : 'optimize'; 

		if (typeof message !== 'undefined') {
			var isError = false;
			if (resultItem.is_error == true)
				isError = true;
			this.UpdateMessage(resultItem, message, isError);
		}
		if (element !== null && apiName !== 'ai')  {
			element.innerHTML = '';
			//  var event = new CustomEvent('shortpixel.loadItemView', {detail: {'type' : type, 'id': result.id }}); // send for new item view.
			var fileStatus = this.processor.fStatus[resultItem.fileStatus];

			if (fileStatus == 'FILE_SUCCESS' || fileStatus == 'FILE_RESTORED' || resultItem.is_done == true) {
				this.processor.LoadItemView({ id: item_id, type: type });
			}
			else if (fileStatus == 'FILE_PENDING') {
				element.style.display = 'none';
			}
		}

		if ('ai' === apiName && typeof resultItem.retrievedText !== 'undefined')
		{
			// Possible alt inputs across screens
			 var inputs = [
				'attachment_alt',  //edit-media 
				'attachment-details-alt-text', // media library upload screen / image select
				'attachment-details-two-column-alt-text',
			
			 ];

			 for (var i = 0; i < inputs.length; i++)
			 {
				var altInput = document.getElementById(inputs[i]); 
				if (altInput !== null)
				{
					if (typeof altInput.value !== 'undefined')
					{
						altInput.value = resultItem.retrievedText; 	
					}
					else
					{
						altInput.innerText = resultItem.retrievedText; 	
					}
					
				}
					
			 }
		}

		return false;
	}

	// @todo here also update le message. 
	UpdateMessage(resultItem, message, isError) {

		if (typeof resultItem !== 'object')
		{	
			// Not all interface get passed resultItem. Adapt.
			 resultItem = {
				'id': resultItem, 
			 }
			 console.error('updatemessge ref wrong');
		}

		var element = this.GetElement(resultItem, 'message');
		//var element = document.getElementById('sp-message-' + id);
		if (typeof isError === 'undefined')
			isError = false;

		this.currentMessage = message;

		if (element !== null) {
			if (element.classList.contains('error'))
				element.classList.remove('error');

			element.innerHTML = message;

			if (isError)
				element.classList.add('error');
		}
		else {
			this.processor.Debug('Update Message Column not found - ' + item_id);
		}
	}

	/**
	 * 
	 * @param {mixed} responseItem 
	 * @param {string} dataType  [message|data]
	 */
	GetElement(resultItem, dataType)
	{
		 var id = resultItem.item_id; 
		 var apiName = (typeof resultItem.apiName !== 'undefined') ? resultItem.apiName : 'optimize'; 
		 var createIfMissing = false; 

		 if (apiName == 'ai')
		 {
			var elementName = 'shortpixel-ai-messagebox-' + id; 
		 }	
		 if (apiName == 'optimize')
		 {
			 if ('message' == dataType)
			 {
				 var elementName = 'shortpixel-message-' + id; 
				 createIfMissing = true; 
			 }
			 else
			 {
				var elementName = 'shortpixel-data-' + id; 
				
			 }
		 }
			
		 var element = document.getElementById(elementName);
		 if (element === null)
		 {
			  if (false === createIfMissing)
			  {
				 console.log(elementName + ' not found - false on createmissing');
				 return null; 
			  }

			  var parent = document.getElementById('shortpixel-data-' + id);
			  if (parent !== null) {
				  var element = document.createElement('div');
				  element.classList.add('message');
				  element.setAttribute('id', 'shortpixel-message-' + id);
				  parent.parentNode.insertBefore(element, parent.nextSibling);
			  }

		 }

		 return element; 

	}

	// Show a message that an action has started.
	SetMessageProcessing(id, apiName) {
		var message = this.strings.startAction;

		var loading = document.createElement('img');
		loading.width = 20;
		loading.height = 20;
		loading.src = this.processor.GetPluginUrl() + '/res/img/bulk/loading-hourglass.svg';

		message += loading.outerHTML;
		
		if (typeof apiName === 'undefined')
		{
			var apiName = 'optimize'; 
		}

		var item = {
			item_id: id, 
			apiName: apiName,
		};
		this.UpdateMessage(item, message);
	}

	UpdateStats(stats, type) {
		// for now, since we process both, only update the totals in tooltip.
		if (type !== 'total')
			return;

		var waiting = stats.in_queue + stats.in_process;
		this.processor.tooltip.RefreshStats(waiting);
	}

	GeneralResponses(responses) {
		var self = this;

		if (responses.length == 0)  // no responses.
			return;

		var shownId = []; // prevent the same ID from creating multiple tooltips. There will be punishment for this.

		responses.forEach(function (element, index) {

			if (element.id) {
				if (shownId.indexOf(element.id) > -1) {
					return; // skip
				}
				else {
					shownId.push(element.id);
				}
			}

			var message = element.message;
			if (element.filename)
				message += ' - ' + element.filename;

			self.processor.tooltip.AddNotice(message);
			if (self.processor.rStatus[element.code] == 'RESPONSE_ERROR') {

				if (element.id) {
					var message = self.currentMessage;
					self.UpdateMessage(element.id, message + '<br>' + element.message);
					self.currentMessage = message; // don't overwrite with this, to prevent echo.
				}
				else {
					var errorBox = document.getElementById('shortpixel-errorbox');
					if (errorBox) {
						var error = document.createElement('div');
						error.classList.add('error');
						error.innerHTML = element.message;
						errorBox.append(error);
					}
				}
			}
		});

	}

	// HandleItemError is handling from results / result, not ResponseController. Check if it has negative effects it's kinda off now.
	HandleItemError(result) {
		if (result.message && result.item_id) {
			this.UpdateMessage(result, result.message, true);
		}

		if (typeof result.item_id !== 'undefined') {
			this.processor.LoadItemView({ id: result.item_id, type: 'media' });
		}
	}

	RestoreItem(id) {
		var data = {};
		data.id = id;
		data.type = this.type;
		data.screen_action = 'restoreItem';
		// AjaxRequest should return result, which will go through Handleresponse, then LoaditemView.
		this.SetMessageProcessing(id);
		this.processor.AjaxRequest(data);
	}

	CancelOptimizeItem(id) {
		var data = {};
		data.id = id;
		data.type = this.type;
		data.screen_action = 'cancelOptimize';
		// AjaxRequest should return result, which will go through Handleresponse, then LoaditemView.

		this.processor.AjaxRequest(data);
	}

	ReOptimize(id, compression, action) {
		var data = {
			id: id,
			compressionType: compression,
			type: this.type,
			screen_action: 'reOptimizeItem'
		};

		if (typeof action !== 'undefined') {
			data.actionType = action;
		}

		if (!this.processor.CheckActive())
			data.callback = 'shortpixel.' + this.type + '.resumeprocessing';

		this.SetMessageProcessing(id);
		this.processor.AjaxRequest(data);
	}

	RequestAlt(id) {
		var data = {
			id: id,
			type: this.type,
			'screen_action': 'ai/requestalt',
		}

		if (!this.processor.CheckActive())
			data.callback = 'shortpixel.' + this.type + '.resumeprocessing';

		this.SetMessageProcessing(id, 'ai');
		this.processor.AjaxRequest(data);


	}

	Optimize(id, force) {
		var data = {
			id: id,
			type: this.type,
			screen_action: 'optimizeItem'
		}

		if (typeof force !== 'undefined' && true == force) {
			data.flags = 'force';
		}

		if (!this.processor.CheckActive())
			data.callback = 'shortpixel.' + this.type + '.resumeprocessing';

		this.SetMessageProcessing(id);
		this.processor.AjaxRequest(data);
	}

	MarkCompleted(id) {
		var data = {};
		data.id = id;
		data.type = this.type;
		data.screen_action = 'markCompleted';

		this.processor.AjaxRequest(data);
	}
	UnMarkCompleted(id) {
		var data = {};
		data.id = id;
		data.type = this.type;
		data.screen_action = 'unMarkCompleted';

		this.processor.AjaxRequest(data);
	}

} // class
