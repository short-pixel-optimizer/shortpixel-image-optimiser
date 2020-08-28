<?php


class InstallSPIOCest
{
/*
    public function pluginNotInstalled(FunctionalTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPluginsPage();
//        $I->cantSeePluginInstalled("shortpixel-image-optimiser");
    }

    //TODO Move to a different test suite. Normally the tests would happen on the latest commit in the github updates. this should
    public function assumeSearchShortPixelByNameIsFirst(FunctionalTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnPage("/wp-admin/plugin-install.php");
        $I->fillField("input.plugin-search-input", "short pixel");
    }

    public function assumeISeeInstallationButton(FunctionalTester $I, $scenario) {
        $scenario->skip("Work in progress");
    }

    public function assumeISeeActivateButton(FunctionalTester $I, $scenario) {
        //TODO After installed, the button should be deactivated
        //This seems like a test on wordpress, so maybe drop it
        $scenario->skip("Work in progress");
    }

    public function assumeISeeMoreDetailsButton(FunctionalTester $I) {
        //TODO. text printed in the description is expected one
    }

    public function assumeInstallPrintsOk(FunctionalTester $I, $scenario)
    {
        $I->loginAsAdmin();
        $I->amOnPage("/wp-admin/plugin-install.php");
        $I->fillField("input.plugin-search-input", "short pixel");
        $I->click("[data-slug=shortpixel-image-optimiser]");
        $scenario->skip("Work in progress");
        $I->see("Activate", "[data-slug=shortpixel-image-optimiser]");
    }
*/

/*    public function assumeAPIKeyIsActive(FunctionalTester $I) {
        $this->amOnSPIOSettingsPage($I);
        $I->canSeeInField("#key", "API_KEY"); //TODO remove api key from here
    }*/

/*    public function installFackerPress(FunctionalTester $I) {
        $I->loginAsAdmin();
        $I->amOnAdminPage("plugin-install.php?s=fakerpress&tab=search&type=term");
        $I->click("Install Now");
        $I->click("Activate");
        $I->amOnAdminPage("admin.php?page=fakerpress&view=posts");
        $I->fillField("fakerpress-field-qty-min", 5);
        $I->click("Generate");
     }
 */


    public function startOptimizingImage() {

    }

    public function fail(FunctionalTester $I) {
        $I->amGoingTo("Fail for the purpose of testing pipeline");
        $I->expect("Pipeline will fail");
    }

    /**
     * @param FunctionalTester $I
     */
    private function amOnSPIOSettingsPage(FunctionalTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPluginsPage();
        $I->click("Activate");
        $I->amOnAdminPage("options-general.php?page=wp-shortpixel-settings");
    }


}
