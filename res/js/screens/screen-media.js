'use strict';

// MainScreen as an option for delegate functions
class ShortPixelScreen extends ShortPixelScreenItemBase //= function (MainScreen, processor)
{
	isCustom = true;
	isMedia = true;
	type = 'media';
	altInputNames = [
		'attachment_alt',  //edit-media 
		'attachment-details-alt-text', // media library upload screen / image select
		'attachment-details-two-column-alt-text',
	
	 ];
	 ai_enabled = true; 
	 gutenCheck = []; 


	Init() {
		super.Init();
		
		let settings = spio_mediascreen_settings;
		this.settings = settings;

		this.ListenGallery();
		this.ListenGutenberg();


		if (typeof settings.hide_ai !== 'undefined')
		{
			this.ai_enabled = ! settings.hide_ai;
		}
		
		// bind DoAction, for bulk actions in Media Libbrary to event
		var actionEl = document.getElementById('doaction');
		if (actionEl !== null)
			actionEl.addEventListener('click', this.BulkActionEvent.bind(this));
	   
		// This init only in edit-media and pass the ID for safety. 
		if (document.getElementById('attachment_alt') !== null)
		{
			var postInput = document.getElementById('post_ID');
			let item_id = postInput.value; 
			this.FetchAltView(undefined, item_id);
			let imageDataEl = document.getElementById('shortpixel-data-' + item_id); 
			let editorArgs = {}; 
			if (null !== imageDataEl && imageDataEl.dataset.imagewidth)
			{
				 editorArgs.image_width= imageDataEl.dataset.imagewidth;
			}
			if (null !== imageDataEl && imageDataEl.dataset.extension)
			{
				 editorArgs.image_ext = imageDataEl.dataset.extension;
			}
			this.InitEditorActions(postInput.value, 'edit', editorArgs); 
		}
	}

	InitEditorActions(item_id, uiType, args)
	{
		let id = 'shortpixel_removebackground_button';
		var button = document.createElement('button'); 

		button.name = 'removeBackground'; 
		button.innerHTML = this.settings.remove_background_title; 
		button.type =  'button'; 
		button.classList.add('button', 'button-secondary');
		button.id = id; 
		button.dataset.item_id = item_id; 

		button.addEventListener('click', (event) => { this.OpenEditorEvent(event, 'remove') }); 
		button.dataset.opener = uiType; 
		
		var scaleButton = document.createElement('button'); 
		scaleButton.name = 'scaleBackground'; 
		scaleButton.innerHTML = this.settings.scale_title; 
		scaleButton.type = 'button'; 
		scaleButton.classList.add('button', 'button-secondary'); 
		scaleButton.id = 'shortpixel_scale_button'; 
		scaleButton.dataset.item_id = item_id; 
		scaleButton.style.marginLeft = '6px'
		
		scaleButton.addEventListener('click', (event) => { this.OpenEditorEvent(event, 'scale') }); 
		scaleButton.dataset.opener = uiType; 

		if (args.image_ext && false == (args.image_ext == 'jpg' || args.image_ext == 'png' || args.image_ext == 'jpeg'))
		{
			return; 
		}
		else if (args.image_width && parseInt(args.image_width) > parseInt(this.settings.upscale_max_width))
		{
			scaleButton.disabled = true;
			scaleButton.title = this.settings.too_big_for_scale_title; 
		}

			// @todo Probably all should pass uiType. 
		if (typeof uiType === 'undefined' || uiType === 'edit')
		{
			var parent = document.querySelector('[id^=media-head]'); 
			let par = document.createElement('p');
			let par2 = document.createElement('p');
			par.append(button);
			par2.append(scaleButton); 
			parent.append(par, par2);
		}
		else if('gallery' === uiType)
		{
			var parent = document.querySelector('.media-modal .attachment-actions')
			parent.append(button, scaleButton);
		}
		else if ('gutenberg' == uiType)
		{
			var parent = document.querySelector('.attachment-info  ');
			if (null !== parent)
			{
				button.classList.add('button-link');
				button.style.display = 'inline';
				button.style.fontSize = '12px'; // hacking in to match Gburg. 
				button.style.textDecoration = 'none'; 
				
				
				scaleButton.classList.add('button-link'); 
				scaleButton.style.display = 'inline';
				scaleButton.style.fontSize = '12px'; // hacking in to match Gburg. 
				scaleButton.style.textDecoration = 'none'; 

				if (false == scaleButton.disabled)
				{
					button.style.marginLeft = '4px';
					parent.append(scaleButton);
				}

				parent.append(button);


			}
		}
		
	}

