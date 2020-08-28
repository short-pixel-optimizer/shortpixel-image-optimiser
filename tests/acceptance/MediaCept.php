<?php


class MediaCept
{
    public function uploadNewMedia(AcceptanceTester $I)
    {
        $year  = date('Y');
        $month = date('m');

        $I->loginAsAdmin();

        $I->amOnPage('/wp-admin/media-new.php');
        $I->waitForText('Upload New Media');

        $I->attachFile('input[type="file"]', 'team.jpg');

        $I->waitForElement('.edit-attachment', 20);
        $I->seeElement('.edit-attachment');
        $I->click('.edit-attachment');

        $I->seeUploadedFileFound('team.jpg','today');

        $postTable = $I->grabPrefixedTableNameFor('postmeta');
        $id = $I->grabAllFromDatabase($postTable, 'post_id', ['meta_value', '{$year}/{$month}/team.jpg']);

        $I->seePostMetaInDatabase(['post_id' => $id, 'meta_key' => '_wp_attached_file', 'meta_value' => "{$year}/{$month}/team.jpg"]);
    }

    public function uploadNewMediaWrongNameShouldFail(AcceptanceTester $I)
    {
        $I->loginAsAdmin();

        $I->amOnPage('/wp-admin/media-new.php');
        $I->waitForText('Upload New Media');

        $I->attachFile('input[type="file"]', 'team.jpg');

        $I->waitForElement('.edit-attachment', 20);
        $I->seeElement('.edit-attachment');
        $I->click('.edit-attachment');

        $I->seeUploadedFileFound('team-wrong.jpg','today');
    }

}