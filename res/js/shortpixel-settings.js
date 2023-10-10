'use strict'

// New Class for Settings Section.
var ShortPixelSettings = function()
{

	 this.Init = function()
	 {
			this.InitActions();
			this.SaveOnKey();
	 }

	this.InitActions = function()
	{
			var toggles = document.querySelectorAll('[data-toggle]');
			var self = this;

			toggles.forEach(function (toggle, index)
			{
				//	var target = (action.getAttribute('data-toggle')) ? action.getAttribute('data-toggle') : 'click';
					toggle.addEventListener('change', self.DoToggleAction.bind(self));

					var evInit = new CustomEvent('change',  {detail : { init: true }} );
					toggle.dispatchEvent(evInit);
			});

			// Modals
			var modals = document.querySelectorAll('[data-action="open-modal"]');
			modals.forEach(function (modal, index)
			{
					modal.addEventListener('click', self.OpenModal.bind(self));
			});

			// Events for the New Exclusion dialog
			var newExclusionInputs = document.querySelectorAll('.new-exclusion select, .new-exclusion input, .new-exclusion button');
			newExclusionInputs.forEach(function (input)
			{
				  if (input.name == 'addExclusion')
					{
						var eventType = 'click';
					}
					else {
						var eventType = 'change';
					}
					input.addEventListener(eventType, self.NewExclusionUpdateEvent.bind(self));
			});

			// Remove buttons on the Exclude patterns to remove them
			var exclusionRemoveButtons = document.querySelectorAll('.exclude-list li > .dashicons-remove');
			exclusionRemoveButtons.forEach(function (input) {
					input.addEventListener('click', self.RemoveExclusionEvent.bind(self));
			});

			var addNewExclusionButton = document.querySelector('.new-exclusion-button');
			addNewExclusionButton.addEventListener('click', this.NewExclusionShowInterfaceEvent.bind(this));


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

			if (target === null)
			{
				 console.error('Target element ID not found', checkbox);
				 return false;
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


this.OpenModal = function(elem)
{
		var target = elem.target;
		var targetElem = document.getElementById(target.dataset.target);
		if (! targetElem)
			return;

		var shade = document.getElementById('spioSettingsModalShade');
		var modal = document.getElementById('spioSettingsModal');

		shade.style.display = 'block';
		modal.classList.remove('spio-hide');

		var body = modal.querySelector('.spio-modal-body');
		body.innerHTML = ('afterbegin', targetElem.innerHTML); //.cloneNode()
		body.style.background = '#fff';
		shade.addEventListener('click', this.CloseModal.bind(this), {'once': true} );

		modal.querySelector('.spio-close-help-button').addEventListener('click', this.CloseModal.bind(this), {'once': true});

		if (body.querySelector('[data-action="ajaxrequest"]') !== null)
		{
			body.querySelector('[data-action="ajaxrequest"]').addEventListener('click', this.SendModal.bind(this));
		}

}

this.CloseModal = function(elem)
{
	var shade = document.getElementById('spioSettingsModalShade');
	var modal = document.getElementById('spioSettingsModal');

	shade.style.display = 'none';
	modal.classList.add('spio-hide');

}

this.SendModal = function(elem)
{
	var modal = document.getElementById('spioSettingsModal');
	var body = modal.querySelector('.spio-modal-body');
	var inputs = body.querySelectorAll('input');

	var data = {};
	var validated = true;

	for (var i = 0; i < inputs.length; i++)
	{
		 data[inputs[i].name] = inputs[i].value;
		 if (typeof inputs[i].dataset.required !== 'undefined')
		 {
			  if (inputs[i].value !== inputs[i].dataset.required)
				{
					 inputs[i].style.border = '1px solid #ff0000';
					 validated = false;
					 return false;
				}
		 }
	}

	if (! validated)
		return false;

	data.callback = 'shortpixelSettings.receiveModal'
	data.type = 'settings';

	window.addEventListener('shortpixelSettings.receiveModal', this.ReceiveModal.bind(this), {'once': true} );

	window.ShortPixelProcessor.AjaxRequest(data);

}

this.ReceiveModal = function(elem)
{
	 if (typeof elem.detail.settings.results !== 'undefined')
	 {
		 var modal = document.getElementById('spioSettingsModal');
	 	 var body = modal.querySelector('.spio-modal-body');

		 body.innerHTML = elem.detail.settings.results;
	 }

	 if (typeof elem.detail.settings.redirect !== 'undefined')
	 {
			window.location.href = elem.detail.settings.redirect;
	 }

}

this.SaveOnKey = function()
{
	var saveForm = document.getElementById('wp_shortpixel_options');
	if (saveForm === null)
		return false; // no form no save.

	window.addEventListener('keydown', function(event) {

    if (! (event.key == 's' || event.key == 'S')  || ! event.ctrlKey)
		{
			return true;
		}
		document.getElementById('wp_shortpixel_options').submit();
    event.preventDefault();
    return false;
	});
}

this.NewExclusionShowInterfaceEvent = function (event)
{
	 event.preventDefault();

	 var element = document.querySelector('.new-exclusion');
	 element.classList.remove('not-visible');
}

// EXCLUSIONS
this.NewExclusionUpdateEvent = function(event)
{
	console.log(event);
	var target = event.target;
	var inputName = event.target.name;
	switch(inputName)
	{
		 case 'exclusion-type':
		 	 this.NewExclusionUpdateType(target);
		 break;
		 case 'apply-select':
		 	 this.NewExclusionUpdateThumbType(target);
		 break;
		 case 'addExclusion':
		 	 this.NewExclusionButtonAdd(target);
		 break;
		 case 'exclusion-exactsize':
		 	 this.NewExclusionToggleSizeOption(target);
		 break;

	}
}

this.NewExclusionUpdateType = function(element)
{
	 	 var value = element.value;
		 var selected = element.options[element.selectedIndex];
		 var example = selected.dataset.example;
		 if ( typeof example == 'undefined')
		 {
			  example = '';
		 }

		 var valueOption = document.querySelector('.new-exclusion .value-option');
		 var sizeOption = document.querySelector('.new-exclusion .size-option');
		 var regexOption = document.querySelector('.regex-option');
		 var switchExactOption = document.querySelector('.exact-option');


		 if (value == 'size')
		 {
 			 	valueOption.classList.add('not-visible');
				sizeOption.classList.remove('not-visible');
				switchExactOption.classList.remove('not-visible');
				regexOption.classList.add('not-visible');
		 }
		 else {
			 valueOption.classList.remove('not-visible');
			 sizeOption.classList.add('not-visible');
			 switchExactOption.classList.add('not-visible');
			 regexOption.classList.remove('not-visible');
		 }

		 var valueInput = document.querySelector('input[name="exclusion-value"]');
		 if (null !== valueInput)
		 {
			  valueInput.placeholder = example;
		 }
}

this.NewExclusionUpdateThumbType = function(element)
{
		 var value = element.value;

		 var thumbSelect = document.querySelector('select[name="thumbnail-select"]');

		 if (value == 'selected-thumbs')
		 {
			 	thumbSelect.classList.remove('not-visible');
		 }
		 else {
		 		thumbSelect.classList.add('not-visible');
		 }
}

this.NewExclusionButtonAdd = function(element)
{
	 	// compile all inputs to a json encoded string to add to UX
		 var setting = {
			 'type' : '',
			 'value' :  '',
			 'apply' : '',
		 };

		 var typeOption = document.querySelector('.new-exclusion select[name="exclusion-type"]');
		 var valueOption = document.querySelector('.new-exclusion input[name="exclusion-value"]');
		 var applyOption = document.querySelector('.new-exclusion select[name="apply-select"]');
		 var regexOption = document.querySelector('.new-exclusion input[name="exclusion-regex"]');

		 setting.type = typeOption.value;
		 setting.value = valueOption.value;
		 setting.apply = applyOption.value;

		 // When selected thumbnails option is selected, add the thumbnails to the list.
		 if ('selected-thumbs' == applyOption.value)
		 {
			  var thumbOption = document.querySelector('.new-exclusion select[name="thumbnail-select"]');
				var thumblist  = [];
				for(var i =0; i < thumbOption.selectedOptions.length; i++)
				{
					 thumblist.push(thumbOption.selectedOptions[i].value);
				}

				setting.thumblist = thumblist;
		 }

		 // Check for regular expression active on certain types
		 if (true === regexOption.checked && (typeOption.value == 'name' || typeOption.value == 'path'))
		 {
			  setting.type = 'regex-' + setting.type;
		 }

		 // Options for size setting
		 if ('size' == setting.type)
		 {
			 var exactOption = document.querySelector('.new-exclusion input[name="exclusion-exactsize"]');
			 if (true === exactOption.checked)
			 {
					 var width = document.querySelector('.new-exclusion input[name="exclusion-width"]');
					 var height = document.querySelector('.new-exclusion input[name="exclusion-height"]');
					 setting.value = width.value + 'x' + height.value;
			 }
			 else {
				 var minwidth = document.querySelector('.new-exclusion input[name="exclusion-minwidth"]');
				 var maxwidth = document.querySelector('.new-exclusion input[name="exclusion-maxwidth"]');
				 var minheight = document.querySelector('.new-exclusion input[name="exclusion-minheight"]');
				 var maxheight = document.querySelector('.new-exclusion input[name="exclusion-maxheight"]');

				 setting.value = minwidth.value + '-' + maxwidth.value + 'x' + minheight.value + '-' + maxheight.value;
			 }

		 }

		 var listElement = document.querySelector('.exclude-list');
		 var newElement = document.createElement('li');
		 var inputElement = document.createElement('input');

		 inputElement.type = 'hidden';
		 inputElement.name = 'exclusions[]';
		 console.log('Settings created: ', setting);
		 inputElement.value = JSON.stringify(setting);

		 newElement.appendChild(inputElement);

		 var spans = [setting.type, setting.value , 'dashicons' ];

		 for (var i = 0; i < spans.length; i++)
		 {
			   var spanElement = document.createElement('span');
				 if (spans[i] == 'dashicons')
				 {
					  spanElement.classList.add('dashicons', 'dashicons-remove');
						spanElement.addEventListener('click', this.RemoveExclusionEvent.bind(this));
				 }
				 else {
				 	 spanElement.textContent = spans[i];
				 }
				 newElement.appendChild(spanElement);
		 }

		 listElement.appendChild(newElement);

		 var noItemsItem = document.querySelector('.exclude-list .no-exclusion-item');
		 if (noItemsItem !== null)
	 	 {
			  noItemsItem.classList.add('not-visible');
		 }

		 this.resetExclusionInputs();

}

this.NewExclusionToggleSizeOption = function(target)
{
	 	var sizeOptionRange = document.querySelector('.new-exclusion .size-option-range');
		var sizeOptionExact = document.querySelector('.new-exclusion .size-option-exact');

		if (true === target.checked)
		{
			 sizeOptionRange.classList.add('not-visible');
			 sizeOptionExact.classList.remove('not-visible');
		}
		else {
			sizeOptionRange.classList.remove('not-visible');
			sizeOptionExact.classList.add('not-visible');

		}
}

this.resetExclusionInputs = function()
{
	var typeOption = document.querySelector('.new-exclusion select[name="exclusion-type"]');
	var valueOption = document.querySelector('.new-exclusion input[name="exclusion-value"]');
	var applyOption = document.querySelector('.new-exclusion select[name="apply-select"]');

	typeOption.selectedIndex = 0;
	valueOption.value = '';
	applyOption.selectedIndex = 0;

	var ev = new CustomEvent('change');
	typeOption.dispatchEvent(ev);
	applyOption.dispatchEvent(ev);

}

this.RemoveExclusionEvent = function(event)
{
		 var element = event.target;
		 element.parentElement.remove();
}

 	this.Init();
} // SPSettings


document.addEventListener("DOMContentLoaded", function(){
	  var s = new ShortPixelSettings();
});
