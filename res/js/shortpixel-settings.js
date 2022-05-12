'use strict'

// New Class for Settings Section.
var ShortPixelSettings = function()
{

	 this.Init = function()
	 {
		 	console.log('Init Settings');
			this.InitActions();
	 }

	this.InitActions = function()
	{
			var toggles = document.querySelectorAll('[data-toggle]');
			var self = this;

			toggles.forEach(function (toggle, index)
			{
				//	var target = (action.getAttribute('data-toggle')) ? action.getAttribute('data-toggle') : 'click';
					toggle.addEventListener('change', self.DoToggleAction.bind(self));

					var evInit = new Event('change');
					toggle.dispatchEvent(evInit);
			});

	}

	this.DoToggleAction = function(event)
	{
			event.preventDefault();

			var checkbox = event.target;
			var target = document.getElementById(checkbox.getAttribute('data-toggle'));

		  if (typeof checkbox.dataset.toggleReverse !== 'undefined')
			{
				var checked = ! checkbox.checked;
			}
			else {
				var checked = checkbox.checked;
			}

			if (checked)
			{
			  // target.classList.add('is-visible');
				this.ShowElement(target);
			}
			else
			{
				this.HideElement(target);
  			//	target.classList.remove('is-visible');
			}
	}

	this.ShowElement = function (elem) {

	// Get the natural height of the element
	var getHeight = function () {
		elem.style.display = 'block'; // Make it visible
		var height = elem.scrollHeight + 'px'; // Get it's height
		elem.style.display = ''; //  Hide it again
		return height;
	};

	var height = getHeight(); // Get the natural height
	elem.classList.add('is-visible'); // Make the element visible
	elem.style.height = height; // Update the max-height

	// Once the transition is complete, remove the inline max-height so the content can scale responsively
	window.setTimeout(function () {
		elem.style.height = '';
	}, 350);

};

// Hide an element
this.HideElement = function (elem) {

	// Give the element a height to change from
	elem.style.height = elem.scrollHeight + 'px';

	// Set the height back to 0
	window.setTimeout(function () {
		elem.style.height = '0';
	}, 1);

	// When the transition is complete, hide it
	window.setTimeout(function () {
		elem.classList.remove('is-visible');
	}, 350);

};

	this.Init();
} // SPSettings

document.addEventListener("DOMContentLoaded", function(){
	  var s = new ShortPixelSettings();
});
