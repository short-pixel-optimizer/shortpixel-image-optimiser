<?php 

class SettingsPageCest
{
    public function _before(AcceptanceTester $I)
    {
        $I->loginAsAdmin();
        $I->amOnAdminPage('options-general.php?page=wp-shortpixel-settings');
    }

    // tests
    public function changeOptimizationLevel(AcceptanceTester $I)
    {
        $I->click('Glossy');
        $I->acceptPopup();
        $I->click('Save Changes');
        $I->seeCheckboxIsChecked('.shortpixel-radio-glossy');
    }

    public function reloadPageAfterChangeOptionsWontPersist(AcceptanceTester $I){
        $I->click('Glossy');
        $I->reloadPage();
        $I->seeCheckboxIsChecked('.shortpixel-radio-lossy');
    }
}
