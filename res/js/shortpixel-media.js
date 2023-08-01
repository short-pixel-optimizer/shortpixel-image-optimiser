'use strict';

(function( $) {

	if (typeof (wp.media) === 'undefined'  || typeof wp.media.frame === 'undefined')
	{
		 return;
	}
	var ShortPixelFilter = wp.media.view.AttachmentFilters.extend
	({
		id: 'shortpixel-media-filter',

		createFilters: function() {
			 var filters = {};
			 var optimizedfilter = spio_media.mediafilters.optimized;

			 for (const [key,value] of Object.entries(optimizedfilter))
			 {
				  filters[key] =  {
						 text: value,
						 props: { 'shortpixel_status': key },
						 priority: 10,
					}
			 };

			 this.filters = filters;
		}

	}); // ShortPixelFilter

	var AttachmentsBrowser = wp.media.view.AttachmentsBrowser;

	wp.media.view.AttachmentsBrowser = wp.media.view.AttachmentsBrowser.extend({
		createToolbar: function() {

			// Make sure to load the original toolbar
			AttachmentsBrowser.prototype.createToolbar.call( this );

			this.toolbar.set(
				'ShortPixelFilter',
				new ShortPixelFilter({
					controller: this.controller,
					model:      this.collection.props,
					priority:   -80
				})
				.render()
			);
		}
	});

})( jQuery);

//}); // jquery  - Attachmentfilters
