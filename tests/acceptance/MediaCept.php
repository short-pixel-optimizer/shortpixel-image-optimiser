<?php


class MediaCept
{
    public function uploadNewMedia(AcceptanceTester $I)
    {
        $I->loginAsAdmin();

        $I->amOnPage('/wp-admin/media-new.php');
        $I->waitForText('Upload New Media');

        $I->attachFile('input[type="file"]', 'team.jpg');

        $I->waitForElement('.edit-attachment', 20);
        $I->seeElement('.edit-attachment');
        $I->click('.edit-attachment');
    }

}