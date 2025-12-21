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

		var isError = false;
		if (resultItem.is_error == true)
			isError = true;

		if (typeof message !== 'undefined' && apiName !== 'ai') {

			this.UpdateMessage(resultItem, message, isError);
		}
		else if ('ai' == apiName && null !== element)
		{
			this.UpdateMessage(resultItem, message, isError);
		}

		if (element !== null && apiName !== 'ai')  {
			element.innerHTML = '';
			//  var event = new CustomEvent('shortpixel.loadItemView', {detail: {'type' : type, 'id': result.id }}); // send for new item view.
			var fileStatus = this.processor.fStatus[resultItem.fileStatus];

			if (fileStatus == 'FILE_DONE' || fileStatus == 'FILE_RESTORED' || resultItem.is_done == true) {
				this.processor.LoadItemView({ id: item_id, type: type });
			}
			else if (fileStatus == 'FILE_PENDING') {
				element.style.display = 'none';
			}
		}

		// Not optimal
		if ('ai' === apiName && typeof resultItem.aiData !== 'undefined')
		{
			if (null !== element)
			{
				var fileStatus = this.processor.fStatus[resultItem.fileStatus];
				if (fileStatus == 'FILE_DONE' || true == resultItem.is_done)
				{
					this.processor.LoadItemView({ id: item_id, type: type });

				}
			}
			 this.FetchAltView(resultItem.aiData, item_id);
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
			this.processor.Debug('Update Message Column not found - ' + resultItem.id);
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
			// Edit media view 
			var elementName = 'shortpixel-ai-messagebox-' + id; 
			var element = document.getElementById(elementName);

			if (null == element) // List-view
			{
				var elementName = 'shortpixel-message-' + id;  // see if this works better
				createIfMissing = true; 
			}

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

		if (typeof apiName === 'undefined')
			{
				var apiName = 'optimize'; 
			}

		if (apiName == 'ai')
		{
			var message = this.strings.startActionAI;
		}	
		else {
			var message = this.strings.startAction;
			var loading = document.createElement('img');
			loading.width = 20;
			loading.height = 20;
			loading.src = this.processor.GetPluginUrl() + '/res/img/bulk/loading-hourglass.svg';
	
			message += loading.outerHTML;
	
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

		if (typeof result.item_id !== 'undefined' && result.apiName !== 'ai') {
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

	UndoAlt(id, action_type)
	{
		var data = {
			id: id,
			type: this.type,
			'screen_action': 'ai/undoAlt',
			'action_type' : action_type, 
			'callback': 'shortpixel.HandleUndoAlt',
		}

		window.addEventListener('shortpixel.HandleUndoAlt', function (event) {
			var data = event.detail.media;
			var original = data.current; 
	
			if ('redo' == action_type)
			{
				if (!this.processor.CheckActive())
				{
					let ev = new Event('shortpixel.' + this.type + '.resumeprocessing');
					window.dispatchEvent(ev);

				}
			}
			this.FetchAltView(original,id);

		}.bind(this), {once: true});

	/*	if (!this.processor.CheckActive())
			data.callback = 'shortpixel.' + this.type + '.resumeprocessing'; */

		//this.SetMessageProcessing(id, 'ai');
		this.processor.AjaxRequest(data);
	}

	Optimize(id, force, compressionType) {
		var data = {
			id: id,
			type: this.type,
			screen_action: 'optimizeItem'
		}

		if (typeof force !== 'undefined' && true == force) {
			data.flags = 'force';
		}

		if (typeof compressionType !== 'undefined')
		{
			data.compressionType = compressionType; 
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

	FetchAltView()
	{
		 console.error('not implemented for this view!');
	}
	
	AttachAiInterface()
	{
		 console.error('not implemented for this view!');
	}

} // class