	OpenEditorEvent(event, action_name)
	{
		let item_id = event.target.dataset.item_id; 
		var opener = event.target.dataset.opener; 

		event.preventDefault(); 
		
		let backgroundShade = document.createElement('div');
		backgroundShade.id = 'shortpixel-media-modal-shade';
		backgroundShade.classList.add('shortpixel-media-modal-shade');
		
		let modal = document.createElement('div'); 
		modal.id = 'shortpixel-media-modal'; 
		modal.classList.add('shortpixel-media-modal', 'modal');
		modal.dataset.opener = opener; 
		modal.dataset.action_name = action_name; 
		modal.dataset.item_id = item_id; 

		if (null === document.getElementById('shortpixel-media-modal-css'))
		{
			var head  = document.getElementsByTagName('head')[0];
			var link  = document.createElement('link');
			link.id   = 'shortpixel-media-modal-css';
			link.rel  = 'stylesheet';
			link.type = 'text/css';
			link.href = this.settings.modalcss;
			link.media = 'all';
			head.appendChild(link);	
		}

		// CloseEvent to close the modal + background
		var closeEvent = new CustomEvent('shortpixel-media-modal-close', { detail : 
			 {
				 modal: modal,
				 shade: backgroundShade,
				 opener: opener, 
			 }
		} ); 

		var buttonEventUnblock = new CustomEvent('shortpixel-media-modal-buttons-unblock', { detail: {toggle : 'on'}});
		var buttonEventBlock = new CustomEvent('shortpixel-media-modal-buttons-block', { detail: {toggle : 'off'}});
		

		modal.closeEvent = closeEvent;  // if works, quite dirty.
		modal.toggleButtonBlock = buttonEventBlock; 
		modal.toggleButtonUnblock = buttonEventUnblock; 

		//modal.addEventListener('shortpixel-media-modal-buttons-unblock, shortpixel-media-modal-buttons-block',
		var buttonEvent = (event) => {
			var toggle = event.detail.toggle; 
			console.log('toggle buttons');
			var buttons = modal.querySelectorAll('button[type="button"]');

			for (var i = 0; i < buttons.length; i++)
			{
				if ('on' == toggle)
				{
					buttons[i].disabled = false; 
				}
				else
				{
					buttons[i].disabled = true; 
				}
			}
		}; 
		modal.addEventListener('shortpixel-media-modal-buttons-unblock', buttonEvent);
		modal.addEventListener('shortpixel-media-modal-buttons-block', buttonEvent);

		document.addEventListener('shortpixel-media-modal-close', (event) => {
				let detail = event.detail; 
				detail.modal.remove();
				detail.shade.remove(); 

				if ('gallery' == event.detail.opener || 'gutenberg' == event.detail.opener)
				{
						var WPmodals = document.querySelectorAll('#wp-media-modal, .media-modal-backdrop'); 
						for (let i =0; i < WPmodals.length; i++)
						{
							WPmodals[i].style.display = 'unset'; 
						}
		
				}
		}, { once: true });

		backgroundShade.addEventListener('click', () => { document.dispatchEvent(closeEvent)});

		// hide underlaying popups for now. 
		if ('gallery' == opener || 'gutenberg' == opener)
		{
			var WPmodals = document.querySelectorAll('#wp-media-modal, .media-modal-backdrop'); 
			for (let i =0; i < WPmodals.length; i++)
			{
			 	WPmodals[i].style.display = 'none'; 
			}
		}

		document.body.append(backgroundShade);
		document.body.append(modal);

		window.addEventListener('shortpixel.openEditorPopup', function (event) {
			let previewData = event.detail; 

			let actionName = event.detail.action_name; 

			modal.innerHTML = previewData.popup; 
			modal.querySelector('[data-action="close"]').addEventListener('click', () => {
				document.dispatchEvent(closeEvent);
			}, { once: true });

			if (true == this.settings.popup_load_preview)
			{
				this.MediaEditorDoAction({ preview: true, 'item_id': item_id, 'modal': modal});
			}

			let previewButton = modal.querySelector('[data-action="media-get-preview"]'); 
			previewButton.addEventListener('click', () => {
				 	this.MediaEditorDoAction({ preview: true, 'item_id': item_id, 'modal' : modal, 'refresh' : true});
			});

			let saveButton = modal.querySelector('[data-action="media-save-button"]'); 
			saveButton.addEventListener('click', () =>  { 
				 this.MediaEditorDoAction({ preview: false, 'item_id': item_id, 'modal' : modal, 'refresh' : true});
			});

			let actionWrapper = modal.querySelector('.' + actionName + '.action_wrapper');
			if (null !== actionWrapper)
			{
				 actionWrapper.classList.add('active');
			}

			// Interface elements for remove backgrounnd
			let solidInput = modal.querySelectorAll('input[name="background_type"]'); 
			if (solidInput.length > 0)
			{
				let solidSelector = modal.querySelector('#solid_selector'); 
				for (let i = 0; i < solidInput.length; i++)	
				{
					solidInput[i].addEventListener('change', (event) => {
						 if (event.target.value == 'solid')
						 {
							 solidSelector.style.display = 'block'; 
							 solidSelector.style.opacity = 1; 
						 }
						 else
						 {
							solidSelector.style.display = 'none'; 
							solidSelector.style.opacity = 0; 
						 }
					}); 
				}
			}
			let bgTransInput = modal.querySelector('#bg_transparency');
			if (null !== bgTransInput)
			{
				bgTransInput.addEventListener('change', (event) => {
					let value = event.target.value; 
					let tr = modal.querySelector('#transparency_range'); 
					if (null !== tr)
					{ 
						tr.innerText = value;
					}
				});
			}

			


		}.bind(this), { once: true });

		// Fire off data load event.  This loads the popup content. 
		let data = {
			id: item_id,
			type: 'media',
			screen_action: 'media/getEditorPopup',
			action_name : action_name, 
		}
		data.callback = 'shortpixel.openEditorPopup';
		this.processor.AjaxRequest(data);


	}

