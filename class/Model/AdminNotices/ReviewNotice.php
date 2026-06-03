<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Admin notice asking for a review after two weeks of plugin usage.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class ReviewNotice extends \ShortPixel\Model\AdminNoticeModel
{
    /** @var string Unique notice key. */
    protected $key = 'MSG_REVIEW_REMINDER';

    /** @var int Keep this notice permanently until dismissed. */
    protected $suppress_delay = -1;

    /** @var string Severity level for this notice. */
    protected $errorLevel = 'normal';

    /**
     * Ensure activation date is available for the review timer.
     */
    public function load()
    {
        $activationDate = \wpSPIO()->settings()->activationDate;
        if (! $activationDate)
        {
            $legacyActivationDate = get_option('wp-short-pixel-activation-date');
            if ($legacyActivationDate)
            {
                $activationDate = intval($legacyActivationDate);
                \wpSPIO()->settings()->activationDate = $activationDate;
            }
        }

        parent::load();
    }

    /**
     * Trigger only after two weeks have passed since activation.
     *
     * @return bool True to show the notice, false to suppress it.
     */
    protected function checkTrigger()
    {
        $activationDate = \wpSPIO()->settings()->activationDate;
        if (! $activationDate)
        {
            return false;
        }

        if (time() < $activationDate + (14 * DAY_IN_SECONDS))
        {
            return false;
        }

        return true;
    }

    /**
     * Build the review prompt message.
     *
     * @return string HTML message string.
     */
    protected function getMessage()
    {
        $reviewUrl = 'https://wordpress.org/support/plugin/shortpixel-image-optimiser/reviews/';
        $reviewLink = '<a href="' . esc_url($reviewUrl) . '" target="_blank" rel="noopener noreferrer">WordPress.org</a>';
        $message = sprintf(
            /* translators: %s is a link to the plugin review page on WordPress.org. */
            __('If you are happy with ShortPixel, please leave us a review on %s. Your feedback helps us improve the plugin.', 'shortpixel-image-optimiser'),
            $reviewLink
        );

        return '<p>' . wp_kses_post($message) . '</p>';
    }
}
