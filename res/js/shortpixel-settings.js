'use strict'

// New Class for Settings Section.
var ShortPixelSettings = function()
{

	 var menu_tabs = {};
	 var current_tab = '';

	 this.Init = function()
	 {
			this.InitActions();
			this.SaveOnKey();
	 }

	this.InitActions = function()
	{
			var self = this;

			this.InitToggle();
			this.InitExclusions();
			this.InitWarnings();
			this.InitMenu();

			// Modals
			var modals = document.querySelectorAll('[data-action="open-modal"]');
			modals.forEach(function (modal, index)
			{
					modal.addEventListener('click', self.OpenModalEvent.bind(self));
			});

	}

	this.InitToggle = function()
	{
		var toggles = document.querySelectorAll('[data-toggle]');
		var self = this;

		toggles.forEach(function (toggle, index)
		{
				toggle.addEventListener('change', self.DoToggleActionEvent.bind(self));

				var evInit = new CustomEvent('change',  {detail : { init: true }} );
				toggle.dispatchEvent(evInit);
		});

	}

	this.InitExclusions = function()
	{
		 	var self = this;
			// Events for the New Exclusion dialog
			var newExclusionInputs = document.querySelectorAll('.new-exclusion select, .new-exclusion input, .new-exclusion button, input[name="removeExclusion"], button[name="cancelEditExclusion"]');

			newExclusionInputs.forEach(function (input)
			{
					switch (input.name)
					{
							case 'addExclusion':
							case 'removeExclusion':
							case 'cancelEditExclusion':
							case 'updateExclusion':
								var eventType = 'click';
							break;
							default:
								var eventType = 'change';
							break;
					}

					input.addEventListener(eventType, self.NewExclusionUpdateEvent.bind(self));
			});

			 var exclusionItems = document.querySelectorAll('.exclude-list li');
			 exclusionItems.forEach(function (input) {
					if (false == input.classList.contains('no-exclusion-item'))
					{
						input.addEventListener('click', self.NewExclusionShowInterfaceEvent.bind(self));
					}
			});


			var addNewExclusionButton = document.querySelector('.new-exclusion-button');
			if (addNewExclusionButton !== null)
			{
				addNewExclusionButton.addEventListener('click', this.NewExclusionShowInterfaceEvent.bind(this));
			}

			var size_select = new ShiftSelect('input[name^="excludeSizes[]"]');

			var compressionRadios = document.querySelectorAll('.shortpixel-compression-options input[type="radio"]');
			for (var i = 0; i < compressionRadios.length; i++)
			{
				 compressionRadios[i].addEventListener('change', this.CompressionTypeChangeEvent.bind(this));
			}
	}

	this.InitWarnings = function()
	{
		var self = this;

		var ifAllChecked = (elements, mode) =>
		{
			 	var checked = ('reverse' == mode) ? false : true ;

			 for (var i = 0; i < elements.length; i++)
			 {
				  if (elements[i].checked === false)
					{
						 checked = ('reverse' == mode) ? true : false;

						 return checked;
					}
			 }
			 return checked;
		}

		var updateShowWarning = (elements, warning, mode) => {
				for(var i = 0; i < elements.length; i++)
				{
					 elements[i].addEventListener('change', function (event)
					 {
						 event.preventDefault();
						 if (ifAllChecked(elements, mode))
						 {
								self.ShowElement(warning)
						 }
						 else {
								self.HideElement(warning);
						 }
					 });
				}
		}

		var remove_elements = document.querySelectorAll('input[name="removeExif"], input[name="png2jpg"]');
		var warning = document.querySelector('.exif-warning');

		updateShowWarning(remove_elements, warning);

		var elements = document.querySelectorAll('input[name="backupImages"]');
  	var warning =  document.querySelector('#backup-warning');

		updateShowWarning(elements, warning, 'reverse');

		var elements = document.querySelectorAll('input[name="offload-active"], input[name="useSmartcrop"]');
		var warning = document.querySelector('#smartcrop-warning');

		updateShowWarning(elements, warning);

	}

	this.InitMenu = function()
	{
		  var menu_elements = document.querySelectorAll('menu ul li a');

			for (var i = 0; i < menu_elements.length; i++)
			{
				  menu_elements[i].addEventListener('click', this.SwitchMenuTabEvent);
			}

			var tab_elements = document.querySelectorAll('[data-tab]');
			for (var i = 0; i < tab_elements.length; i++)
			{
					var name = tab_elements[i].dataset.part;
					this.menu_tabs[name] = tab_elements[i];
			}

			var params = new URLSearchParams(uri);
			if (params.has('part'))
			{
				 var part = params.get('part');
				 var event = new CustomEvent('click', { detail : {tabName: tab, section: section }});
				 window.dispatchEvent(event);
			}

	}

	this.SwitchMenuTabEvent = function(event)
	{
		 event.preventDefault();

		 var targetLink = event.target;
		 var uri = targetLink.href;
		 var displayPartEl = document.querySelector('input[name="display_part"]');
		 var current_tab = displayPartEl.value;

	//	 var oldMenuItem = document.querySelector('active');

		 var params = new URLSearchParams(uri);
		 var new_tab = params.get('part');

		 // If same, do nothing.
		 if (current_tab == new_tab)
		 {
			  return;
		 }

		// oldMenuItem.classList.remove('active');
		 targetLink.classList.add('active');

     // Update Uri

	   if (uri.indexOf("?") > 0) {
	       //var clean_uri = uri.substring(0, uri.indexOf("?"));
	       //clean_uri += '?' + jQuery.param({'page':'wp-shortpixel-settings', 'part': tab});
	       window.history.replaceState({}, document.title, uri);
	   }



		 var event = new CustomEvent('shortpixel.ui.settingsTabLoad', { detail : {tabName: tab, section: section }});
		 window.dispatchEvent(event);

	}

	// Elements with data-toggle active
	this.DoToggleActionEvent = function(event)
	{
			event.preventDefault();

			var checkbox = event.target;
		//	checkbox.disabled = true;
			var field_id = checkbox.getAttribute('data-toggle');
			var target = document.getElementById(field_id);
			// Allow multiple elements to be toggled, which will not work with id. In due time all should be transferred to use class-based toggle
			var targetClasses = document.querySelectorAll('.' + field_id);

			if (checkbox.type === 'radio')
			{

			}

		  if (typeof checkbox.dataset.toggleReverse !== 'undefined')
			{
				var checked = ! checkbox.checked;
			}
			else {
				var checked = checkbox.checked;
			}

			if (target === null)
			{
				 console.error('Target element ID not found', checkbox, field_id);
				 return false;
			}

			var show = false;
			if (checked)
			{
				show = true;
			}

			if (target !== null)
			{
				 if (show)
				 {
				 	this.ShowElement(target);
				}
				else {
					 this.HideElement(target);
				}
			}

		 	for (var i = 0; i < targetClasses.length; i++)
			{
				  if (show)
					{
						this.ShowElement(targetClasses[i]);
					}
					else {
						this.HideElement(targetClasses[i]);
					}
			}

			//checkbox.disabled = false;
	}

	this.ShowElement = function (elem) {

		elem.classList.add('is-visible'); // Make the element visible

		// Once the transition is complete, remove the inline max-height so the content can scale responsively
		window.setTimeout(function () {
				elem.style.opacity = 1;
		}, 150);

};

// Hide an element
this.HideElement = function (elem) {

	elem.style.opacity = 0;
		// When the transition is complete, hide it
		window.setTimeout(function () {
			elem.classList.remove('is-visible');

		}, 300);
};


this.OpenModalEvent = function(elem)
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
	 this.ResetExclusionInputs();
	 event.preventDefault();

	 var element = document.querySelector('.new-exclusion');
	 element.classList.remove('not-visible', 'hidden');

	 var cancelButton = document.querySelector('.new-exclusion .button-actions button[name="cancelEditExclusion"]');
	 cancelButton.classList.remove('not-visible', 'hidden');

	 var updateButton = document.querySelector('.new-exclusion .button-actions button[name="updateExclusion"]');

	 if (event.target.name == 'addNewExclusion')
	 {
		  var mode = 'new';
			var id = 'new';
			var title = document.querySelector('.new-exclusion h3.new-title');
			var button = document.querySelector('.new-exclusion .button-actions button[name="addExclusion"]');
	 }
	 else {
	 	  var mode = 'edit';

			if (event.target.id)
			{
				 var id = event.target.id;
				 var parent = event.target;
			}
			else {
				 var id = event.target.parentElement.id;
				 var parent = event.target.parentElement;
			}
			var title = document.querySelector('.new-exclusion h3.edit-title');
			var button = document.querySelector('.new-exclusion .button-actions button[name="removeExclusion"]');
			var input = document.querySelector('.new-exclusion input[name="edit-exclusion"]')

			updateButton.classList.remove('not-visible', 'hidden');

			input.value = id;
			var dataElement = parent.querySelector('input').value;
			var data = JSON.parse(dataElement);
			this.ReadWriteExclusionForm(data)

	 }

 	 title.classList.remove('not-visible', 'hidden');
	 button.classList.remove('not-visible', 'hidden');

}