	MediaEditorDoAction(data)
	{
		let modal = data.modal;
		let backgroundType = modal.querySelector('input[name="background_type"]:checked').value; 
		let backgroundColor = modal.querySelector('#bg_display_picker').value; 
		let backgroundTransparency = modal.querySelector('#bg_transparency').value; 
		let newFileName = modal.querySelector('input[name="new_filename"]').value; 
		let newPostTitle= modal.querySelector('input[name="new_posttitle"]').value;
		let spinner = document.querySelector('.load-preview-spinner'); 
		let scaleOption = document.querySelector('input[name="scale"]:checked').value; 

		let action = modal.dataset.action_name; 
		let opener = modal.dataset.opener; 


		let previewImage = document.querySelector('.modal-wrapper .image-preview i'); 
		previewImage.style.backgroundImage = 'url(' + previewImage.dataset.placeholder + ')';

		modal.dispatchEvent(modal.toggleButtonBlock);

		if ('gutenberg' == opener)
		{
			let searchParams = new URLSearchParams(window.location.search);
			if (searchParams.has('post'))
				{
					 data.attached_post_id = searchParams.get('post'); 
				}			 
		}

		if (typeof data.refresh !== 'undefined')
		{
			 var refresh = data.refresh;
		}
		else
		{
			 var refresh = false; 
		}

		spinner.classList.remove('shortpixel-hide');

		let request = {
			id: data.item_id,
			type: 'media',
			screen_action: 'media/getEditorPreview',
			newFileName: newFileName, 
			newPostTitle: newPostTitle, 
			is_preview: data.preview,
			refresh: refresh, 
			opener: opener,
			action_name: action,
		};

		if ('remove' == action)
		{
			request.background_type =  backgroundType; 
			request.background_color = backgroundColor; 
			request.background_transparency = backgroundTransparency;   
		}

		if ('scale' == action)
		{
			request.scale = scaleOption;
		}

		if (data.attached_post_id)
		{
			request.attached_post_id = data.attached_post_id;
		}

		request.callback = 'shortpixel.mediaEditorPreviewLoaded';
		this.processor.AjaxRequest(request);

		window.addEventListener('shortpixel.mediaEditorPreviewLoaded',  function (event) {
				this.MediaEditorPreviewEvent(event, modal);
		}.bind(this), {once:true});
	}

