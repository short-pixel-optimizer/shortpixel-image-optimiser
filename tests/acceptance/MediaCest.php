<?php


class MediaCest
{
    public function uploadNewMediaStartsImageOptimization(\AcceptanceTester $I)
    {
        $year = date('Y');
        $month = date('m');
        $filename = 'team.jpg';

        $I->wantTo("Upload image to media library");
        $I->loginAsAdmin();
        $this->uploadMedia($I, $filename);

        $I->wantTo("Check image uploaded");
        $I->waitForElement(".edit-attachment", 20);
        $I->seeElement('.edit-attachment');
//        $I->click('.edit-attachment'); //TODO this goes to a new tab and exists the scenario context
//        $I->waitForText('Edit Media', 30, '.wp-heading-inline'); //TODO This is not found. New tab opened by previous click. Not workining
//        $I->switchToPreviousTab(0);
//        $I->closeTab();
        $I->seeUploadedFileFound('team.jpg', 'today');
//        $id = $I->grabAttributeFrom("#post_ID", "value");
//        $I->seePostMetaInDatabase(['post_id' => $id, 'meta_key' => '_wp_attached_file', 'meta_value' => "{$year}/{$month}/team.jpg"]);

        $I->wantTo("See short pixel optimization starting");
        $I->amOnPage('/wp-admin/upload.php?mode=list');
        $I->seeElement('#short-pixel-notice-toolbar');//, ['title' => 'ShortPixel optimizing... Please do not close this admin page.']);
        $I->see('Image waiting to be processed..');

        $I->comment("The test passed successfully. SPIO started optimizing image on upload.");
    }

    public function startBulkOptimization(\AcceptanceTester $I)
    {
        $I->wantTo('Have many images in the media library to optimize');
        //TODO dump sql with couple of images in media library

        $I->wantTo("Upload 3 images for bulk processing");

        $I->loginAsAdmin();
        $filename = 'team-bulk.jpg';
        for ($i = 3; $i > 0; $i--) {
            $this->uploadMedia($I, $filename);
        }
        $I->wait(10);

        $I->amOnPage('/wp-admin/upload.php?page=wp-short-pixel-bulk');
        $I->waitForText('Bulk Image Optimization by ShortPixel');
        $I->see('3');
        $I->see('15');
        $I->see('Start Optimizing 18 images', '.bulk-play');;

        $I->click('Start Optimizing 18 images');
        $I->see('Media Library and Custom folders optimization in progress ...');

//        $I->see('#bulk-progress'); //TODO Progress bar it's there but it cannot see it for some reason
//        $I->seeProgressBarValue('0%', 'progress-left');
//        $I->wait('10s');
//        $I->seeProgressBar('>30%');
//        $I->wait('30s');
//        $I->see('Analytics improvement 50%');
        //$I->see('Just optimized ...');

//        $I->expectTo("Finish the optimization of 5 images in 1 minute");
//        $I->waitForText('Congratulations!');
//
//        $I->amOnPage('/wp-admin/upload.php?mode=list');
//        $I->see('Reduced by');
//        TODO Future checks. After waiting for bulk to optimize all
//        $I->dontSee('Waiting to be optimized');
//        $I->dontSee('Malformed URL');
//        $I->dontSee('Error in image');
    }

    /**
     * @param AcceptanceTester $I
     * @param string $filename
     * @throws Exception
     */
    private function uploadMedia(AcceptanceTester $I, string $filename): void
    {
        $I->amOnPage('/wp-admin/media-new.php');
        $I->waitForText('Upload New Media');
//        $I->makeHtmlSnapshot("beforeUploadInMediaLibrary");
        $I->attachFile('input[type="file"]', $filename);
//        $I->makeHtmlSnapshot("afterUploadInMediaLibrary");
    }

}
