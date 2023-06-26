'use strict';

// This document is jquery because WP emits jquery events on image edit
jQuery(document).ready(function () {

		jQuery(document).on('image-editor-ui-ready', Init);
		var image_post_id;
		var is_restorable;
    var is_optimized;

		function Init()
		{
			image_post_id = spio_media.post_id;
			is_restorable = spio_media.is_restorable;
			is_optimized = spio_media.is_optimized;

			if ('true' === is_restorable || 'true' === is_optimized)
			{
				 showOptimizeWarning();
			}
		}

		function showOptimizeWarning()
		{
				var div = document.createElement('div');
				div.id = 'shortpixel-edit-image-warning';
				div.classList.add('shortpixel', 'shortpixel-notice', 'notice-warning');
				if ('true' == spio_media.is_restorable)
					div.innerHTML = '<p>' + spio_media.optimized_text + ' <a href="'  + spio_media.restore_link + '">' + spio_media.restore_link_text + '</a></p>' ;
				else {
					div.innerHTML = '<p>' + spio_media.optimized_text  + ' ' + spio_media.restore_link_text + '</p>' ;

				}
				// only if not existing.
				if (document.getElementById('shortpixel-edit-image-warning') == null)
					jQuery('.imgedit-menu').append(div);
		}

}); // jquery - imageEdit


//jQuery(document).ready(function () {
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
			 console.log(optimizedfilter);
		//	 optimizedfilter.forEach(function (option, key)
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
