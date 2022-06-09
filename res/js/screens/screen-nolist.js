'use strict';

// MainScreen as an option for delegate functions
var ShortPixelScreen = function (MainScreen, processor)
{
    this.isCustom = true;
    this.isMedia = true;
    this.processor = processor;

    this.Init = function()
    {

    },
    this.HandleImage = function(result, type)
    {
				return true;
    }

    this.UpdateStats = function()
    {

    }
    this.HandleError = function()
    {

    }

    this.RenderItemView = function(e)
    {

    }


} // class
