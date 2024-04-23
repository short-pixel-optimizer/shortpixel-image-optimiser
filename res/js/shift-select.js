'use strict';


class ShiftSelect
{
    lastChecked = null;
    checkList;

    constructor(selector)
    {
       var checkList =  document.querySelectorAll(selector);
       if (checkList.length == 0)
       {
         return; 
       }

       for (let i = 0; i < checkList.length; i++)
       {
           let checkbox = checkList[i];
           checkbox.addEventListener('click', this.HandleCheckEvent.bind(this));
       }

       this.checkList = checkList;
    }

    HandleCheckEvent(event)
    {
        let inBetween = false;
        let changeEvent = new Event('change');
        var target = event.target;

        // Selection happens because of ranges.
        window.getSelection().removeAllRanges();

        var[startindex, lastindex, endindex, targetindex] = [0,0,0,0];

        if (this.lastChecked !== null)
        {
           var lastindex = this.FindIndexOfElement(this.lastChecked);
        }

        var targetindex = this.FindIndexOfElement(target);

        if (lastindex == targetindex)
        {
           return;
        }
        else if (lastindex > targetindex)
        {
           startindex = targetindex;
           endindex = lastindex;
        }
        else {
          startindex = lastindex;
          endindex = targetindex;
        }

        if (event.shiftKey)
        {
            for (let i = startindex; i < endindex; i++)
            {

                this.checkList[i].checked = target.checked;
                this.checkList[i].dispatchEvent(changeEvent);
            }
        }

        this.lastChecked = target;
    }

    FindIndexOfElement(element)
    {
        let name = element.name;
        let value = element.value;

        for (let i = 0; i < this.checkList.length; i++)
        {
              if (this.checkList[i].name == name && this.checkList[i].value == value)
              {
                 return i;
              }
        }
    }

} // ShiftSelect
