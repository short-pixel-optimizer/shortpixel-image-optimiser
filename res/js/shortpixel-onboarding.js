'use strict';

class ShortPixelOnboarding
{

    root;
    settings;
    buttons = [];
    steps = [];
    step_counter;
    stepdots;

    constructor(data)
    {
       this.root = data.root;
       this.settings = data.settings;
       this.Init();

    }

    Init()
    {
      this.InitActions();
    }


    InitActions()
    {
         this.InitNewKeySwitch();

         var addButton = this.root.querySelector('button[name="add-key"]');
         addButton.addEventListener('click', this.AddKeyEvent.bind(this));

         var quickTour = this.root.querySelector('.quick-tour');
         if (quickTour !== null)
         {
            this.InitQuickTour();
         }
    }

    InitNewKeySwitch()
    {
        var panels = this.root.querySelectorAll('.onboarding-join-wrapper settinglist');
        for (var i = 0; i < panels.length; i++)
        {
           panels[i].addEventListener('click', this.NewKeyPanelEvent.bind(this));
        }
    }

    NewKeyPanelEvent(event)
    {
      // event.preventDefault();

       let target = event.target;
       if (event.target.tagName !== 'settinglist')
       {
          target = event.target.closest('settinglist');
       }

       if (target.classList.contains('now-active'))
       {
          return true;
       }

       var panels = this.root.querySelectorAll('.onboarding-join-wrapper settinglist');
       for (var i = 0; i < panels.length; i++)
       {
          panels[i].classList.remove('now-active');
       }

       target.classList.add('now-active');

    }

    AddKeyEvent(event)
    {
       event.preventDefault();

       console.log(event);

       var activePanel = this.root.querySelector('settinglist.now-active');
       var formData = new FormData();
       var submit = true;
       // Form Nonce.
       var spNonce = this.root.querySelector('input[name="sp-nonce"]').value;

       formData.append('sp-nonce', spNonce);

       if (activePanel.classList.contains('new-customer'))
       {
          let email = activePanel.querySelector('input[name="pluginemail"]');
          let tos = activePanel.querySelector('input[name="tos"]');

          email.classList.remove('invalid');
          tos.classList.remove('invalid');

          if (false === this.IsEmailValid(email.value))
          {
             email.classList.add('invalid');
             activePanel.querySelector('#pluginemail-error').style.display = 'block';
             submit = false;
          }
          if (false === tos.checked)
          {
             tos.classList.add('invalid');
             activePanel.querySelector('.tos-hand').style.display = 'block';
             submit = false;
          }
          else {
             formData.append(email.name, email.value);
             formData.append('screen_action', 'action_request_new_key');
          }

       }
       else if(activePanel.classList.contains('existing-customer'))
       {
           let apiKey = activePanel.querySelector('input[name="login_apiKey"]');
           formData.append('apiKey', apiKey.value);
           formData.append('screen_action', 'action_addkey');
       }

       if (true === submit)
       {
          this.settings.DoAjaxRequest(formData, this.FormAddKeyResponse, this.FormAddKeyResponse).then( (json) => {
              this.FormAddKeyResponse(json);
          }) ;

       }

    }

    FormAddKeyResponse(json)
    {
        var anchor = this.root.querySelector('.submit-errors');
        anchor.innerHTML = '';

        if (json.display_notices)
        {
          for (let i = 0; i < json.display_notices.length; i++)
          {
            anchor.innerHTML += json.display_notices[i];
          }

          anchor.classList.add('is-visible');
        }

        window.setTimeout(function () {

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
        },500);
    }

    IsEmailValid(email) {
        var regex = /^\S+@\S+\.\S+$/;
        return regex.test(email);
    }

