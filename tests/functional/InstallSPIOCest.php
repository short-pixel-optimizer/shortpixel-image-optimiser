<?php


class InstallSPIOCest
{

    public function pluginNotInstalled(FunctionalTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPluginsPage();
        $I->cantSeePluginInstalled("shortpixel-image-optimiser");
    }

    public function searchPlugin(FunctionalTester $I)
    {

    }


}