//** When compressiontype changes, also update the information
this.CompressionTypeChangeEvent = function(event)
{
	  var target = event.target;
		var className = target.className;

    var elements = document.querySelectorAll('.shortpixel-compression .settings-info');
		for (var i = 0; i < elements.length; i++)
		{
			  var element = elements[i];
				element.style.display = 'none';
				if (element.classList.contains(className))
				{
					 element.style.display = 'inline-block';
				}
		}

}

this.HideExclusionInterface = function()
{
	var element = document.querySelector('.new-exclusion');
	element.classList.add('not-visible');

}

// EXCLUSIONS
this.NewExclusionUpdateEvent = function(event)
{
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
		 case 'removeExclusion':
		 	 this.RemoveExclusion(target);
		 break;
		 case 'cancelEditExclusion':
		 	 this.HideExclusionInterface();
		 break;
		 case 'updateExclusion':
		 	 this.UpdateExclusion();
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


this.ReadWriteExclusionForm = function(setting)
{
	 	// compile all inputs to a json encoded string to add to UX
	 	 if (null === setting || typeof setting === 'undefined')
		 {
			 var setting = {
				 'type' : '',
				 'value' :  '',
				 'apply' : '',
			 };

			 var mode = 'read';
		 }
		 else {
		 	 var mode = 'write';
		 }

		 var strings = {};

		 var typeOption = document.querySelector('.new-exclusion select[name="exclusion-type"]');
		 var valueOption = document.querySelector('.new-exclusion input[name="exclusion-value"]');
		 var applyOption = document.querySelector('.new-exclusion select[name="apply-select"]');
		 var regexOption = document.querySelector('.new-exclusion input[name="exclusion-regex"]');

		 if ('read' === mode)
	 	 {
		 	setting.type = typeOption.value;
		 	setting.value = valueOption.value;
		 	setting.apply = applyOption.value;

			strings.type = typeOption.options[typeOption.selectedIndex].innerText;
			strings.apply = applyOption.options[applyOption.selectedIndex].innerText;
		}
		else {
			if (setting.type.indexOf('regex') != -1)
			{
				 typeOption.value = setting.type.replace('regex-', '');
			}
			else {
				typeOption.value = setting.type;
			}
			valueOption.value = setting.value;
			applyOption.value = setting.apply;
		}

		 // When selected thumbnails option is selected, add the thumbnails to the list.
		 if ('selected-thumbs' == applyOption.value)
		 {
			  var thumbOption = document.querySelector('.new-exclusion select[name="thumbnail-select"]');
				var thumblist  = [];
				if ('read' === mode)
				{
					for(var i =0; i < thumbOption.selectedOptions.length; i++)
					{
						 thumblist.push(thumbOption.selectedOptions[i].value);
					}
				setting.thumblist = thumblist;
				}
				else if ('write' === mode){
					 for (var i = 0; i < thumbOption.options.length; i++)
				 	 {
						 	if (setting.thumblist.indexOf(thumbOption[i].value) != -1)
							{
								 thumbOption[i].selected = true;
							}
				 	 }

					 this.NewExclusionUpdateThumbType(applyOption);
				}
		 }

		 // Check for regular expression active on certain types
		 if ('read' === mode && true === regexOption.checked && (typeOption.value == 'name' || typeOption.value == 'path'))
		 {
			  setting.type = 'regex-' + setting.type;
		 }
		 else if ('write' === mode)
		 {
			   if (setting.type.indexOf('regex') != -1)
				 {
					  regexOption.checked = true;
				 }
		 }


		 // Options for size setting
		 if ('size' === setting.type)
		 {
			 var exactOption = document.querySelector('.new-exclusion input[name="exclusion-exactsize"]');

			 var width = document.querySelector('.new-exclusion input[name="exclusion-width"]');
			 var height = document.querySelector('.new-exclusion input[name="exclusion-height"]');

			 var minwidth = document.querySelector('.new-exclusion input[name="exclusion-minwidth"]');
			 var maxwidth = document.querySelector('.new-exclusion input[name="exclusion-maxwidth"]');
			 var minheight = document.querySelector('.new-exclusion input[name="exclusion-minheight"]');
			 var maxheight = document.querySelector('.new-exclusion input[name="exclusion-maxheight"]');


			 if ('read' === mode)
			 {
				 if (true === exactOption.checked)
				 {
						 setting.value = width.value + 'x' + height.value;
				 }
				 else {
					 setting.value = minwidth.value + '-' + maxwidth.value + 'x' + minheight.value + '-' + maxheight.value;
				 }
			 }
			 else if ('write' === mode)
			 {
				 	var value = setting.value;
					var split = value.split(/(x|×|X)/);

					if (value.indexOf('-') === -1)
					{
							exactOption.checked = true;
							width.value = split[0]; // in this split 1 is the X symbol
							height.value = split[2];
					}
					else {
						  var widths = split[0].split('-'); // split the split for widths
							var heights = split[2].split('-');

							minwidth.value = widths[0];
							maxwidth.value = widths[1];

							minheight.value = heights[0];
							maxheight.value = heights[1];
					}

					this.NewExclusionUpdateType(typeOption)
					this.NewExclusionToggleSizeOption(exactOption);
			 }
		 }
		 if ('read' === mode)
		 {
		 	 return [setting, strings];
		 }
}

this.NewExclusionButtonAdd = function(element)
{
		 var result = this.ReadWriteExclusionForm(null);
		 var setting = result[0];
		 var strings = result[1];

		 var listElement = document.querySelector('.exclude-list');
		 var newElement = document.createElement('li');
		 var inputElement = document.createElement('input');

		 var newIndexInput = document.getElementById('new-exclusion-index');
		 var newIndex = parseInt(newIndexInput.value) + 1;
		 newIndexInput.value = newIndex;

		 newElement.id = 'exclude-' + newIndex;
		 newElement.addEventListener('click', this.NewExclusionShowInterfaceEvent.bind(this));

		 inputElement.type = 'hidden';
		 inputElement.name = 'exclusions[]';
		 inputElement.value = JSON.stringify(setting);


		 newElement.appendChild(inputElement);

		 var spans = [strings.type, setting.value, strings.apply];

		 for (var i = 0; i < spans.length; i++)
		 {
			   var spanElement = document.createElement('span');
			 	 spanElement.textContent = spans[i];

				 newElement.appendChild(spanElement);
		 }

		 listElement.appendChild(newElement);

		 var noItemsItem = document.querySelector('.exclude-list .no-exclusion-item');
		 if (noItemsItem !== null)
	 	 {
			  noItemsItem.classList.add('not-visible');
		 }

		 this.ResetExclusionInputs();
		 this.HideExclusionInterface();
		 this.ShowExclusionSaveWarning();

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

this.ResetExclusionInputs = function()
{
	var typeOption = document.querySelector('.new-exclusion select[name="exclusion-type"]');
	var valueOption = document.querySelector('.new-exclusion input[name="exclusion-value"]');
	var applyOption = document.querySelector('.new-exclusion select[name="apply-select"]');

 	var inputs = document.querySelectorAll('.new-exclusion input, .new-exclusion select');
	for (var i = 0; i < inputs.length; i++)
	{
			var input = inputs[i];
			if (input.tagName == 'SELECT')
			{
				 input.selectedIndex = 0;
			}
			else if (input.type == 'checkbox')
			{
				 input.checked = false;
			}
			else {
					input.value = '';
			}
	}

	var ev = new CustomEvent('change');
	typeOption.dispatchEvent(ev);
	applyOption.dispatchEvent(ev);

	// reset title and buttons.
	var titles = document.querySelectorAll('.new-exclusion h3');
	var buttons = document.querySelectorAll('.new-exclusion .button-actions button');

	for(var i = 0; i < titles.length; i++)
	{
		 titles[i].classList.add('not-visible', 'hidden');
	}

	for (var i = 0; i < buttons.length; i++)
	{
		 buttons[i].classList.add('not-visible', 'hidden');
	}

	var exactOption = document.querySelector('.new-exclusion input[name="exclusion-exactsize"]');
	exactOption.checked = false

}

this.UpdateExclusion = function()
{
	var id = document.querySelector('.new-exclusion input[name="edit-exclusion"]');
	var result = this.ReadWriteExclusionForm();
	var setting = result[0];
	var strings = result[1];

	if (id)
	{
			var element = document.querySelector('.exclude-list #' +id.value + ' input');
			var liElement = document.querySelector('.exclude-list #' +id.value);

			var removeChildren = [];
			if (null !== element)
			{
				 element.value = JSON.stringify(setting);

				 // Can't directly remove children, because it messes with the collection index.
				 Array.from(liElement.children).forEach ( function (child, index){
				 if (child.tagName == 'SPAN')
					 {
						 removeChildren.push(child)
						}
				 });

				 for(var i = 0; i < removeChildren.length; i++ )
				 {
					  removeChildren[i].remove();
				 }

				 var spans = [strings.type, setting.value, strings.apply];
				 for (var j = 0; j < spans.length; j++)
				 {
						 var spanElement = document.createElement('span');
						 spanElement.textContent = spans[j];
						 liElement.appendChild(spanElement);
				 }
			}
	}

	this.HideExclusionInterface();
	this.ShowExclusionSaveWarning();

}

this.ShowExclusionSaveWarning = function()
{
	  var reminder = document.querySelector('.exclusion-save-reminder');
		if (reminder)
		{
			 reminder.classList.remove('hidden');
		}
}

this.RemoveExclusion = function()
{
		 var id = document.querySelector('.new-exclusion input[name="edit-exclusion"]');
		 if (id)
		 {
			   var element = document.querySelector('.exclude-list #' +id.value);
				 if (null !== element)
				 {
					  element.remove();
				 }
		 }

		 this.HideExclusionInterface();
		 this.ShowExclusionSaveWarning();

}

 	this.Init();
} // SPSettings


document.addEventListener("DOMContentLoaded", function(){
	  var s = new ShortPixelSettings();
});