    InitQuickTour()
    {
         var buttons = this.root.querySelectorAll('.quick-tour .navigation button');
         for (var i = 0; i < buttons.length; i++)
         {
            let button = buttons[i];
            button.addEventListener('click', this.QuickTourActionEvent.bind(this));
            button.disable = () => { button.classList.add('hide'); };
            button.enable = () => { button.classList.remove('hide'); };
            button.showNext = () => { button.classList.remove('show-start'); button.classList.add('show-next'); };
            this.buttons.push(button);
         }

         var stepdots = this.root.querySelectorAll('.quick-tour .navigation .stepdot');
         for (var i = 0;  i < stepdots.length; i++)
         {
            let stepdot = stepdots[i];
            stepdot.addEventListener('click', this.QuickTourActionEvent.bind(this));
            stepdot.disable = () => { button.classList.remove('active'); };
            stepdot.enable = () => { button.classList.add('active'); };

         }

         this.stepdots = stepdots;

         var closeButtons = this.root.querySelectorAll('.quick-tour .close');
         for (var i = 0; i < closeButtons.length; i++)
         {
           closeButtons[i].addEventListener('click', this.QuickTourCloseEvent.bind(this));

         }

         this.steps = this.root.querySelectorAll('.quick-tour .steps .step');
         this.step_counter = this.root.querySelector('.quick-tour .navigation .step_count');

         this.step_counter.innerText = '1/' + this.steps.length;

         this.root.classList.add('active-step-' + 0);
    }

    QuickTourActionEvent(event)
    {
       event.preventDefault();

       var target = event.target;
       if (target.type !== 'button' && false == target.classList.contains('stepdot'))
       {
          target = target.closest('button');
       }

       for (var i = 0; i < this.steps.length; i++)
       {
          if (this.steps[i].classList.contains('active'))
          {
             var current_step_number = i;
             var current_step = this.steps[i];
             console.log('current_step', current_step_number);
             break;
          }
       }

       var next_step_number = current_step_number+1;
       var previous_step_number = current_step_number-1;

      //var previous_button = this.buttons[0];
      var next_button = this.buttons[0];
      var end_button = this.buttons[1];

       if (target.classList.contains('next') && next_step_number < this.steps.length)
       {
            this.QuickTourSwitchToItem(next_step_number);
            var new_step = next_step_number;
       }
    /*   else if (previous_step_number >= 0 && target.classList.contains('previous'))
       {
          this.QuickTourSwitchToItem(previous_step_number);
          var new_step = previous_step_number;
       } */
       else if (target.classList.contains('stepdot')) {

          var new_step = event.target.dataset.step;
          if (new_step !== current_step_number)
          {
            this.QuickTourSwitchToItem(new_step);
          }
       }
       else {
         console.log('no steps done', event.target, next_step_number, previous_step_number);
         return;
       }

       // Somebody click on active dot.
       if (new_step == current_step_number)
       {
          return;
       }

       current_step.classList.remove('active');
       this.root.classList.remove('active-step-' + current_step_number);
       this.step_counter.innerText = (new_step +1) + '/' + this.steps.length;

console.log('newstep', new_step, this.steps.length-1);
       if (new_step == (this.steps.length-1))
       {
          console.log('next step number at end?');
          next_button.disable();
          end_button.enable();
       }
       else {
          end_button.disable();
          if (new_step > 0)
          {
              next_button.showNext();
          }
          next_button.enable();
          if (new_step == 1)
          {
            for (var i = 0; i < this.stepdots.length; i++)
            {
               this.stepdots[i].classList.remove('active','hide');
            }
          }
       }

      this.stepdots[new_step-1].classList.add('active');
      if (current_step_number > 0)
        this.stepdots[current_step_number-1].classList.remove('active');
             /* if (new_step < 0)
       {
          previous_button.disable();
       }
       else {
          previous_button.enable();
       } */
    }

    QuickTourSwitchToItem(item_number)
    {
       this.steps[item_number].classList.add('active');
       if (typeof this.steps[item_number].dataset.screen !== 'undefined')
       {
           var ev = new CustomEvent('click');
           var menuItem = this.root.querySelector('menu ul [data-menu-link="' + this.steps[item_number].dataset.screen + '"]');
           if (menuItem !== null)
           {
              menuItem.dispatchEvent(ev);
           }
       }

       this.root.classList.add('active-step-' + item_number);
    }

    QuickTourCloseEvent(event)
    {
       event.preventDefault();

       var formData = new FormData();
       var spNonce = this.root.querySelector('input[name="sp-nonce"]').value;
       formData.append('sp-nonce', spNonce);
       formData.append('screen_action', 'action_end_quick_tour');

       this.QuickTourSwitchToItem(0);

       this.settings.DoAjaxRequest(formData, null, null).then( (json) => {
           this.root.querySelector('.quick-tour').remove();
           window.location.reload();

       }) ;

    }

}

document.addEventListener('shortpixel.settings.loaded', function (event) {
  var s = new ShortPixelOnboarding(event.detail);
});
