<?php 

class PluginActivationCest
{
    public function _before(AcceptanceTester $I)
    {

    }

    // tests
    public function deactivateDeleteInstallPlugin(AcceptanceTester $I)
    {
        $I->loginAsAdmin();

        $this->deactivatePlugin($I);
        $this->deletePlugin($I);
        $this->installSPIOPlugin($I);
    }

    private function deactivatePlugin(AcceptanceTester $I) {
        $I->amOnAdminPage('plugins.php');
        $I->click('#shortpixel-deactivate-link-shortpixel-image-optimiser');
        $I->click('#shortpixel-deactivate-submit-form');
        $I->see('Plugin deactivated.');
        $I->see('Activate', '#activate-shortpixel-image-optimiser');
    }

    private function deletePlugin(AcceptanceTester $I) {
        $I->click('#delete-shortpixel-image-optimiser');
        $I->acceptPopup();

        $I->wait(10);
        $I->see('ShortPixel Image Optimizer was successfully deleted.');
    }

    /**
     * @param AcceptanceTester $I
     * @throws \Codeception\Exception\ModuleException
     */
    private function installSPIOPlugin(AcceptanceTester $I): void
    {
        $I->amOnAdminPage('plugin-install.php?s=shortpixel&tab=search&type=term');

        $I->click('a[data-slug="shortpixel-image-optimiser"]');
        $I->wait(45);
        $I->reloadPage();
        $I->click('Activate');

        $I->see('Plugin activated');
        $I->seeInCurrentUrl('plugins.php');
        $I->seePluginInstalled('shortpixel-image-optimiser');
        $I->seePluginActivated('shortpixel-image-optimiser');
        $I->seePluginFileFound('shortpixel-image-optimiser/wp-shortpixel.php');
    }
}