	MediaEditorPreviewEvent(event, modal)
	{
		let data = event.detail; 

		let errorElement = modal.querySelector('.error-message'); 
		let spinner = modal.querySelector('.load-preview-spinner'); 

		 if (true === data.is_error)
		 {
			 errorElement.innerText = data.message; 
			 errorElement.classList.remove('shortpixel-hide');
		 }
		 if (true === data.is_done)
		 {
			if (spinner)
			{
				 spinner.classList.add('shortpixel-hide');
			}
		 }

		 if (data.optimized)
		 {	
			 errorElement.classList.add('shortpixel-hide');
			 let previewImage = document.querySelector('.modal-wrapper .image-preview i'); 
			 previewImage.style.backgroundImage = 'url("' + data.optimized + '?ts=' + Date.now() + '")'; 
		}

		 if (typeof data.redirect !== 'undefined' && 'gutenberg' == data.redirect && data.new_attach_id)
		 {

			// Documentation is media-template.php and media-attachment.js 
			var lib = wp.media.frame.state().get('library');
			var file = data.file;
			lib.add(file);	
			document.dispatchEvent(modal.closeEvent);

		 }
		 else if (typeof data.redirect !== 'undefined')
		 {
			window.location.href = data.redirect;
		 }
		 modal.dispatchEvent(modal.toggleButtonUnblock);
	}

