'use strict';

// MainScreen as an option for delegate functions
class ShortPixelScreen extends ShortPixelScreenItemBase //= function (MainScreen, processor)
{
    isCustom = true;
    isMedia = true;
		type = 'media';

		Init()
		{
			super.Init();
      this.ListenGallery();

			// bind DoAction, for bulk actions in Media Libbrary to event
			var actionEl = document.getElementById('doaction');
      if (actionEl !== null)
			   actionEl.addEventListener('click', this.BulkActionEvent.bind(this));

		}

    RenderItemView(e)
    {
				e.preventDefault();
        var data = e.detail;
        if (data.media)
        {
            var id = data.media.id;

            var element = document.getElementById('sp-msg-' + id);
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

		BulkActionEvent(event)
		{

			 var actionSelect = document.querySelector('select[name="action"]');
			 if ( null === actionSelect)
			  return ;

	     var actionValue = actionSelect.value;

			 // Check if we have a shortpixel event
			  if (actionValue.includes('shortpixel'))
				{
						event.preventDefault();
						var items = document.querySelectorAll('input[name="media[]"]:checked');

						for (var i = 0; i < items.length; i++)
						{
							 var media_id = items[i].value;
							 var column = document.getElementById('sp-msg-' + media_id);
							 var optimizable = column.classList.contains('is-optimizable');
							 var restorable = column.classList.contains('is-restorable');

							 var compressionType = column.dataset.compression;

							 switch(actionValue)
							 {
								 	case 'shortpixel-optimize':
											if (optimizable)
											{
												 this.Optimize(media_id);
											}
									break;
									case 'shortpixel-glossy':
									case 'shortpixel-lossy':
									case 'shortpixel-lossless':
									case 'shortpixel-smartcrop':
									case 'shortpixel-smartcropless':

										  switch(actionValue)
											{
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

											if (typeof action === 'undefined' && compressionType == compression)
											{
													items[i].checked = false
													continue; // no need for compression. Should probably not work when actionstuff is happening.
											}
											else {
												 compressionType = compression;
											}

											if (restorable)
											{
												 	this.ReOptimize(media_id, compressionType, action);
											}

									break;
									case 'shortpixel-restore':
											if (restorable)
											{
												 this.RestoreItem(media_id);
											}
									break;
									case 'shortpixel-mark-completed':
									{
										if (optimizable)
										{
											 this.MarkCompleted(media_id);
										}
									}
							 }
							 items[i].checked = false;

						} // for Loop

						var selectAllCheck = document.getElementById('cb-select-all-1');
						selectAllCheck.checked = false;
				} // actionvalue shortpixel check
		}

		HandleImage(resultItem, type)
		{
				var res = super.HandleImage(resultItem, type);
				var fileStatus = this.processor.fStatus[resultItem.fileStatus];

				// If image editor is active and file is being restored because of this reason ( or otherwise ), remove the warning if this one exists.
				if (fileStatus == 'FILE_RESTORED')
				{
					 var warning = document.getElementById('shortpixel-edit-image-warning');
					 if (warning !== null)
					 {
						  warning.remove();
					 }
				}
		}

		RedoLegacy(id)
		{
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

			}.bind(this), {'once': true} );

			this.SetMessageProcessing(id);
			this.processor.AjaxRequest(data);
		}

    ListenGallery()
  	{
  	   	var self = this;
  			if (typeof wp.media === 'undefined')
  			{
  				 this.ListenEditAttachment(); // Edit Media edit attachment screen
  				 return;
  			}

  			// This taken from S3-offload / media.js /  Grid media gallery
  		  if (typeof wp.media.view.Attachment.Details.TwoColumn !== 'undefined')
        {
  			     var detailsColumn = wp.media.view.Attachment.Details.TwoColumn;
             var twoCol = true;
        }
        else {
            var detailsColumn = wp.media.view.Attachment.Details;
            var twoCol = false;
        }

  			 var extended = detailsColumn.extend ({
            render: function()
            {
               detailsColumn.prototype.render.apply( this );
               if(typeof this.fetchSPIOData === 'function') this.fetchSPIOData(this.model.get( 'id' ));

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
               event.preventDefault();
               self.AjaxOptimizeWarningFromUnderscore(this.model.get( 'id' ));
               detailsColumn.prototype.editAttachment.apply( this, [event]);
            }
        });

        if (true === twoCol)
        {
          wp.media.view.Attachment.Details.TwoColumn =  extended; //wpAttachmentDetailsTwoColumn;
        }
        else {
          wp.media.view.Attachment.Details = extended;
        }
  	}

  	AjaxOptimizeWarningFromUnderscore(id)
  	{
  		var data = {
  			id: id,
  			type: 'media',
  			screen_action: 'getItemEditWarning',
  			callback: 'ShortPixelMedia.getItemEditWarning'
  		};

  		window.addEventListener('ShortPixelMedia.getItemEditWarning', this.CheckOptimizeWarning.bind(this), {'once': true} );
  		this.processor.AjaxRequest(data);
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
        {
          var $menu = jQuery('.imgedit-menu');
          if ($menu.length > 0)
          {
  				      $menu.append(div);
          }
          else {
            jQuery(document).one('image-editor-ui-ready', function() // one!
            {
                jQuery('.imgedit-menu').append(div);
            });

          }

        }
  	}

  	// This should be the edit-attachment screen
   ListenEditAttachment()
   {
  	 var self = this;
  	 var imageEdit = window.imageEdit;

  	 jQuery(document).on('image-editor-ui-ready', function()
  	 {
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

} // class
