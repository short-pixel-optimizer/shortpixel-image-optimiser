'use strict';


document.addEventListener('shortpixel.settings.loaded', ShortPixelOnboarding.Init());


class ShortPixelOnboarding
{

    Init()
    {
      console.log('iit');
        this.InitNewKeySwitch();
    }

    InitNewKeySwitch()
    {
        var panels = document.querySelectorAll('.onboarding-join-wrapper settinglist');
        for (var i = 0; i < panels.length; i++)
        {
           panels[i].addEventListener('click', this.NewKeyPanelEvent().bind(this));
        }
    }

    NewKeyPanelEvent(event)
    {
       event.preventDefault();

       var panels = document.querySelectorAll('.onboarding-join-wrapper settinglist');
       for (var i = 0; i < panels.length; i++)
       {
          panels[i].classList.remove('now-active');
       }

       var target = event.target;
       target.classList.addClass('now-active');



    }
}