	FetchAltView(aiData, item_id)
	{
		if (false == this.ai_enabled)
		{
			 return;
		}
		var attachmentAlt = this.GetPageAttachmentAlt();
		if (null === attachmentAlt) // No attach alt around
		{
			return; 
		}
		if (typeof item_id === 'undefined')
		{
			console.error('Item_id not passed');
			return; 
		}

		if (typeof aiData !== 'undefined')
		{
			var newAltText = aiData.alt; 
			var newCaption = aiData.caption;
			var newDescription = aiData.description;
			var newTitle = aiData.post_title;
		}

		if (typeof newAltText !== 'undefined' || newAltText < 0)
		{
			var inputs = this.altInputNames;
	
			for (var i = 0; i < inputs.length; i++)
			{
				   var altInput = document.getElementById(inputs[i]); 
				   if (altInput !== null)
				   {
					   if (altInput.dataset.shortpixelAlt != item_id)
					   {
						 console.log('Returned alt, but not ours.', item_id, altInput);
						 continue; 
					   }
					   if (typeof altInput.value !== 'undefined')
					   {
						   altInput.value = newAltText; 	
					   }
					   else
					   {
						   altInput.innerText = newAltText; 	
					   }
					   
				   }
					   
			}
		}
		// edit media screen
		 let captionFields = ['attachment_caption', 'attachment-details-caption', 'attachment-details-two-column-caption']; 
		 let descriptionFields = ['attachment_content', 'attachment-details-description', 'attachment-details-two-column-description']; 
		 let postTitleFields = ['title', 'attachment-details-title', 'attachment-details-two-column-title'];
		 
		 if (typeof newCaption !== 'undefined' || newCaption < 0)
		 {
			for (var i = 0; i < captionFields.length; i++)
			{
				let captionField = document.getElementById(captionFields[i]); 
				if (null !== captionField)
				{
					captionField.value = newCaption; 
				}				 
			}
		 }

		 if (typeof newDescription !== 'undefined' || newDescription < 0)
		 {
			for (var i = 0; i < descriptionFields.length; i++)
			{
				let descriptionField = document.getElementById(descriptionFields[i]);
				if (null !== descriptionField)
				{
					 descriptionField.value = newDescription; 
				}
			}
		 }

		 if (typeof newTitle !== 'undefined' || newTitle < 0)
		 {
			for (var i = 0; i < postTitleFields.length; i++)
			{
				 let titleField = document.getElementById(postTitleFields[i]); 
				 if (null !== titleField)
				 {
					 titleField.value = newTitle;
				 }
			}
		 }

		if (null !== attachmentAlt)
		{
			if (attachmentAlt.dataset.shortpixelAlt && attachmentAlt.dataset.shortpixelAlt != item_id)
			{
				console.log('AttachmentAlt not ' + item_id); 
				return;
			}
			
			var data = {
				id: item_id,
				type: 'media',
				screen_action: 'ai/getAltData',
			}
			data.callback = 'shortpixel.AttachAiInterface';
			this.processor.AjaxRequest(data);

			window.addEventListener('shortpixel.AttachAiInterface', this.AttachAiInterface.bind(this), {once: true});
		}
	}

	GetPageAttachmentAlt()
	{
		for (var i = 0; i < this.altInputNames.length; i++)
			{
				var attachmentAlt = document.getElementById(this.altInputNames[i]);
				if (attachmentAlt !== null)
				{
					return attachmentAlt;
				} 	
			}
		return null;
	}

	RenderItemView(e) {
		e.preventDefault();
		var data = e.detail;
		if (data.media) {
			var id = data.media.id;

			var element = document.getElementById('shortpixel-data-' + id);
			if (element !== null) // Could be other page / not visible / whatever.
				element.outerHTML = data.media.itemView;
			else {
				console.error('Render element not found');
			}
		}
		else {
			console.error('Data not found - RenderItemview on media screen');
		}
		return false; // callback shouldn't do more, see processor.
	}

	BulkActionEvent(event) {

		var actionSelect = document.querySelector('select[name="action"]');
		if (null === actionSelect)
			return;

		var actionValue = actionSelect.value;

		// Check if we have a shortpixel event
		if (actionValue.includes('shortpixel')) {
			event.preventDefault();
			var items = document.querySelectorAll('input[name="media[]"]:checked');

			for (var i = 0; i < items.length; i++) {
				var media_id = items[i].value;
				var column = document.getElementById('shortpixel-data-' + media_id);
				var optimizable = column.classList.contains('is-optimizable');
				var restorable = column.classList.contains('is-restorable');
				var aiAction = column.classList.contains('ai-action');

				var compressionType = column.dataset.compression;

				switch (actionValue) {
					case 'shortpixel-optimize':
						if (optimizable) {
							this.Optimize(media_id);
						}
						break;
					case 'shortpixel-glossy':
					case 'shortpixel-lossy':
					case 'shortpixel-lossless':
					case 'shortpixel-smartcrop':
					case 'shortpixel-smartcropless':

						switch (actionValue) {
							case 'shortpixel-glossy':
								var compression = this.imageConstants.COMPRESSION_GLOSSY;
								break;
							case 'shortpixel-lossless':
								var compression = this.imageConstants.COMPRESSION_LOSSLESS;
								break;
							case 'shortpixel-lossy':
								var compression = this.imageConstants.COMPRESSION_LOSSY;
								break;
							case 'shortpixel-smartcrop':
								var action = this.imageConstants.ACTION_SMARTCROP;
								break;
							case 'shortpixel-smartcropless':
								var action = this.imageConstants.ACTION_SMARTCROPLESS;
								break;
						}

						if (typeof action === 'undefined' && compressionType == compression) {
							items[i].checked = false
							continue; // no need for compression. Should probably not work when actionstuff is happening.
						}
						else {
							compressionType = compression;
						}

						if (restorable) {
							this.ReOptimize(media_id, compressionType, action);
						}

						break;
					case 'shortpixel-restore':
						if (restorable) {
							this.RestoreItem(media_id);
						}
						break;
					case 'shortpixel-mark-completed':
							if (optimizable) {
								this.MarkCompleted(media_id);
							}
					break; 
					case 'shortpixel-generateai':
						if (aiAction)
						{
							 this.RequestAlt(media_id);
						}
					break; 
				}
				items[i].checked = false;

			} // for Loop

			var selectAllCheck = document.getElementById('cb-select-all-1');
			selectAllCheck.checked = false;
		} // actionvalue shortpixel check
	}

