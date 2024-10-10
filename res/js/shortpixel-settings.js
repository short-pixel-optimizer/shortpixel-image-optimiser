'use strict'

// New Class for Settings Section.
class ShortPixelSettings
{

	 tab_elements = {};
	 menu_elements = [];
	 current_tab; // string, the name of the tab.
	 current_mode = 'simple';
	 root; // top of settings page.
	 strings;

	 Init()
	 {
		  this.root = document.querySelector('.wrap.is-shortpixel-settings-page');


			this.InitActions();
			this.InitAjaxForm();
			this.SaveOnKey();

			// @todo if this fires directly, probably missed by onboarding. Need some other way
			var ev = new CustomEvent('shortpixel.settings.loaded');
			document.dispatchEvent(ev);
	 }

	InitActions()
	{
		  console.time('init');
			var self = this;
			this.strings = settings_strings;

			console.time('inits');
			this.InitToggle();
			this.InitExclusions();
			console.timeLog('inits', 'afterExcl');
			this.InitWarnings();
			console.timeLog('inits', 'afterwarn');
			this.InitMenu();
			this.InitModeSwitcher();
			console.timeEnd('inits');



			// Modals
			var modals = this.root.querySelectorAll('[data-action="open-modal"]');
			modals.forEach(function (modal, index)
			{
					modal.addEventListener('click', self.OpenModalEvent.bind(self));
			});
			console.timeEnd('init');
	}

	InitToggle()
	{
		var toggles = this.root.querySelectorAll('[data-toggle]');
		var self = this;

		toggles.forEach(function (toggle, index)
		{
				toggle.addEventListener('change', self.DoToggleActionEvent.bind(self));

				var evInit = new CustomEvent('change',  {detail : { init: true }} );
				toggle.dispatchEvent(evInit);
		});

	}

	InitAjaxForm()
	{
			var forms = this.root.querySelectorAll('form');
			for (var i = 0; i < forms.length; i++)
			{
				 forms[i].addEventListener('submit', this.FormSendEvent);
			}

	}

	InitExclusions()
	{
		 	var self = this;
			// Events for the New Exclusion dialog
			var newExclusionInputs = this.root.querySelectorAll('.new-exclusion select, .new-exclusion input, .new-exclusion button, input[name="removeExclusion"], button[name="cancelEditExclusion"]');

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

			 var exclusionItems = this.root.querySelectorAll('.exclude-list li');
			 exclusionItems.forEach(function (input) {
					if (false == input.classList.contains('no-exclusion-item'))
					{
						input.addEventListener('click', self.NewExclusionShowInterfaceEvent.bind(self));
					}
			});


			var addNewExclusionButton = this.root.querySelector('.new-exclusion-button');
			if (addNewExclusionButton !== null)
			{
				addNewExclusionButton.addEventListener('click', this.NewExclusionShowInterfaceEvent.bind(this));
			}

			var size_select = new ShiftSelect('input[name^="excludeSizes[]"]');

			var compressionRadios = this.root.querySelectorAll('.shortpixel-compression-options input[type="radio"]');
			for (var i = 0; i < compressionRadios.length; i++)
			{
				 compressionRadios[i].addEventListener('change', this.CompressionTypeChangeEvent.bind(this));
			}
	}

