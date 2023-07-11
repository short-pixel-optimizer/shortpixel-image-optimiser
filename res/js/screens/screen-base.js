'use strict';

class ShortPixelScreenBase
{
	isCustom = true;
	isMedia = true;
	processor;
	strings = [];


	constructor(MainScreen, processor)
	{
		 this.processor = processor;
		 this.strings = spio_screenStrings;

	}

	// Function for subclasses to add more init. Seperated because of screens that need to call Process functions when starting.
	Init()
	{
		 this.ListenGallery();
	}

//	var message = {status: false, http_status: response.status, http_text: text, status_text: response.statusText };
	HandleError(data)
	{
		//if (this.processor.debugIsActive == 'false')
		//	return; // stay silent when debug is not active.

		var text = String(data.http_text);
		var title = this.strings.fatalError;
		var notice = this.GetErrorNotice(title, text);

 		var el = this.GetErrorPosition();
		if (el === null)
		{
				console.error('Cannot display error - no position found!');
				return;
		}
		el.prepend(notice);

	}

	// No actions at the base.
	HandleItemError(result)
	{

	}

	HandleErrorStop()
	{
		  var title = this.strings.fatalErrorStop;
			var text = this.strings.fatalErrorStopText;

			var notice = this.GetErrorNotice(title, text);
			var el = this.GetErrorPosition();
			if (el === null)
			{
				console.error('Cannot display error - no position found!');
				return;
			}
		el.prepend(notice);
	}


	GetErrorNotice(title, text)
	{
		  var notice = document.createElement('div');

			var button  = document.createElement('button'); // '<button type="button" class="notice-dismiss"></button>';
			button.classList.add('notice-dismiss');
			button.type = 'button';
			button.addEventListener('click', this.EventCloseErrorNotice);

			notice.classList.add('notice', 'notice-error', 'is-dismissible');

			notice.innerHTML += '<p><strong>' + title + '</strong></p>';
			notice.innerHTML += '<div class="error-details">' + text + '</div>';

			notice.append(button);

			return notice;

	}

	EventCloseErrorNotice(event)
	{
			event.target.parentElement.remove();
	}

	// Search for where to insert the notice before ( ala WP system )
	GetErrorPosition()
	{
		var el = document.querySelector('.is-shortpixel-settings-page');
		if (el !== null) // we are on settings page .
		{
			 return el;
		}

		var el = document.querySelector('.wrap');
		if (el !== null)
			return el;


		var el = document.querySelector('#wpbody-content');
		if (el !== null)
			return el;


		return null;
	}

	HandleImage(result, type)
	{
			return true;
	}

	UpdateStats()
	{

	}


	RenderItemView(e)
	{

	}

	// @todo Find a better home for this. Global screen class?
	ParseNumber(str)
	{
		 str = str.replace(',','', str).replace('.','',str);
		 return parseInt(str);
	}