	HandleImage(resultItem, type) {
		var res = super.HandleImage(resultItem, type);
		var fileStatus = this.processor.fStatus[resultItem.fileStatus];
		var apiName = (typeof resultItem.apiName !== 'undefined') ? resultItem.apiName : 'optimize'; 


		// If image editor is active and file is being restored because of this reason ( or otherwise ), remove the warning if this one exists.
		if (fileStatus == 'FILE_RESTORED') {
			var warning = document.getElementById('shortpixel-edit-image-warning');
			if (warning !== null) {
				warning.remove();
			}
		}

		if (fileStatus == 'FILE_DONE' && apiName == 'ai')
		{
			this.UpdateGutenBerg(resultItem);
		}
	}

	RedoLegacy(id) {
		var data = {
			id: id,
			type: 'media',
			screen_action: 'redoLegacy',
		}
		data.callback = 'shortpixel.LoadItemView';

		window.addEventListener('shortpixel.LoadItemView', function (e) {
			var itemData = { id: e.detail.media.id, type: 'media' };
			this.processor.timesEmpty = 0; // reset the defer on this.
			this.processor.LoadItemView(itemData);
			this.UpdateMessage(itemData.id, '');

		}.bind(this), { 'once': true });

		this.SetMessageProcessing(id);
		this.processor.AjaxRequest(data);
	}

