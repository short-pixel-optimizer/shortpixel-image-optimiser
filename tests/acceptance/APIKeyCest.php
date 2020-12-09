<?php 

class APIKeyCest
{
    public function _before(AcceptanceTester $I)
    {
    }

    public function removeAPIKey(AcceptanceTester $I)
    {
        $I->wantToTest("How an error message is printed when removing API key from the input field");
        $I->loginAsAdmin();
        $I->amOnAdminPage('/options-general.php?page=wp-shortpixel-settings&part=settings');

        $I->fillField(['name' => 'key'], '');
        $I->wait(1);
        $I->click('Save Changes');

        $I->see('', ['name' => 'key']);
        $I->see('Your API Key has been removed');
        $I->see('In order to start the optimization process, you need to validate your API Key in the ShortPixel Settings page in your WordPress Admin.');

        $I->see('Join ShortPixel', 'a.tab-link');
        $I->dontSee('Advanced', 'a.tab-link');
        $I->dontSee('Statistics', 'a.tab-link');
        $I->dontSee('Debug', 'a.tab-link');
    }

    public function fillWrongAPIKeyWithoutRemovingCorrectOneFromBefore(AcceptanceTester $I) {
        $I->wantToTest('That inserting a wrong API key over the valid, existing one, will not make the change and print a message');
        $I->loginAsAdmin();
        $I->amOnAdminPage('/options-general.php?page=wp-shortpixel-settings&part=settings');

        $I->fillField(['name' => 'key'], 'wrongAPIKeySPIOdzYHj');
        $I->click('Save Changes');

        $I->see('Error during verifying API key: Wrong API Key.');
        $apiKey = $I->grabValueFrom('input[name="key"]');
        $I->assertEquals($_SERVER['SP_API_KEY'], $apiKey);
        $I->dontSee('Great, your API Key is valid. Please take a few moments to review the plugin settings before starting to optimize your images.');
        $I->dontSee('wrongAPIKeySPIOdzYHj');
        $I->see('Your API key is valid.');

        $I->wantTo('See if settings page still displays the advanced settings and so on');
        $I->see('Advanced', 'a.tab-link');
        $I->see('Statistics', 'a.tab-link');
        $I->dontSee('Debug', 'a.tab-link');
    }

    public function fillWrongAPIKeyAfterRemovingCorrectOneFromBefore(AcceptanceTester $I) {
        $I->wantToTest('That an error message is displayed and no other previous API key is filled in after error');

        $this->removeAPIKey($I);
        $I->see('', '#key');

        $I->fillField('#key', 'wrongAPIKeySPIOdzYHj');
        $I->click('Validate');

        $I->see('Error during verifying API key: Wrong API Key.');
        $I->see('In order to start the optimization process, you need to validate your API Key in the ShortPixel Settings page in your WordPress Admin.');
        $I->dontSee('Your API key is valid.');
        $I->dontSee('Great, your API Key is valid. Please take a few moments to review the plugin settings before starting to optimize your images.');
        $I->dontSee('wrongAPIKeySPIOdzYHj');

        $I->see('Join ShortPixel', 'a.tab-link');
        $I->dontSee('Advanced', 'a.tab-link');
        $I->dontSee('Statistics', 'a.tab-link');
        $I->dontSee('Debug', 'a.tab-link');
    }
}