	ListenGallery()
	{
			if (typeof (wp.media) === 'undefined'  || typeof wp.media.frame === 'undefined')
			{
				//console.log('No WP Media or Frame', wp);
				 this.ListenEditAttachment();
				 return;
			}
			var self = this;

			// This taken from S3-offload / media.js
			var wpAttachmentDetailsTwoColumn = wp.media.view.Attachment.Details.TwoColumn;
			wp.media.view.Attachment.Details.TwoColumn = wpAttachmentDetailsTwoColumn.extend ({
					render: function()
					{
						 wpAttachmentDetailsTwoColumn.prototype.render.apply( this );
						 this.fetchSPIOData(this.model.get( 'id' ));

						 return this;
					},
					fetchSPIOData : function (id)
					{
						var data = {};
						data.id =  id;
						data.type = self.type;
						data.action = 'getItemView';
						data.callback = 'shortpixel.MediaRenderView';

						window.addEventListener('shortpixel.MediaRenderView', this.renderSPIOView.bind(this), {'once':true});
						self.processor.LoadItemView(data);

					},

					renderSPIOView: function(e, timed)
					{
						if (! e.detail || ! e.detail.media || ! e.detail.media.itemView)
						{

							return;
						}

						var $spSpace = this.$el.find('.attachment-info .details');
						if ($spSpace.length === 0 && (typeof timed === 'undefined' || timed < 5))
						{
							// It's possible the render is slow or blocked by other plugins. Added a delay and retry bit later to draw.
							if (typeof timed === 'undefined')
							{
								 var timed = 0;
							}
							else {
								 timed++;
							}
							setTimeout(function () { this.renderSPIOView(e, true) }.bind(this), 1000);
						}

						var html = this.doSPIORow(e.detail.media.itemView);

						$spSpace.after(html);
					},
					doSPIORow : function(dataHtml)
					{
						 var html = '';
						 html += '<div class="shortpixel-popup-info">';
						 html += '<label class="name">ShortPixel</label>';
						 html += dataHtml;
						 html += '</div>';
						 return html;
					},
					editAttachment: function(event)
					{
						 var data = {
							 id: this.model.get( 'id' ),
							 type: 'media',
							 screen_action: 'getItemEditWarning',
							 callback: 'ShortPixelMedia.getItemEditWarning'
						 };

						 window.addEventListener('ShortPixelMedia.getItemEditWarning', self.CheckOptimizeWarning.bind(self), {'once': true} );
						 self.processor.AjaxRequest(data);

						 wpAttachmentDetailsTwoColumn.prototype.editAttachment.apply( this, event);

					}
			})
	}


	CheckOptimizeWarning(event)
	{
		var data = event.detail;

		var image_post_id = data.id;
		var is_restorable = data.is_restorable;
		var is_optimized = data.is_optimized;

		if ('true' === is_restorable || 'true' === is_optimized)
		{
			 this.ShowOptimizeWarning(image_post_id, is_restorable, is_optimized);
		}

	}

	ShowOptimizeWarning(image_post_id, is_restorable, is_optimized)
	{
			var div = document.createElement('div');
			div.id = 'shortpixel-edit-image-warning';
			div.classList.add('shortpixel', 'shortpixel-notice', 'notice-warning');


			if ('true' == is_restorable)
			{
				var restore_link = spio_media.restore_link.replace('#post_id#', image_post_id);
				div.innerHTML = '<p>' + spio_media.optimized_text + ' <a href="'  + restore_link + '">' + spio_media.restore_link_text + '</a></p>' ;
			}
			else {
				div.innerHTML = '<p>' + spio_media.optimized_text  + ' ' + spio_media.restore_link_text_unrestorable + '</p>' ;

			}
			// only if not existing.
			if (document.getElementById('shortpixel-edit-image-warning') == null)
				jQuery('.imgedit-menu').append(div);
	}

	// This should be the edit-attachment screen
 ListenEditAttachment()
 {
	 var self = this;
	 jQuery(document).on('image-editor-ui-ready', function()
	 {

			/* @todo Something like this might be used for gutenberg, but this doesn't work like this.
			if (typeof (wp.media) !== 'undefined'  || typeof wp.media.frame !== 'undefined')
			{

				// copy from EditAttachment
				var data = {
					id: wp.media.model.Attachment.get( 'id' ),
					type: 'media',
					screen_action: 'getItemEditWarning',
					callback: 'ShortPixelMedia.getItemEditWarning'
				};

				window.addEventListener('ShortPixelMedia.getItemEditWarning', self.CheckOptimizeWarning.bind(self), {'once': true} );
				self.processor.AjaxRequest(data);


			} */

		 var element = document.querySelector('input[name="post_ID"]');
		 if (null === element)
		 {
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

		 window.addEventListener('ShortPixelMedia.getItemEditWarning', self.CheckOptimizeWarning.bind(self), {'once': true} );
		 window.ShortPixelProcessor.AjaxRequest(data);
	 });
 }


}