	// Check the Gallery popup on Media Library 
	ListenGallery() {
		var self = this;
		var next_item_run_process = false; 

		if (this.settings.hide_spio_in_popups)
		{
			return;
		}

		if (typeof wp.media === 'undefined') {
			this.ListenEditAttachment(); // Edit Media edit attachment screen
			return;
		}

		// This taken from S3-offload / media.js /  Grid media gallery
		if (typeof wp.media.view.Attachment.Details.TwoColumn !== 'undefined') {
			var detailsColumn = wp.media.view.Attachment.Details.TwoColumn; // Media library grid.
			var twoCol = true;
			var opener = 'gallery'; 
		}
		else {
			var detailsColumn = wp.media.view.Attachment.Details; // Gutenberg
			var twoCol = false;
			var opener = 'gutenberg'; 
		}

		var extended = detailsColumn.extend({
			render: function () {
				detailsColumn.prototype.render.apply(this); // Render Parent

				if (typeof this.fetchSPIOData === 'function') {
					let attach_id = this.model.get('id');

					if (typeof attach_id !== 'undefined')
					{
						if (true === next_item_run_process )
						{
							window.ShortPixelProcessor.SetInterval(-1);
							window.ShortPixelProcessor.RunProcess();
							next_item_run_process = false; 
						}
						else
						{
						this.fetchSPIOData(attach_id);
						this.spioBusy = true; // Note if this system turns out not to work, the perhaps render empties all if first was painted, second cancelled?
						}
					}
					else if (true == this.model.get('uploading'))
					{
						next_item_run_process = true; 
						console.log('Upload Start Detected');
					}
					else
					{
						console.log('Id not found on render');
					}
				}

				return this;
			},

			fetchSPIOData: function (id) {
				var data = {};
				data.id = id;
				data.type = self.type;
				data.callback = 'shortpixel.MediaRenderView';

				if (typeof this.spioBusy !== 'undefined' && this.spioBusy === true) {
					return;
				}

				window.addEventListener('shortpixel.MediaRenderView', this.renderSPIOView.bind(this), { 'once': true });
				self.processor.LoadItemView(data);
			},

			renderSPIOView: function (e, timed) {
				this.spioBusy = false;
				if (!e.detail || !e.detail.media || !e.detail.media.itemView) {
					return;
				}

				var item_id = e.detail.media.id; 

				var $spSpace = this.$el.find('.attachment-info .details');
				if ($spSpace.length === 0 && (typeof timed === 'undefined' || timed < 5)) {
					// It's possible the render is slow or blocked by other plugins. Added a delay and retry bit later to draw.
					if (typeof timed === 'undefined') {
						var timed = 0;
					}
					else {
						timed++;
					}
					setTimeout(function () { this.renderSPIOView(e, timed) }.bind(this), 1000);
				}

				let editorArgs = {}; 
				if (e.detail.media.image && e.detail.media.image.width) // dottadot
				{
					editorArgs.image_width = e.detail.media.image.width; 
				}
				if (e.detail.media.image && e.detail.media.image.extension) // dottadot
				{
					editorArgs.image_ext = e.detail.media.image.extension; 
				}

				var html = this.doSPIORow(e.detail.media.itemView);
				$spSpace.after(html);

				self.InitEditorActions(item_id, opener, editorArgs);
				self.FetchAltView(undefined, item_id); 

			},
			doSPIORow: function (dataHtml) {
				var html = '';
				html += '<div class="shortpixel-popup-info">';
				html += '<label class="name">ShortPixel</label>';
				html += dataHtml;
				html += '</div>';
				return html;
			},
			 
			editAttachment: function (event) {
				event.preventDefault();
				self.AjaxOptimizeWarningFromUnderscore(this.model.get('id'));
				detailsColumn.prototype.editAttachment.apply(this, [event]);
			}
		});

		if (true === twoCol) {
			wp.media.view.Attachment.Details.TwoColumn = extended; //wpAttachmentDetailsTwoColumn;
		}
		else {
			wp.media.view.Attachment.Details = extended;
		}
	}

	// It's not possible via hooks / server-side, so attach the AI interface HTML to where it should be attached. 
	AttachAiInterface(event)
	{
		
		var data = event.detail.media; 	
		var item_id = data.item_id; 
		if (typeof data === 'undefined')
		{
			console.log('Error on ai interface!', data);
			return false;
		}
		var element = this.GetPageAttachmentAlt();

		if (null == element)
		{
			console.warn('Could not attach ID interface here! '); 
			return false; 
		}

		var wrapper = document.getElementById('shortpixel-ai-wrapper-' + item_id);

		if (null !== wrapper) // remove previous controls
		{
			wrapper.remove();
		}

		// This will not work because the wrapper doesn't exist and is recreated each time on fly. Need some place to store item_id on item load
		var wrapper = document.createElement('div');
		wrapper.id = 'shortpixel-ai-wrapper-' + item_id;
		wrapper.classList.add('shortpixel-ai-interface',element.getAttribute('id'));
		
		wrapper.innerHTML = data.snippet;	


		element.after(wrapper);

		element.dataset.shortpixelAlt = data.item_id;		
		if (data.result_alt && data.has_data)
			element.value = data.result_alt;

	}

