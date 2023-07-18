'use strict';

// MainScreen as an option for delegate functions
class ShortPixelScreen extends ShortPixelScreenBase
{

  Init()
  {
    super.Init();
    this.ListenPLUpload();
  }


  // Only listening on the nolist ( and more specific -> media addnew) , since the post editor classic/ gutenberg and others have this interface otherwise hidden.
  ListenPLUpload() {

    // Most screen will not have uploader defined or ready.
    if (typeof uploader === 'undefined' || uploader === null)
    {
       return;
    }

    var self = this;
    uploader.bind('UploadComplete', function (up, file, response)
    {
        // Give processor a swoop when uploading is done, while respecting set boundaries.
        self.processor.RunProcess();
    });
  }

} // class
