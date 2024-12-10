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
	 dashboard_status = {};
	 save_in_progress = false;

	 Init()
	 {
		  this.root = document.querySelector('.wrap.is-shortpixel-settings-page');

			this.InitActions();
			this.InitAjaxForm();
			this.SaveOnKey();

			var ev = new CustomEvent('shortpixel.settings.loaded', { detail: { 'root' : this.root, 'settings' : this }});
			document.dispatchEvent(ev);
	 }

	InitActions()
	{
			var self = this;
			this.strings = settings_strings;

			this.InitToggle();
			this.InitExclusions();
			this.InitWarnings();
			this.InitMenu();
			this.InitModeSwitcher();

			// Modals
			var modals = this.root.querySelectorAll('[data-action="open-modal"]');
			modals.forEach(function (modal, index)
			{
					modal.addEventListener('click', self.OpenModalEvent.bind(self));
			});
	}

	InitToggle()
	{
		var toggles = this.root.querySelectorAll('[data-toggle]');
		var self = this;

		toggles.forEach(function (toggle, index)
		{
				toggle.addEventListener('change', self.DoToggleActionEvent.bind(self));

				let evInit = new CustomEvent('change',  {detail : { init: true }} );
				toggle.dispatchEvent(evInit);
		});

		// This is all super unflexible, and would probably need to be changed with a new warning system, which could take multiple inputs.
		toggles = this.root.querySelectorAll('[data-exclude]');
		toggles.forEach(function(toggle,index)
		{
			  toggle.addEventListener('change', self.DoToggleExcludeEvent.bind(self));
		});

		toggles = this.root.querySelectorAll('[data-disable]');
		toggles.forEach(function (toggle, index)
		{
				toggle.addEventListener('change', self.DoToggleDisableEvent.bind(self));

				let evInit = new CustomEvent('change',  {detail : { init: true }} );
				toggle.dispatchEvent(evInit);
		});


		let hideWarnings = this.root.querySelectorAll('[data-hidewarnings]');
		hideWarnings.forEach(function(hide, index)
		{
			 hide.addEventListener('change', self.DoToggleHideWarningEvent.bind(self));
		});


		// ApiKeyField toggle
		var keyField = this.root.querySelector('.apifield i.eye');
		keyField.addEventListener('click', self.ToggleApiFieldEvent.bind(self));


	}

	InitAjaxForm()
	{
			var forms = this.root.querySelectorAll('form');
			for (var i = 0; i < forms.length; i++)
			{
				 forms[i].addEventListener('submit', this.FormSendEvent.bind(this));
			}

	}

	InitExclusions()
	{
		 	var self = this;
			// Events for the New Exclusion dialog
			var newExclusionInputs = this.root.querySelectorAll('.new-exclusion select, .new-exclusion input, .new-exclusion button, button[name="cancelEditExclusion"]');

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

			 var exclusionItems = this.root.querySelectorAll('.exclude-list li i.edit, .exclude-list li');
			 exclusionItems.forEach(function (input) {
					if (false == input.classList.contains('no-exclusion-item'))
					{
						input.addEventListener('click', self.NewExclusionShowInterfaceEvent.bind(self));
					}
			});

			var exclusionItems = this.root.querySelectorAll('.exclude-list li i.remove');
			exclusionItems.forEach(function (input) {
				 if (false == input.classList.contains('no-exclusion-item'))
				 {
					 input.addEventListener('click', self.RemoveExclusion.bind(self));
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
							args.functions.onTrue.call(this, args.warnings, matches, args.elements);
						 }
						 else if (false === matches.someMatch)
						 {
							args.functions.onFalse.call(this, args.warnings, matches, args.elements);
						 }
						 else
						 {
							  if (typeof args.functions.onAny !== 'undefined')
								{
									 args.functions.onAny.call(this, args.warnings, matches, args.elements);
								}
								else {
									 args.functions.onFalse.call(this, args.warnings, matches, args.elements);
								}
						 }
					 }.bind(this));
				} // set the eventlisteners

				var matches = checkMatches(args.elements, args.checks);
				if (true == matches.allMatches)
				{
					args.functions.onTrue.call(this, args.warnings, matches, args.elements);
				}
				else if (typeof args.functions.onAny !== 'undefined')
				{
					 args.functions.onAny.call(this, args.warnings, matches, args.elements);
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

		var elements = root.querySelectorAll('input[name="createWebp"],input[name="createAvif"],input[name="deliverWebp"],input[name="useCDN"]');
		var warnings = root.querySelectorAll('.panel.dashboard-webp');
		var checks = [':checked', ':checked', ':checked', ':checked'];

		var CDNFunctions = {
				'onTrue' : this.CDNCheckWarningEvent,
				'onFalse' : this.CDNCheckWarningEvent,
				'onAny': this.CDNCheckWarningEvent,
		};

		updateShowWarning({elements: elements, warnings: warnings, checks: checks, functions: CDNFunctions});

	}

	InitMenu()
	{
		  //var menu_elements = this.root.querySelectorAll('menu ul li a');
			var menu_elements = this.root.querySelectorAll('[data-menu-link]');
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

			// responsive menu
			var mobileToggle = this.root.querySelector('.mobile-menu input');
			mobileToggle.addEventListener('change', this.ShowMobileMenuEvent.bind(this));
	}

	InitModeSwitcher()
	{
      var switcher = document.getElementById('viewmode-toggles');
			var checkbox = switcher.querySelector('input[type="checkbox"]');

			if (null == switcher)
			{
						return;
			}
			if (this.root.classList.contains('advanced') || checkbox.checked)
			{
				 checkbox.checked = true;
				 this.current_mode = 'advanced';
			}
			else
			{
				 checkbox.checked = false;
				 this.current_mode = 'simple';
			}
			switcher.addEventListener('change', this.SwitchViewModeEvent.bind(this));
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
		 if (typeof targetLink.href === 'undefined');
		 {
				targetLink = targetLink.closest('a');
		 }
		 var uri = targetLink.href;

		 // If nothing is provided here.
		 if (typeof uri === 'undefined')
		 {
					return;
		 }

		 var current_tab = this.current_tab;
		 var params = new URLSearchParams(uri);
		 var new_tab = params.get('part');

		 var mobileToggle = this.root.querySelector('.mobile-menu input');
		 mobileToggle.checked = false;
		 var changeEv = new CustomEvent('change');
		 mobileToggle.dispatchEvent(changeEv);

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

				if (this.menu_elements[i].dataset.menuLink == new_tab)
				{
					 this.menu_elements[i].classList.add('active');
				}

		 }
		 // Add active to the new tab.
		// targetLink.classList.add('active');

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

	ShowMobileMenuEvent(event)
	{
		 event.preventDefault(); // target is checkbox.

		 var mobileMenu = this.root.querySelector('label.mobile-menu');
		 if (event.target.checked)
		 {
				mobileMenu.classList.add('opened');
				mobileMenu.classList.remove('closed')
		 }
		 else {
				mobileMenu.classList.add('closed');
				mobileMenu.classList.remove('opened')
		 }
	}

	// Elements with data-toggle active
	DoToggleActionEvent(event)
	{
			event.preventDefault();

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
			}

			for (var i = 0; i < checkboxes.length; i++)
			{
				  var checkbox = checkboxes[i];

					var field_id = checkbox.getAttribute('data-toggle');
					var target = document.getElementById(field_id);

					// Allow multiple elements to be toggled, which will not work with id. In due time all should be transferred to use class-based toggle
					// This can return null, in case of radio buttons where only one has a data-toggle set.
					var targetClasses = this.root.querySelectorAll('.' + field_id);

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
								this.ShowElement([targetClasses[j]]);
							}
							else {
								this.HideElement([targetClasses[j]]);
							}
					}
			} // for

	}

	DoToggleExcludeEvent(event)
	{
		 var checkbox = event.target;

		 if (true == checkbox.checked)
		 {
			   var excludeInput = this.root.querySelector('input[name="' + checkbox.dataset.exclude + '"]');
				 var ev = new CustomEvent('change');
				 excludeInput.checked = false;
				 excludeInput.dispatchEvent(ev);
		 }
	}

	DoToggleDisableEvent(event)
	{
		let checkbox = event.target;
		let disableTarget = this.root.querySelector('input[name="' + checkbox.dataset.disable + '"]');


		if (null !== disableTarget)
		{
				let settingParent = disableTarget.closest('setting');

				 //disableTarget.classList.add('disabled');
				 if (false == checkbox.checked)
				 {
						 	 disableTarget.disabled = true;
							 settingParent.classList.add('disabled');
				 }
				 else {
							disableTarget.disabled = false;
							settingParent.classList.remove('disabled');
				 }

		}

	}

	DoToggleHideWarningEvent(event)
	{
		 var checkbox = event.target;
		 var setting = checkbox.closest('setting');

		 if (false == checkbox.checked)
		 {
				 var warnings = setting.querySelectorAll('warning');
				 if (null !== warnings)
				 {
						 this.HideElement(warnings);
				 }
		 }
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

// Hide an element
HideElement(elems) {

	for (var i = 0; i < elems.length; i++)
	{
		var element = elems[i];

		element.style.opacity = 0;
			// When the transition is complete, hide it
			window.setTimeout(function (el) {
				el.classList.remove('is-visible');

			}, 300, element);
	}

};

FormSendEvent(event)
{
	 event.preventDefault();

	 var form = event.target;
	 var formData = new FormData(event.target, event.submitter);

	 if (this.save_in_progress)
	 {
					return false;
	 }

	 this.save_in_progress = true;

	 var saveButtons = this.root.querySelector('.setting-tab.active .save-buttons');
	 if (saveButtons !== null)
	 {
	 	saveButtons.classList.add('saving');
	 }

	 formData.append('screen_action', 'form_submit');
	 formData.append('form-nonce', formData.get('nonce'));

	 // Special Actions
	 let formaction_parsed = URL.parse(form.action);
	 if (formaction_parsed.searchParams && formaction_parsed.searchParams.has('sp-action'))
	 {
				formData.set('screen_action', formaction_parsed.searchParams.get('sp-action'));
	 }

	 this.DoAjaxRequest(formData, this.FormResponseEvent).then( (json) => {
			 this.FormResponseEvent(json);
	 }) ;
}


async DoAjaxRequest(formData, responseOkCallBack, responseErrorCallback)
{

	formData.append('action', 'shortpixel_settingsRequest');
	formData.append('ajaxSave', 'true');


	if (false === formData.has('nonce'))
	{
				formData.append('nonce', ShortPixelProcessorData.nonce_settingsrequest);
	}

	const url = ShortPixel.AJAX_URL;

	if (typeof responseOkCallBack !== 'function')
	{
			responseOkCallBack = (response) => { console.log(response); };
	}
	if (typeof responseErrorCallback !== 'function')
	{
		 responseErrorCallback = (response) => { console.error(response) };
	}


	var response = await fetch(url, {
	 method: 'POST',
	 body: formData
 });


json = null;
var json;
if (response.ok)
{
	 json = await response.json();
	 return json;

}


}

FormResponseEvent(json)
{
		let saveDialog = document.querySelector('.ajax-save-done');
		var noticeLine = saveDialog.querySelector('.after-save-notices');
		this.save_in_progress = false;

		var saveButtons = this.root.querySelector('.setting-tab.active .save-buttons');
		if (saveButtons !== null)
		{
	 		saveButtons.classList.remove('saving');
		}


			if (json.redirect)
			{
				 if (json.redirect == 'reload')
				 {
							window.location.reload();
				 }
				 else {
						 window.location.href = json.redirect;
				 }
			}

			if (json.notices && json.notices.length > 0)
			{
					var notice_count = saveDialog.querySelector('.notice_count');
					notice_count.textContent = json.notices.length;
					noticeLine.classList.remove('shortpixel-hide');
			}
			else {
				noticeLine.classList.add('shortpixel-hide');
			}
			if (json.display_notices)
			{
					let anchor = document.querySelector('.wp-header-end')
					for (let i = 0; i < json.display_notices.length; i++)
					{
						anchor.insertAdjacentHTML('afterend', json.display_notices[i]);
					}
			}

		saveDialog.classList.add('show');

		window.setTimeout(function () {
			 saveDialog.classList.remove('show')
		}, 2000);
}



DashBoardWarningEvent(warning, matches)
{

	 var dashBox = warning[0];
	 var status = (true === matches.allMatches) ? 'alert' : (true === matches.someMatch) ? 'warning' : 'ok';

	 let panelName;
	 if (dashBox.classList.contains('dashboard-optimize'))
	 {
			panelName = 'optimize';
	 }
	 else
			panelName = 'webp';

	 this.dashboard_status[panelName] = status;

	 dashBox.classList.remove('ok', 'alert', 'warning');
	 dashBox.classList.add(status)

	 // Remove all status-lines ( rebuild )
	 var statusWrapper = dashBox.querySelector('.status-wrapper');
	 if (null === statusWrapper)
	 {
		  console.error('issue with statuswrapper');
			return;
	 }
 	 Array.from(statusWrapper.children).forEach(e => e.remove());


	 var statusIcon = document.createElement('i');
	 var linesAdded = [];

	 statusIcon.classList.add('shortpixel-icon', 'static-icon', status);


  if (matches.matches.length > 0)
	{
			for(var i = 0; i < matches.matches.length; i++ )
			{
				var input = matches.matches[i];
				var statusLine = document.createElement('span');
				var duplicate = false;
				statusLine.classList.add('status-line');

				if (input.dataset.dashboard)
				{
					let line = input.dataset.dashboard;
					if (linesAdded.indexOf(line) == -1)
					{
						statusLine.textContent = line;
						linesAdded.push(line);
					}
					else {
						continue; // skip this one.
					}
				}
				else {
				  var linestring = this.strings.dashboard_strings[status];
		 		  statusLine.textContent = linestring;
				}

					statusWrapper.appendChild(statusLine).appendChild(statusIcon.cloneNode());

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
	}

	 // Button display
	 var button = dashBox.querySelector('button,.dashboard-button');
	 if (null === button)
	 {
		  console.error('dash button not found');
			return;
	 }

	 if ('ok' === status)
	 {
			if (false === button.classList.contains('not-visible'))
			{
					button.classList.add('not-visible');
			}
	 }
	 else if (true === button.classList.contains('not-visible'))
	 {
		 		button.classList.remove('not-visible');
	 }

	 this.UpdateDashBoardMainBlock();
}

CDNCheckWarningEvent(warning, matches, elements)
{
	 // If useCDN, or the other one is selected, pass this off as ok.
	 // Matches are the ones, who are -NOT- selected, ie not-preferable status.
	 var matchinputs = matches.matches;

	 var hasCreate = false;
	 var hasDelivery = false;

	 for ( var i = 0; i < matchinputs.length; i++)
	 {
			 switch(matchinputs[i].name)
			 {
				 case 'createWebp':
				 case 'createAvif':
						 hasCreate = true;
					break;
				 case 'useCDN':
 				 case 'deliverWebp':
						 hasDelivery = true;
					break;
			 }
	 }

	 // All fine, but dashboardevent expects a 'negative', so any, all match should be false.
	 if (true == hasCreate && true == hasDelivery)
	 {
				matches.someMatch = false;
				matches.allMatches = false;
				matches.matches;
				matchinputs = Array.from(elements); // equal matchinput to elements, not to trigger too much texts
	 }
	 else if (false == hasCreate && false == hasDelivery ) // Boo alert, allMatch should true.
	 {
				matches.allMatches = true;
	 }
	 else
	 { // one of the other is true.
				matches.someMatch = true;
	 }

	 // Now we have to go through all the elements, to find the ones unselected, because they rule the message being send on the dashboard. So those are the matches we must put to dashboard event.
	 var elements = Array.from(elements);
	 var to_splice = [];
	 for (var i = 0; i <  elements.length; i++)
	 {
			var el = elements[i];
			var res = matchinputs.find(node => node.isEqualNode(el));
			var name = elements[i].name;

			// Dirty exceptions
			if (typeof res !== 'undefined' ||
					(true == hasCreate &&  (name == 'createAvif' || name == 'createWebp' )) ||
					(true == hasDelivery && (name == 'useCDN' || name == 'deliverWebp') )
			)
			{
				to_splice.push(i); // no live splicing, because it messed the indexes
			}
	 }

	 // Funky function I found online to filter the splicers from the elements.
	 elements = elements.filter((value, index) => to_splice.indexOf(index) == -1);

	 matches.matches = elements;

	 this.DashBoardWarningEvent(warning, matches);
}

UpdateDashBoardMainBlock()
{
		var ok = true;

		for (const [key, value] of Object.entries(this.dashboard_status))
		{
					if (value !== 'ok')
						{
							 ok = false;
						}
		}

		var mainBlock = this.root.querySelector('.panel.first-panel .first-line');


		if (ok && false == mainBlock.classList.contains('ok'))
		{
				mainBlock.classList.remove('warning');
				mainBlock.classList.add('ok');
		}
		else if (false == ok && false == mainBlock.classList.contains('warning'))
		{
				mainBlock.classList.add('warning');
				mainBlock.classList.remove('ok');
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
			button.classList.remove('hidden', 'not-visible');
	 }
	 else {
	 	  var mode = 'edit';

			let mainEl = event.target.closest('li');
			var id = mainEl.id;


			var exclusionModal = this.root.querySelector('.new-exclusion');
		//	event.target.closest('li').after(exclusionModal);
			//exclusionModal.parentElement = event.target.closest('li').after(exclusionModal);

			var title = this.root.querySelector('.new-exclusion h3.edit-title');
			//var button = this.root.querySelector('.new-exclusion .button-actions button[name="removeExclusion"]');
			var input = this.root.querySelector('.new-exclusion input[name="edit-exclusion"]')

			updateButton.classList.remove('not-visible', 'hidden');

			input.value = id;
			var dataElement = event.target.closest('li').querySelector('input').value;
			var data = JSON.parse(dataElement);
			this.ReadWriteExclusionForm(data)

	 }

 	 title.classList.remove('not-visible', 'hidden');
	 //button.classList.remove('not-visible', 'hidden');

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
	let element = this.root.querySelector('.new-exclusion');
	element.classList.add('not-visible');

	let notValidated = element.querySelectorAll('.not-validated');
	for (let i = 0; i < notValidated.length; i++)
	{
		 notValidated[i].classList.remove('not-validated');
	}

	if (notValidated.length  > 0)
	{
		let message = element.querySelector('.validation-message');
		message.classList.add('not-visible');
	}

}

// EXCLUSIONS - this is not conclusive, some elements have direct events .  This needs some brushing up to make more consistent.
NewExclusionUpdateEvent(event)
{
	var target = event.target;
	if (typeof target.name === 'undefined')
	{
		 	var target = target.parentElement;
	}
	var inputName = target.name;


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

		 var thumbSelect = this.root.querySelector('div.thumbnail-select');
		 if (null === thumbSelect)
		 {
			  console.error('Something wrong with the thumbnails selector');
				return false;
		 }

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
			setting.validated = true;

			strings.type = typeOption.options[typeOption.selectedIndex].innerText;
			strings.apply = applyOption.options[applyOption.selectedIndex].innerText;
		}
		else {
			if (setting.type && setting.type.indexOf('regex') != -1)
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
			  var thumbOptions = this.root.querySelectorAll('.new-exclusion input[name="thumbnail-select[]"]');
				var thumblist  = [];
				if ('read' === mode)
				{
					for(var i =0; i < thumbOptions.length; i++)
					{
						 if (thumbOptions[i].checked == true)
						 {
						 	thumblist.push(thumbOptions[i].value);
						 }
					}
				setting.thumblist = thumblist;
				}
				else if ('write' === mode){
					 for (var i = 0; i < thumbOptions.length; i++)
				 	 {
						 	if (setting.thumblist.indexOf(thumbOptions[i].value) != -1)
							{
								 thumbOptions[i].checked = true;
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
			   if (setting.type && setting.type.indexOf('regex') != -1)
				 {
					  regexOption.checked = true;
				 }
		 }

		 var validateInput = [];

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

						validateInput.push(width, height);
				 }
				 else {
					 setting.value = minwidth.value + '-' + maxwidth.value + 'x' + minheight.value + '-' + maxheight.value;
					 validateInput.push(minwidth, maxwidth, minheight, maxheight);

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
			//		this.NewExclusionToggleSizeOption(exactOption);
			 }
			 this.NewExclusionToggleSizeOption(exactOption);

		 }
		 else
		 {
			 	validateInput.push(valueOption);
		 }


		 // // Problem - with validatation, must note which field is not validated (1) and secondly value must be valited as string, while the sizes should be numbers.
		 setting.validated = this.DoValidateInputs(validateInput);

		 if ('read' === mode)
		 {
		 	 return [setting, strings];
		 }
}

DoValidateInputs(inputs)
{
		var validated = true;
	  for (let i = 0; i < inputs.length; i++)
		{
			 let item = inputs[i];
			 let type = item.type;
			 let value = item.value;

			 if (type == 'number' && (value.length <= 0 || isNaN(value)))
			 {
				  item.classList.add('not-validated');
				 	validated = false;
				 // break;
			 }
			 else if (value.length <= 0) {
				 	item.classList.add('not-validated');

				  validated = false;
				//	break;
			 }
			 else if (item.classList.contains('not-validated')) {
			 	 	item.classList.remove('not-validated');
			 }
	  };

		// Validate.

		let validationMessage = this.root.querySelector('.validation-message');
		if (false === validated)
		{
			 validationMessage.classList.remove('not-visible');
		}
		else {
			validationMessage.classList.add('not-visible');
		}

		return validated;
}


// Function to add an exclusion to the interface.
NewExclusionButtonAdd(target, update)
{
		 var result = this.ReadWriteExclusionForm(null);

		 var setting = result[0];
		 var strings = result[1];

		 if (false == setting.validated)
		 {
			  return false;
		 }

		 var listElement = this.root.querySelector('.exclude-list');

		 if (typeof update === 'undefined')
		 {
			 var newIndexInput = document.getElementById('new-exclusion-index');
			 var newIndex = parseInt(newIndexInput.value) + 1;
			 newIndexInput.value = newIndex;
			 var newIndexString = 'id="exclude-' + newIndex + '"';
		 }
		 else {
		 	 var newIndexString = 'id="' + update + '"';
		 }

		 var input_value = JSON.stringify(setting);

		 var noItemsItem = this.root.querySelector('.exclude-list .no-exclusion-item');
		 var itemClass = '';
		 var title = '';
		 /*if (noItemsItem !== null)
	 	 {
				 itemClass = 'not-visible';
		 } */

		 if (setting.type && setting.type.indexOf('regex') != -1)
		 {
			  itemClass += ' is-regex';
		 }

		 var format = document.getElementById('exclusion-format').innerHTML;

		 var sprintf = (str, ...argv) => !argv.length ? str :
		     sprintf(str = str.replace(sprintf.token||"$", argv.shift()), ...argv);
		 sprintf.token = '%s';

//  $class, $title, $exclude_id, $option_code, $field_name, $value, $apply_name, '', 'Yesno'
		 var newHTML = sprintf(format, 'class="' + itemClass + '"', title,  newIndexString, input_value, strings.type, setting.value, strings.apply, '', ''  );

		 var newHTML =  this.DecodeHtmlEntities(newHTML);

		 if (typeof update !== 'undefined')
		 {
			 	let updateEl = listElement.querySelector('#' + update);
				updateEl.outerHTML = newHTML;
		 }
		 else {
			 listElement.insertAdjacentHTML('beforeend', newHTML);
		 }

		 if (typeof update === 'undefined')
		 {
			 var newElement = listElement.querySelector('#exclude-' + newIndex);
		 }
		 else {
		 	 var newElement = listElement.querySelector('#' + update);
		 }

		 var editButton = newElement.querySelector('i.edit');
		 editButton.addEventListener('click', this.NewExclusionShowInterfaceEvent.bind(this));

		 newElement.addEventListener('click', this.NewExclusionShowInterfaceEvent.bind(this));

		 var removeButton = newElement.querySelector('i.remove');
		 removeButton.addEventListener('click', this.RemoveExclusion.bind(this));


		 this.ResetExclusionInputs();
		 this.HideExclusionInterface();
		 this.ShowExclusionSaveWarning();
}

DecodeHtmlEntities(str) {
    const entities = {
        '&lt;': '<',
        '&gt;': '>',
        '&amp;': '&',
        '&quot;': '"',
        '&apos;': "'"
    };

    return str.replace(/&[a-zA-Z0-9#]+;/g, (match) => entities[match] || match);
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

	this.NewExclusionToggleSizeOption(exactOption);

}

UpdateExclusion()
{
	var id = this.root.querySelector('.new-exclusion input[name="edit-exclusion"]');

	this.NewExclusionButtonAdd(null, id.value);

}

ShowExclusionSaveWarning()
{
	  var reminder = this.root.querySelector('.exclusion-save-reminder');
		if (reminder)
		{
			 reminder.classList.remove('hidden');
		}
}

RemoveExclusion(event)
{
		event.preventDefault();
		var target = event.target;
		 var element = target.closest('li');
		 element.remove();

		 this.ShowExclusionSaveWarning();
}

ToggleApiFieldEvent(event)
{
	 event.preventDefault();

	 var apiKeyField = this.root.querySelector('input[name="apiKey"]');
	 if (apiKeyField.type == 'password')
	 {
		  apiKeyField.type = 'text';
	 }
	 else {
	 	 apiKeyField.type = 'password';
	 }

}


} // SPSettings  class


document.addEventListener("DOMContentLoaded", function(){
	  var s = new ShortPixelSettings();
		s.Init();
});