	InitWarnings()
	{
		var self = this;

		var checkMatches = (elements, checks) =>
		{
				var allMatches = true;
				var someMatch = false;
				var matches = [];

				for (var i = 0; i < elements.length; i++)
				{
					  if (elements[i].matches(checks[i]))
						{
							 someMatch = true;
							 matches.push(elements[i]);
						}
						else {
							 allMatches = false;
						}
				}

				return { allMatches : allMatches, someMatch: someMatch, matches: matches}

		}


		var showHideFunctions = {
				'onTrue' : this.ShowElement,
				'onFalse': this.HideElement
		};

		var updateShowWarning = (args) => {

				var defaults = {
						functions : showHideFunctions,
				//		checkFunction: ifAllChecked,
				}

				args = { ...defaults, ...args };

				if (typeof args.elements === 'undefined')
				{
					 console.error('No elements in updateShowWarning', args);
					 return false;
				}

				if (typeof args.warnings === 'undefined')
				{
					 console.error('Function needs one or more warning elements', args);
					 return false;
				}

				if (typeof args.checks === 'undefined' || args.elements.length !== args.checks.length)
				{
					 console.error('Checks must be provided and same length as elements', args);
					 return false;
				}

				for(var i = 0; i < args.elements.length; i++)
				{
					 args.elements[i].addEventListener('change', function (event)
					 {
						 event.preventDefault();
						 var matches = checkMatches(args.elements, args.checks);

						 if (true === matches.allMatches)
						 {
							args.functions.onTrue.call(this, args.warnings, matches);
						 }
						 else if (false === matches.someMatch)
						 {
						  args.functions.onFalse.call(this, args.warnings, matches);
						 }
						 else
						 {
							  if (typeof args.functions.onAny !== 'undefined')
								{
									 args.functions.onAny.call(this, args.warnings, matches);
								}
								else {
									 args.functions.onFalse.call(this, args.warnings, matches);
								}
						 }
					 }.bind(this));
				} // set the eventlisteners

				var matches = checkMatches(args.elements, args.checks);
				if (true == matches.allMatches)
				{
					args.functions.onTrue.call(this, args.warnings, matches);
				}
				else if (typeof args.functions.onAny !== 'undefined')
				{
					 args.functions.onAny.call(this, args.warnings, matches);
				}

		}

		var root = this.root;

	 	var el = root.querySelector('input[name="removeExif"]');
		var remove_elements = root.querySelectorAll('input[name="removeExif"], input[name="png2jpg"]');
		var checks = [':checked', ':not(:checked)'];


		// General checks //
		var warning = root.querySelectorAll('.exif-warning');
		updateShowWarning({ elements: remove_elements, warnings: warning, checks: checks});

		var elements = root.querySelectorAll('input[name="backupImages"]');
  	var warning =  root.querySelectorAll('#backup-warning');
		var checks = [':not(:checked)'];
		updateShowWarning({elements: elements, warnings: warning, checks: checks});

		var elements = root.querySelectorAll('input[name="offload-active"], input[name="useSmartcrop"]');
		var warning = root.querySelectorAll('#smartcrop-warning');
		var checks = [':checked', ':checked'];
		updateShowWarning({elements: elements, warnings: warning, checks: checks});

		var elements = root.querySelectorAll('input[name="heavy_features"], input[name="optimizeUnlisted"]');
		var warning = root.querySelectorAll('.heavy-feature-virtual.unlisted');
		var checks = [':checked', ':checked'];
		updateShowWarning({elements: elements, warnings: warning, checks: checks});

		var elements = root.querySelectorAll('input[name="heavy_features"], input[name="optimizeRetina"]');
		var warning = root.querySelectorAll('.heavy-feature-virtual.retina');
		var checks = [':checked', ':checked'];
		updateShowWarning({elements: elements, warnings: warning, checks: checks});

		// Checks for the dashboard boxes
		// What can be send back match wise, can be 'allmatches' for red and 'anymatches' for yellow warning and let the dashboard function figure it out how to display that ( and which text? )
		var dashboardFunctions = {
 			  'onTrue' : this.DashBoardWarningEvent,
				'onFalse' : this.DashBoardWarningEvent,
				'onAny': this.DashBoardWarningEvent,
		}

		// First box (optimize) dashboard warning
		var elements = root.querySelectorAll('input[name="autoMediaLibrary"], input[name="backupImages"], input[name="doBackgroundProcess"]');
		var warnings = root.querySelectorAll('.panel.dashboard-optimize');
		var checks = [':not(:checked)', ':not(:checked)', ':not(:checked)'];
		updateShowWarning({elements: elements, warnings: warnings, checks: checks, functions: dashboardFunctions});

		var elements = root.querySelectorAll('input[name="createWebp"],input[name="deliverWebp"]');
		var warnings = root.querySelectorAll('.panel.dashboard-webp');
		var checks = [':not(:checked)', ':not(:checked)'];

		updateShowWarning({elements: elements, warnings: warnings, checks: checks, functions: dashboardFunctions});

	}

