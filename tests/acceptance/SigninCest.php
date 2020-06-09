<?php 

class SigninCest
{
    public function _before(AcceptanceTester $I)
    {
    }

    // tests
    public function tryToTest(AcceptanceTester $I)
    {
	    $I->loginAsAdmin();
	    $I->amOnPluginsPage();
	    $I->activatePlugin('hello-dolly');
	    $I->see('Deactivate', ['css' => '[aria-label=\'Deactivate Hello Dolly\']']);
    }
}
