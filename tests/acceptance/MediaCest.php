<?php


class MediaCest
{
    public function uploadNewMedia(\AcceptanceTester $I)
    {
        $year  = date('Y');
        $month = date('m');

        $I->loginAsAdmin();

        $I->amOnPage('/wp-admin/media-new.php');
//        $I->waitForText('Upload New Media');

        $I->makeHtmlSnapshot("beforeUploadInMediaLibrary");
        $I->attachFile('input[type="file"]', 'team.jpg');

        $I->seeElement('.edit-attachment');
        $I->click('.edit-attachment');

        $I->seeUploadedFileFound('team.jpg','today');

        $postTable = $I->grabPrefixedTableNameFor('postmeta');
        $id = $I->grabAllFromDatabase($postTable, 'post_id', ['meta_value', '{$year}/{$month}/team.jpg']);

        $I->seePostMetaInDatabase(['post_id' => $id, 'meta_key' => '_wp_attached_file', 'meta_value' => "{$year}/{$month}/team.jpg"]);

        $idFrontEnd = $I->grabAttributeFrom("#post_ID", "value");

        assertEquals($id, $idFrontEnd);
    }

    //TODO This test should fail in pipeline, but doesn't seem to be run at all.
    /**
     *
     */
    public function uploadNewMediaWrongNameShouldFail(\AcceptanceTester $I, $scenario)
    {
        $year  = date('Y');
        $month = date('m');

        $scenario->skip("Skipping for now");

        $I->loginAsAdmin();

        $I->amOnPage('/wp-admin/media-new.php');

        $I->waitForElement('input[type="file', 20);
        $I->attachFile('input[type="file"]', 'team.jpg');

        $I->waitForElement('.edit-attachment', 20);
        $I->seeElement('.edit-attachment');
        $I->click('.edit-attachment');

        $postTable = $I->grabPrefixedTableNameFor('postmeta');
        $id = $I->grabAllFromDatabase($postTable, 'post_id', ['meta_value', '{$year}/{$month}/team.jpg']);

        $I->seeUploadedFileFound('team-wrong.jpg','today');
        $I->seePostMetaInDatabase(['post_id' => $id, 'meta_key' => '_wp_attached_file', 'meta_value' => "{$year}/{$month}/team-wrong.jpg"]);

        $idFrontEnd = $I->grabAttributeFrom("#post_ID", "value");

        assertEquals($id, $idFrontEnd);

        $I->fail("The previous statement should fail, no team-wrong.jpg should be available");
    }

}