	InitMenu()
	{
		  var menu_elements = this.root.querySelectorAll('menu ul li a');
			this.menu_elements = menu_elements;

			// Bind change event to all menu items.
			for (var i = 0; i < menu_elements.length; i++)
			{
					var element = menu_elements[i];
				  element.addEventListener('click', this.SwitchMenuTabEvent.bind(this));

			}

			// Load all menu tabs
			var tab_elements = this.root.querySelectorAll('[data-part]');
			for (var i = 0; i < tab_elements.length; i++)
			{
					var name = tab_elements[i].dataset.part;
					this.tab_elements[name] = tab_elements[i];
			}

			// Discover current tab
			var displayPartEl = this.root.querySelector('input[name="display_part"]');
			this.current_tab = displayPartEl.value;

			/* Not sure why this is here, since display_part from html already sets the display part to the query string if there.
			var uri = window.location.href.toString();
			var params = new URLSearchParams(uri);
			if (params.has('part'))
			{

				 var part = params.get('part');
				 var target = this.root.querySelector('menu [data-link="' + part + '"]');

				 if (target === null)
				 {
					  console.error('Tab ' +  part + ' not found');
						return;
				 }

				 var event = new CustomEvent('click');
				 target.dispatchEvent(event);
			} */


	}

	InitModeSwitcher()
	{
      var switcher = document.getElementById('viewmode-toggle');
			if (null == switcher)
			{
						return;
			}

			if (this.root.classList.contains('simple'))
			{
				 this.current_mode = 'simple';
			}
			else {
				 this.current_mode = 'advanced';
			}

			switcher.addEventListener('click', this.SwitchViewModeEvent.bind(this));
	}

  SwitchViewModeEvent(event)
	{
		var new_mode = (this.current_mode == 'simple') ? 'advanced' : 'simple';
		var data = {};
		data.type = 'settings';
		data.screen_action = 'settings/changemode';
		data.new_mode = new_mode;

		window.ShortPixelProcessor.AjaxRequest(data);

	  this.root.classList.remove('simple','advanced');
		this.root.classList.add(new_mode);

		this.current_mode = new_mode;
	}

	SwitchMenuTabEvent(event)
	{
		 event.preventDefault();

		 var targetLink = event.target;
		 var uri = targetLink.href;

		 var current_tab = this.current_tab;
		 var params = new URLSearchParams(uri);
		 var new_tab = params.get('part');


		 // If same, do nothing.
		 if (current_tab == new_tab)
		 {
			  return;
		 }

		 var newTabEl = this.tab_elements[new_tab];
		 if (typeof newTabEl !== 'undefined')
		 {
		 	newTabEl.classList.add('active');
		 }

		 var currentTabEl = this.tab_elements[current_tab];
		 // Happens when no active tab ( ie just started )
		 if (typeof currentTabEl !== 'undefined')
		 {
		 	currentTabEl.classList.remove('active');
		 }

		 for (var i = 0; i < this.menu_elements.length; i++)
		 {
			  if (this.menu_elements[i].classList.contains('active'))
				{
			  	this.menu_elements[i].classList.remove('active');
				}
		 }
		 // Add active to the new tab.
		 targetLink.classList.add('active');

		 this.current_tab = new_tab;
		 var displayPartEl = this.root.querySelector('input[name="display_part"]');
		 displayPartEl.value = new_tab;

     // Update Uri
	   if (uri.indexOf("?") > 0) {
	       window.history.replaceState({}, document.title, uri);
	   }

		 var section = ''; // #todo figure out what the idea of section was
		 var event = new CustomEvent('shortpixel.ui.settingsTabLoad', { detail : {tabName: new_tab, section: section }});
		 window.dispatchEvent(event);



	}

	// Elements with data-toggle active
	DoToggleActionEvent(event)
	{
			event.preventDefault();
			console.log('DoToggleActionEvent');

			var checkbox = event.target;
			var checkboxes = [];

			checkboxes.push(checkbox);
			var targets = [];

			if (target === null)
			{
				 console.error('Target element ID not found', checkbox, field_id);
				 return false;
			}

			// If radio, add all to the event.
			if (checkbox.type === 'radio')
			{
					var checkboxes = this.root.querySelectorAll('input[name="' + checkbox.name + '"]');
					console.log('Checkboxes, doing', checkboxes);

			}

			for (var i = 0; i < checkboxes.length; i++)
			{

					if (i > 100)
					{
						console.error('unclear loop');
						break;
					}
				  var checkbox = checkboxes[i];

					var field_id = checkbox.getAttribute('data-toggle');
					var target = document.getElementById(field_id);

					// Allow multiple elements to be toggled, which will not work with id. In due time all should be transferred to use class-based toggle
					var targetClasses = this.root.querySelectorAll('.' + field_id);

					if (targetClasses.length == 0)
					{
						 console.error('No Targetclasses. Old format!' + field_id);
					}

				  if (typeof checkbox.dataset.toggleReverse !== 'undefined')
					{
						var checked = ! checkbox.checked;
					}
					else {
						var checked = checkbox.checked;
					}


					var show = false;
					if (checked)
					{
						show = true;
					}

					console.log(checkbox, field_id, checked);

					if (target !== null)
					{
						 if (show)
						 {
						 	this.ShowElement([target]);
						}
						else {
							 this.HideElement([target]);
						}
					}

				 	for (var j = 0; j < targetClasses.length; j++)
					{
						  if (show)
							{
								this.ShowElement([targetClasses[i]]);
							}
							else {
								this.HideElement([targetClasses[i]]);
							}
					}
			} // for

	}

