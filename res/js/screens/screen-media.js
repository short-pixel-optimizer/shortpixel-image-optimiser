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

		}

		ListenGallery()
		{
		//		var mediaFrame = document.querySelector('.media-frame-content');
			  if (typeof (wp.media) === 'undefined'  || typeof wp.media.frame === 'undefined')
				{
					 return;
				}
				var self = this;

				wp.media.frame.on('edit:attachment', function(model, e) { console.log(model, e);
							var attach_id = model.id;
							console.log(attach_id);
							var data = {}
							data.id = attach_id;
							data.type = self.type;
							self.processor.LoadItemView(data);

				});
		}

    RenderItemView(e)
    {
				e.preventDefault();
				console.log(e);
        var data = e.detail;
        if (data.media)
        {
            var id = data.media.id;

            var element = document.getElementById('sp-msg-' + id);
						if (element !== null) // Could be other page / not visible / whatever.
            	element.outerHTML = data.media.itemView;
        }
				else {
					console.log('Data not found - RenderItemview on media screen');
				}
        return false; // callback shouldn't do more, see processor.
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

} // class
