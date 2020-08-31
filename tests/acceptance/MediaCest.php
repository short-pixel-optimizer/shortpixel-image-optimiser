<?php


class MediaCest
{
    public function uploadNewMediaStartsImageOptimization(\AcceptanceTester $I)
    {
        $year  = date('Y');
        $month = date('m');

        $I->wantTo("Upload image to media library");
        $I->loginAsAdmin();
        $I->amOnPage('/wp-admin/media-new.php');
        $I->waitForText('Upload New Media');
        $I->makeHtmlSnapshot("beforeUploadInMediaLibrary");
        $I->attachFile('input[type="file"]', 'team.jpg');
        $I->makeHtmlSnapshot("afterUploadInMediaLibrary");

        $I->wantTo("Check image uploaded");
        $I->waitForElement(".edit-attachment", 20);
        $I->seeElement('.edit-attachment');
//        $I->click('.edit-attachment'); //TODO this goes to a new tab and exists the scenario context
//        $I->waitForText('Edit Media', 30, '.wp-heading-inline'); //TODO This is not found. New tab opened by previous click. Not workining
//        $I->switchToPreviousTab(0);
//        $I->closeTab();
        $I->seeUploadedFileFound('team.jpg','today');
//        $id = $I->grabAttributeFrom("#post_ID", "value");
//        $I->seePostMetaInDatabase(['post_id' => $id, 'meta_key' => '_wp_attached_file', 'meta_value' => "{$year}/{$month}/team.jpg"]);

        $I->wantTo("See short pixel optimization starting");
        $I->amOnPage('/wp-admin/upload.php?mode=list');
        $I->seeElement('#short-pixel-notice-toolbar');//, ['title' => 'ShortPixel optimizing... Please do not close this admin page.']);
        $I->see('Image waiting to be processed..');

        $I->comment("The test passed successfully. SPIO started optimizing image on upload.");
    }

}