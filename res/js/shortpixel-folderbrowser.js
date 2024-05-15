'use strict';

// Goals : Javascript plain folder/fileBrowser (filetree)
//       : Crawler to scan / refresh folders via ajax / JSON
//

class ShortPixelFolderTree
{
    strings = [];
    icons = [];
    didInit = false;
    parentElement;
    processor = null;

    loadedFolders = [];



    constructor(element, processor)
  	{
  		 //this.processor = processor;
  		 this.parentElement = element;
       this.processor = processor;
       this.Init();
  	}

    Init()
    {
        if (true === this.didInit)
          return;

        this.strings = spio_folderbrowser.strings;
        this.icons = spio_folderbrowser.icons;

        this.ShowLoading();
        this.AjaxLoadFolders('');

        this.didInit = true;
    }

    BuildElement()
    {

    }

    ShowLoading()
    {
       var loading = document.createElement('div');
       var h3 = document.createElement('h3');
       loading.classList.add('loading');

       h3.textContent = this.strings.loading;

       loading.appendChild(h3);
       this.parentElement.append(loading);
    }

    RemoveLoading()
    {
        var elements = document.querySelectorAll('.sp-folder-picker .loading')
        elements.forEach(function(element) {
          element.remove();
        })
    }

    LoadFolderEvent(e)
    {
        var data = e.detail;
        var folders = data.folder.folders;
        this.RemoveLoading();

        if (data.folder.is_error == 'true') // error / emtpy result handlers.
        {

        }

        var self = this;

        var parent = this.parentElement;
        if (data.folder.relpath !== '')
        {

            var child = document.querySelector('[data-relpath="' +  data.folder.relpath + '"]');

            if (child !== null)
            {
              parent = child;
              //child.classList.remove('closed');
              //child.classList.add('open');

            }
        }

        var ul = document.createElement('ul');
        ul.classList.add('loaded', 'expanded');
        ul.dataset.loadpath = data.folder.relpath;

        folders.forEach(function(element) {
            //  console.log(element);
              var li = document.createElement('li');
              li.dataset.relpath = element.relpath;
              li.classList.add('folder','closed');

              if ( element.is_active && true === element.is_active)
              {
                 li.classList.add('is_active');
              }

              if ( element.is_disabled && true === element.is_disabled)
              {
                  li.classList.add('is_active');
              }

              var link = document.createElement('a');

              var icon = document.createElement('i');
              icon.classList.add('icon');
              link.appendChild(icon);
              link.innerHTML += element.name;
              link.addEventListener('click', self.ToggleFolderContentsEvent.bind(self));

              li.appendChild(link);

              ul.appendChild(li);
        });

        parent.appendChild(ul); // add the tree.
    }

 // @Todo : find out how to keep track of previously selected items (and switch them out)
 // + Emit a singal (trigger event) to external when something is selected, so UX / hidden field can add them in custom.js
    ToggleFolderContentsEvent(e)
    {
        var anchor = e.target;
        var li = e.target.parentElement;
        if (li.tagName !== 'LI')
        {
           var li = li.parentElement;
        }

        // Is classList is_active ( meaning already selected a custom folder ) do nothing with both selection and subfolders
        if (true === li.classList.contains('is_active'))
        {
            return;
        }

        // remove all previous selected thingies.
        var openElements = this.parentElement.querySelectorAll('.folder.selected');
        openElements.forEach(function (element)
        {
           element.classList.remove('selected');
           //element.classList.add('closed');
        });

        var relpath = li.dataset.relpath;
        var childTree = document.querySelector('[data-loadpath="' + relpath + '"]');

        if (li.classList.contains('closed'))
        {
           if (null === childTree)
           {
            this.AjaxLoadFolders(relpath);
          }
          else {
            childTree.classList.add('expanded');
            childTree.classList.remove('collapsed');
          }

          li.classList.remove('closed');
          li.classList.add('open');

        //  var img = li.querySelector('img');
          //img.src = this.icons.folder_open;


        }
        else if (li.classList.contains('open'))
        {
          if (null !== childTree)
          {
             childTree.classList.remove('expanded');
             childTree.classList.add('collapsed');
          }

          li.classList.remove('open')
          li.classList.add('closed');
      //    var img = li.querySelector('img');
      //    img.src = this.icons.folder_closed;
        }

        li.classList.add('selected');
        var selectEvent = new CustomEvent('shortpixel-folder.selected', { 'detail': {'relpath': relpath}});
        this.parentElement.dispatchEvent(selectEvent);

    }

    GetFolderIcon(name)
    {
      var svg = document.createElement('img');
      svg.src = this.icons.folder_closed;
      svg.style.width = '20px';
      svg.style.height = '20px';
      return svg;

    }

    AjaxLoadFolders(relpath)
    {
        var folderbase = this.folderbase;

        var data = {
          type: 'folder',
          screen_action: 'browseFolders',
          relPath: relpath,
          callback: 'shortpixel.folder.LoadFolders',
        };

        window.addEventListener('shortpixel.folder.LoadFolders', this.LoadFolderEvent.bind(this), {'once':true});

        this.processor.AjaxRequest(data);

    }

} // class
