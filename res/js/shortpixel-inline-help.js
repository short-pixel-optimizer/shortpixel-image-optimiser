'use strict'

document.addEventListener("DOMContentLoaded", function(){


   function OpenHelpEvent(event)
   {
      var target = event.target;
      var link = target.dataset.link;
      BuildHelp(link);

   }

   function BuildHelp(link)
   {

       var helpMain = document.createElement('div');
       helpMain.id = 'spio-inline-help';
       helpMain.classList.add('spio-modal');

       var title = document.createElement('div');
       title.classList.add('spio-modal-title');

       var button = document.createElement('button');
       button.addEventListener('click', CloseHelpEvent);
       button.classList.add('spio-close-help-button');
       button.innerHTML = '&times';

       title.appendChild(button);

       var body = document.createElement('div');
       var frame = document.createElement('iframe');
       frame.src = link;
       body.appendChild(frame);

       helpMain.appendChild(title);
       helpMain.appendChild(body);

       var helpShade = document.createElement('div');
       helpShade.id = 'spio-inline-shade';
       helpShade.classList.add('spio-modal-shade');
       helpShade.addEventListener('click', CloseHelpEvent);

       document.body.appendChild(helpShade);
       document.body.appendChild(helpMain);

       frame.style.height = (helpMain.clientHeight - 38) + 'px';
   }

   function CloseHelpEvent(event)
   {
      event.preventDefault();

      var id = document.getElementById('spio-inline-shade');
      if (id !== null)
      {
        id.remove();
      }

      id = document.getElementById('spio-inline-help');
      if (id !== null)
      {
        id.remove();
      }
   }

   var elements = document.querySelectorAll('i.documentation');
   if (elements !== null)
   {
      for(var i = 0; i < elements.length; i++)
      {
            elements[i].addEventListener('click', OpenHelpEvent);
      }
   }



}); // DOMContentLoaded
