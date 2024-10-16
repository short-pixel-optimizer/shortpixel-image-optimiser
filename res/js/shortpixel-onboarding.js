'use strict';

class ShortPixelOnboarding
{

    root;
    settings;

    constructor(data)
    {
       this.root = data.root;
       this.settings = data.settings;
       this.Init();

    }

    Init()
    {
      console.log('init onboard');
      this.InitActions();
    }


    InitActions()
    {
         this.InitNewKeySwitch();

         var addButton = this.root.querySelector('button[name="add-key"]');
         addButton.addEventListener('click', this.AddKeyEvent.bind(this));
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

            // formData.append('screen_action', '')
          }

       }
       else if(activePanel.classList.contains('existing-customer'))
       {
           let apiKey = activePanel.querySelector('input[name="apiKey"]');

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
        console.trace(json);
        var anchor = this.root.querySelector('.submit-errors');
        anchor.innerHTML = '';

        if (json.display_notices)
        {
          for (let i = 0; i < json.display_notices.length; i++)
          {
            console.log(json.display_notices[i]);
            anchor.innerHTML += json.display_notices[i];
            //anchor.insertAdjacentHTML('afterend', json.display_notices[i]);
          }

          anchor.classList.add('is-visible');
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

    }

    IsEmailValid(email) {
        var regex = /^\S+@\S+\.\S+$/;
        return regex.test(email);
    }

}

document.addEventListener('shortpixel.settings.loaded', function (event) {
  var s = new ShortPixelOnboarding(event.detail);
});