	ShowElement(elems) {

		for (var i = 0; i < elems.length; i++)
		{
			var element = elems[i];
			element.classList.add('is-visible'); // Make the element visible

			// Once the transition is complete, remove the inline max-height so the content can scale responsively
			window.setTimeout(function () {
					element.style.opacity = 1;
			}, 150);

		}

};

FormSendEvent(event)
{
	 event.preventDefault();
	 //console.log(event);

	 var form = event.target;
	 var formData = new FormData(event.target);

	 formData.append('ajaxSave', 'true');
	 formData.append('action', 'shortpixel_settingsRequest');
	 formData.append('screen_action', 'form_submit');
	 formData.append('form-nonce', formData.get('nonce'));
	 formData.set('nonce', ShortPixelProcessorData.nonce_settingsrequest);

	 console.log(formData);
	 //var data = [];
	// data.form = formdata;
	 //data.screen_action = 'form_submit';
	 //window.ShortPixelProcessor.SettingsRequest(data);

	 //const url = new URL(window.location.href);
	 const url = ShortPixel.AJAX_URL;

	 var response = fetch(url, {
  	method: 'POST',
  	body: formData
		}).then((response) => {
  		// do something with response here...
			console.log('response', response);
			if (response.ok)
			{
					let saveDialog = document.querySelector('.ajax-save-done');
					saveDialog.classList.add('show');

					response.json().then((json) => {
						console.log('json', json);
						if (json.notices)
						{
								var notice_count = saveDialog.querySelector('.notice_count');
								notice_count.textContent = json.notices.length;
						}
						if (json.display_notices)
						{
								let anchor = document.querySelector('.wp-header-end')
								for (let i = 0; i < json.display_notices.length; i++)
								{
									//	var node =
									//	anchor.parentNode.insertBefore(json.display_notices[i], anchor.nextSibling);
									anchor.insertAdjacentHTML('afterend', json.display_notices[i]);
								}
						}

					});


					window.setTimeout(function () {
						 saveDialog.classList.remove('show')
					}, 2000);
			}
		});



}

// Hide an element
HideElement(elems) {

	for (var i = 0; i < elems.length; i++)
	{
		var element = elems[i];
		element.style.opacity = 0;
			// When the transition is complete, hide it
			window.setTimeout(function () {
				element.classList.remove('is-visible');

			}, 300);
	}

};

DashBoardWarningEvent(warning, matches)
{
	 console.log(warning, matches);
	 var dashBox = warning[0];
	 var status = (true === matches.allMatches) ? 'alert' : (true === matches.someMatch) ? 'warning' : 'ok';

	 dashBox.classList.remove('ok', 'alert', 'warning');
	 dashBox.classList.add(status)

	 // Remove all status-lines ( rebuild )
	 var statusWrapper = dashBox.querySelector('.status-wrapper');
	 if (null === statusWrapper)
	 {
		  console.log('issue with statuswrapper');
			return;
	 }
 	 Array.from(statusWrapper.children).forEach(e => e.remove());

	 var statusIcon = document.createElement('i');
	 statusIcon.classList.add('shortpixel-icon', 'static-icon', status);

  if (matches.matches.length > 0)
	{
			for(var i = 0; i < matches.matches.length; i++ )
			{
				var input = matches.matches[i];
				var statusLine = document.createElement('span');
				statusLine.classList.add('status-line');

				if (input.dataset.dashboard)
				{

			 		statusLine.textContent = input.dataset.dashboard;
				}
				else {
				  var linestring = this.strings.dashboard_strings[status];
		 		  statusLine.textContent = linestring;
				}

				statusWrapper.appendChild(statusLine).appendChild(statusIcon.cloneNode());
//				statusWrapper.;
			}
		 	// Add Not ok status.
	}
	else {
		 // Add OK status
		 var statusLine = document.createElement('span');
		 statusLine.classList.add('status-line');
		 var linestring = this.strings.dashboard_strings[status];
		 statusLine.textContent = linestring;

		 statusWrapper.appendChild(statusLine).appendChild(statusIcon);
		 //statusWrapper.;
	}



/*	 dashBox.querySelector('.status-icon');
	 if (null !== statusIcon)
	 {
		  statusIcon.classList.remove('ok', 'warning', 'alert');
			statusIcon.classList.add(status);
	 } */



	/* var statusLine = dashBox.querySelector('.status-line');
	 if (null !== statusLine)
	 {
		  var linestring = this.strings.dashboard_strings[status];
		  statusLine.textContent = linestring;
	 } */


	 // Button display
	 var button = dashBox.querySelector('button');
	 if ('ok' === status)
	 {
			if (false === button.classList.contains('shortpixel-hide'))
			{
					button.classList.add('shortpixel-hide');
			}
	 }
	 else if (true === button.classList.contains('shortpixel-hide'))
	 {
		 		button.classList.remove('shortpixel-hide');
	 }

}


OpenModalEvent(elem)
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

CloseModal(elem)
{
	var shade = document.getElementById('spioSettingsModalShade');
	var modal = document.getElementById('spioSettingsModal');

	shade.style.display = 'none';
	modal.classList.add('spio-hide');

}

SendModal(elem)
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

ReceiveModal(elem)
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

SaveOnKey()
{

	var saveForm = document.getElementById('wp_shortpixel_options');
	if (saveForm === null)
		return false; // no form no save.


	 window.addEventListener('keydown', function(event) {

    if (! (event.key == 's' || event.key == 'S')  || ! event.ctrlKey)
		{
			return true;
		}

		let submitButton = document.getElementById('save');
		saveForm.requestSubmit(submitButton);
    event.preventDefault();
    return false;
	}.bind(this));
}

NewExclusionShowInterfaceEvent(event)
{
	 this.ResetExclusionInputs();
	 event.preventDefault();

	 var element = this.root.querySelector('.new-exclusion');
	 element.classList.remove('not-visible', 'hidden');

	 var cancelButton = this.root.querySelector('.new-exclusion .button-actions button[name="cancelEditExclusion"]');
	 cancelButton.classList.remove('not-visible', 'hidden');

	 var updateButton = this.root.querySelector('.new-exclusion .button-actions button[name="updateExclusion"]');

	 if (event.target.name == 'addNewExclusion')
	 {
		  var mode = 'new';
			var id = 'new';
			var title = this.root.querySelector('.new-exclusion h3.new-title');
			var button = this.root.querySelector('.new-exclusion .button-actions button[name="addExclusion"]');
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
			var title = this.root.querySelector('.new-exclusion h3.edit-title');
			var button = this.root.querySelector('.new-exclusion .button-actions button[name="removeExclusion"]');
			var input = this.root.querySelector('.new-exclusion input[name="edit-exclusion"]')

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
CompressionTypeChangeEvent(event)
{
	  var target = event.target;
		var className = target.className;

    var elements = this.root.querySelectorAll('.shortpixel-compression .settings-info');
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

HideExclusionInterface()
{
	var element = this.root.querySelector('.new-exclusion');
	element.classList.add('not-visible');

}

// EXCLUSIONS
NewExclusionUpdateEvent(event)
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

NewExclusionUpdateType(element)
{
	 	 var value = element.value;
		 var selected = element.options[element.selectedIndex];
		 var example = selected.dataset.example;
		 if ( typeof example == 'undefined')
		 {
			  example = '';
		 }

		 var valueOption = this.root.querySelector('.new-exclusion .value-option');
		 var sizeOption = this.root.querySelector('.new-exclusion .size-option');
		 var regexOption = this.root.querySelector('.regex-option');
		 var switchExactOption = this.root.querySelector('.exact-option');


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

		 var valueInput = this.root.querySelector('input[name="exclusion-value"]');
		 if (null !== valueInput)
		 {
			  valueInput.placeholder = example;
		 }
}

NewExclusionUpdateThumbType(element)
{
		 var value = element.value;

		 var thumbSelect = this.root.querySelector('select[name="thumbnail-select"]');

		 if (value == 'selected-thumbs')
		 {
			 	thumbSelect.classList.remove('not-visible');
		 }
		 else {
		 		thumbSelect.classList.add('not-visible');
		 }
}


ReadWriteExclusionForm(setting)
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

		 var typeOption = this.root.querySelector('.new-exclusion select[name="exclusion-type"]');
		 var valueOption = this.root.querySelector('.new-exclusion input[name="exclusion-value"]');
		 var applyOption = this.root.querySelector('.new-exclusion select[name="apply-select"]');
		 var regexOption = this.root.querySelector('.new-exclusion input[name="exclusion-regex"]');

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
			  var thumbOption = this.root.querySelector('.new-exclusion select[name="thumbnail-select"]');
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
			 var exactOption = this.root.querySelector('.new-exclusion input[name="exclusion-exactsize"]');

			 var width = this.root.querySelector('.new-exclusion input[name="exclusion-width"]');
			 var height = this.root.querySelector('.new-exclusion input[name="exclusion-height"]');

			 var minwidth = this.root.querySelector('.new-exclusion input[name="exclusion-minwidth"]');
			 var maxwidth = this.root.querySelector('.new-exclusion input[name="exclusion-maxwidth"]');
			 var minheight = this.root.querySelector('.new-exclusion input[name="exclusion-minheight"]');
			 var maxheight = this.root.querySelector('.new-exclusion input[name="exclusion-maxheight"]');


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

NewExclusionButtonAdd(element)
{
		 var result = this.ReadWriteExclusionForm(null);
		 var setting = result[0];
		 var strings = result[1];

		 var listElement = this.root.querySelector('.exclude-list');
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

		 var noItemsItem = this.root.querySelector('.exclude-list .no-exclusion-item');
		 if (noItemsItem !== null)
	 	 {
			  noItemsItem.classList.add('not-visible');
		 }

		 this.ResetExclusionInputs();
		 this.HideExclusionInterface();
		 this.ShowExclusionSaveWarning();

}

NewExclusionToggleSizeOption(target)
{
	 	var sizeOptionRange = this.root.querySelector('.new-exclusion .size-option-range');
		var sizeOptionExact = this.root.querySelector('.new-exclusion .size-option-exact');

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

ResetExclusionInputs()
{
	var typeOption = this.root.querySelector('.new-exclusion select[name="exclusion-type"]');
	var valueOption = this.root.querySelector('.new-exclusion input[name="exclusion-value"]');
	var applyOption = this.root.querySelector('.new-exclusion select[name="apply-select"]');

 	var inputs = this.root.querySelectorAll('.new-exclusion input, .new-exclusion select');
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
	var titles = this.root.querySelectorAll('.new-exclusion h3');
	var buttons = this.root.querySelectorAll('.new-exclusion .button-actions button');

	for(var i = 0; i < titles.length; i++)
	{
		 titles[i].classList.add('not-visible', 'hidden');
	}

	for (var i = 0; i < buttons.length; i++)
	{
		 buttons[i].classList.add('not-visible', 'hidden');
	}

	var exactOption = this.root.querySelector('.new-exclusion input[name="exclusion-exactsize"]');
	exactOption.checked = false

}

UpdateExclusion()
{
	var id = this.root.querySelector('.new-exclusion input[name="edit-exclusion"]');
	var result = this.ReadWriteExclusionForm();
	var setting = result[0];
	var strings = result[1];

	if (id)
	{
			var element = this.root.querySelector('.exclude-list #' +id.value + ' input');
			var liElement = this.root.querySelector('.exclude-list #' +id.value);

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
						 var spanElement = this.root.createElement('span');
						 spanElement.textContent = spans[j];
						 liElement.appendChild(spanElement);
				 }
			}
	}

	this.HideExclusionInterface();
	this.ShowExclusionSaveWarning();

}

ShowExclusionSaveWarning()
{
	  var reminder = this.root.querySelector('.exclusion-save-reminder');
		if (reminder)
		{
			 reminder.classList.remove('hidden');
		}
}

RemoveExclusion()
{
		 var id = this.root.querySelector('.new-exclusion input[name="edit-exclusion"]');
		 if (id)
		 {
			   var element = this.root.querySelector('.exclude-list #' +id.value);
				 if (null !== element)
				 {
					  element.remove();
				 }
		 }

		 this.HideExclusionInterface();
		 this.ShowExclusionSaveWarning();

}

} // SPSettings  class


document.addEventListener("DOMContentLoaded", function(){
	  var s = new ShortPixelSettings();
		s.Init();
});