	AjaxOptimizeWarningFromUnderscore(id) {
		var data = {
			id: id,
			type: 'media',
			screen_action: 'getItemEditWarning',
			callback: 'ShortPixelMedia.getItemEditWarning'
		};

		window.addEventListener('ShortPixelMedia.getItemEditWarning', this.CheckOptimizeWarning.bind(this), { 'once': true });
		this.processor.AjaxRequest(data);
	}

	CheckOptimizeWarning(event) {
		var data = event.detail;

		var image_post_id = data.id;
		var is_restorable = data.is_restorable;
		var is_optimized = data.is_optimized;

		if ('true' === is_restorable || 'true' === is_optimized) {
			this.ShowOptimizeWarning(image_post_id, is_restorable, is_optimized);
		}
	}

	ShowOptimizeWarning(image_post_id, is_restorable, is_optimized) {
		var div = document.createElement('div');
		div.id = 'shortpixel-edit-image-warning';
		div.classList.add('shortpixel', 'shortpixel-notice', 'notice-warning');


		if ('true' == is_restorable) {
			var restore_link = spio_media.restore_link.replace('#post_id#', image_post_id);
			div.innerHTML = '<p>' + spio_media.optimized_text + ' <a href="' + restore_link + '">' + spio_media.restore_link_text + '</a></p>';
		}
		else {
			div.innerHTML = '<p>' + spio_media.optimized_text + ' ' + spio_media.restore_link_text_unrestorable + '</p>';

		}
		// only if not existing.
		if (document.getElementById('shortpixel-edit-image-warning') == null) {
			var $menu = jQuery('.imgedit-menu');
			if ($menu.length > 0) {
				$menu.append(div);
			}
			else {
				jQuery(document).one('image-editor-ui-ready', function () // one!
				{
					jQuery('.imgedit-menu').append(div);
				});

			}

		}
	}

	// This should be the edit-attachment screen
	ListenEditAttachment() {
		var self = this;
		var imageEdit = window.imageEdit;

		jQuery(document).on('image-editor-ui-ready', function () {
			var element = document.querySelector('input[name="post_ID"]');
			if (null === element) {
				console.error('Could not fetch post id on this screen');
				return;
			}

			var post_id = element.value;

			var data = {
				id: post_id,
				type: 'media',
				screen_action: 'getItemEditWarning',
				callback: 'ShortPixelMedia.getItemEditWarning'
			};

			window.addEventListener('ShortPixelMedia.getItemEditWarning', self.CheckOptimizeWarning.bind(self), { 'once': true });
			window.ShortPixelProcessor.AjaxRequest(data);
		});
	}

	ListenGutenberg()
	{

		var self = this; 

		if (typeof wp.data == 'undefined')
		{
			return;
		}

		wp.data.subscribe(() => {
			if (wp.data.select('core')) {
				const { getSelectedBlock } = wp.data.select('core/block-editor');
		
				const block = getSelectedBlock();
			
				if (block && block.name === 'core/image') {
					const imageId = block.attributes.id; // Get the image ID
		
					if (imageId) {
		
						if (self.gutenCheck.indexOf(imageId) === -1)
						{
						
							window.ShortPixelProcessor.SetInterval(-1);
							window.ShortPixelProcessor.RunProcess();
						
							self.gutenCheck.push(imageId);
						}
						else
						{
						
						}
		
					}
				}
			}
		});
	}

	UpdateGutenBerg(resultItem)
	{
		
		var attach_id = resultItem.item_id; 
		var aiData = resultItem.aiData; 
		
		if (! wp.data || ! wp.data.select('core'))
		{
			return false; 
		}

		let blocks = wp.data.select( 'core/block-editor' ).getBlocks();
		for (let i = 0; i < blocks.length; i++)
		{
			let block = blocks[i];

			 if (block.attributes.id == attach_id)
			 {
				let clientId = block.clientId;

				console.log('DATA DISPATCH ', clientId, aiData);				
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( clientId, 
					aiData );

			 }
		}
	}

} // class






