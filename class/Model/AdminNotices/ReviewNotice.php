<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * NPS-style survey asking active users for a rating after two weeks of usage.
 *
 * Flow:
 * - Click on a score button (1-10) submits it immediately via AJAX.
 * - Score 9-10 (promoters): a "leave a review" CTA to WordPress.org is shown.
 * - Score 1-8: a feedback textarea + Submit button is shown, so the user can
 *   describe what is missing/broken, without being pushed towards a public review.
 * - The standard WP notice-dismiss "X" (top-right, same as every other
 *   notice in the plugin) closes the survey permanently.
 *
 * State (answered/dismissed/score/feedback) is persisted in SettingsModel
 * (surveyStatus, surveyScore, surveyFeedback, surveyAnsweredAt) rather than
 * relying on the generic Notices dismiss/suppress mechanism, because that
 * mechanism only supports temporary suppression and cannot drive this
 * checkbox/textarea flow - see checkTrigger()/checkReset() below.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class ReviewNotice extends \ShortPixel\Model\AdminNoticeModel
{
    /** @var string Unique notice key. */
    protected $key = 'MSG_REVIEW_REMINDER';

    /** @var int Keep this notice around until our own checkReset() removes it. */
    protected $suppress_delay = -1;

    /** @var string Severity level for this notice. */
    protected $errorLevel = 'normal';

    /** @var string WP AJAX action name used for nonce creation/verification. */
    const AJAX_ACTION = 'shortpixel_survey_submit';

    /**
     * Ensure activation date is available for the review timer.
     *
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

        return parent::load();
    }

    /**
     * Trigger only after two weeks have passed since activation, and only
     * while the user hasn't answered or dismissed the survey yet.
     *
     * @return bool True to show the notice, false to suppress it.
     */
    protected function checkTrigger()
    {
        if ($this->getSurveyStatus() !== 'pending')
        {
            return false;
        }

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
     * Remove the notice permanently once the user has answered or dismissed it.
     *
     * @return bool True if the notice should be removed.
     */
    protected function checkReset()
    {
        return $this->getSurveyStatus() !== 'pending';
    }

    /**
     * Current survey status ('pending', 'answered', 'dismissed').
     *
     * @return string
     */
    private function getSurveyStatus()
    {
        $status = \wpSPIO()->settings()->surveyStatus;
        return $status ? $status : 'pending';
    }

    /**
     * Build the survey widget: rating buttons, conditional feedback form and
     * review CTA. The standard notice-dismiss "X" (outside this HTML,
     * rendered by NoticeModel) is wired up here too. All interaction happens
     * via a single AJAX endpoint (wp_ajax_shortpixel_survey_submit /
     * AjaxController::ajax_submitSurvey()).
     *
     * @return string HTML message string.
     */
    protected function getMessage()
    {
        $nonce = wp_create_nonce(self::AJAX_ACTION);
        $reviewUrl = 'https://wordpress.org/support/plugin/shortpixel-image-optimiser/reviews/?filter=5';

        $introText = esc_html__('How likely are you to recommend ShortPixel to a friend or colleague?', 'shortpixel-image-optimiser');
        $notLikelyText = esc_html__('Not likely', 'shortpixel-image-optimiser');
        $veryLikelyText = esc_html__('Very likely', 'shortpixel-image-optimiser');
        $feedbackLabel = esc_html__('Sorry to hear that. What could we improve, or what is missing?', 'shortpixel-image-optimiser');
        $submitText = esc_html__('Submit feedback', 'shortpixel-image-optimiser');
        $thanksReviewText = esc_html__('Thank you! Would you mind leaving us a public review?', 'shortpixel-image-optimiser');
        $reviewCtaText = esc_html__('Leave a review on WordPress.org', 'shortpixel-image-optimiser');
        $thanksFeedbackText = esc_html__('Thank you for your feedback, we will use it to improve the plugin.', 'shortpixel-image-optimiser');

        $buttons = '';
        for ($i = 1; $i <= 10; $i++)
        {
            $buttons .= '<button type="button" class="spio-survey-score" data-score="' . $i . '">' . $i . '</button>';
        }

        ob_start();
        ?>
        <div class="spio-survey" id="spio-survey-widget">
            <div class="spio-survey-step spio-survey-step-rating">
                <p><strong><?php echo $introText; ?></strong></p>
                <div class="spio-survey-scores"><?php echo $buttons; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                <div class="spio-survey-scale">
                    <span><?php echo $notLikelyText; ?></span>
                    <span><?php echo $veryLikelyText; ?></span>
                </div>
            </div>

            <div class="spio-survey-step spio-survey-step-feedback" style="display:none;">
                <p><strong><?php echo $feedbackLabel; ?></strong></p>
                <textarea id="spio-survey-feedback-text" rows="3" style="width:100%;" maxlength="2000"></textarea>
                <p><button type="button" class="button button-primary" id="spio-survey-submit-feedback"><?php echo $submitText; ?></button></p>
            </div>

            <div class="spio-survey-step spio-survey-step-review" style="display:none;">
                <p><?php echo $thanksReviewText; ?></p>
                <p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($reviewUrl); ?>"><?php echo $reviewCtaText; ?></a></p>
            </div>

            <div class="spio-survey-step spio-survey-step-thanks" style="display:none;">
                <p><?php echo $thanksFeedbackText; ?></p>
            </div>
        </div>
        <style>
            #MSG_REVIEW_REMINDER > .content { flex: 1 1 auto; width: 100%; }
            /* This is the ONE place that controls the widget's overall width - tweak the % here. */
            .spio-survey { font-family: Roboto, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; width: 100%; }

            /*width for below  scores and scale must be the same to align visually*/
            .spio-survey-scores { display: grid; grid-template-columns: repeat(10, 1fr); gap: 1px; margin: 2px 0; width: 30%; }
            .spio-survey-scores button.spio-survey-score {
                margin: 0; padding: 10px 0;
                border: 0; background: #1ABDCA; color: #fff;
                font-family: inherit; font-weight: 700; font-size: 15px;
                cursor: pointer; transition: background 0.1s ease-in-out;
            }
            .spio-survey-scores button.spio-survey-score:first-child { border-radius: 6px 0 0 6px; }
            .spio-survey-scores button.spio-survey-score:last-child { border-radius: 0 6px 6px 0; }
            .spio-survey-scores button.spio-survey-score:hover { background: #32d7e5; }
            .spio-survey-scores button.spio-survey-score.spio-selected,
            .spio-survey-scores button.spio-survey-score.spio-selected:hover { background: #116C7E; }

            .spio-survey-scale { display: grid; grid-template-columns: repeat(2, 1fr); width: 30%; font-size: 13px; color: #116C7E; }
            .spio-survey-scale span:first-child { grid-column: 1; text-align: left; }
            .spio-survey-scale span:last-child { grid-column: 2; text-align: right; }
            .spio-survey textarea#spio-survey-feedback-text { border: 1px solid #1ABDCA; }
        </style>
        <script>
        jQuery(function($) {
            var widget = $('#spio-survey-widget');
            if (widget.length === 0 || widget.data('spio-bound')) { return; }
            widget.data('spio-bound', true);

            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var ajaxAction = <?php echo wp_json_encode(self::AJAX_ACTION); ?>;
            var currentScore = null;

            function showStep(step) {
                widget.find('.spio-survey-step').hide();
                widget.find('.spio-survey-step-' + step).show();
            }

            function postSurvey(data, onSuccess) {
                data.action = ajaxAction;
                data.nonce = nonce;
                $.post(ajaxurl, data, function(response) {
                    if (response && response.status && typeof onSuccess === 'function') {
                        onSuccess(response);
                    }
                });
            }

            widget.on('click', '.spio-survey-score', function(e) {
                e.preventDefault();
                currentScore = $(this).data('score');
                widget.find('.spio-survey-score').removeClass('spio-selected');
                $(this).addClass('spio-selected');

                postSurvey({ survey_action: 'rating', rating: currentScore }, function(response) {
                    if (response.cta === 'review') {
                        showStep('review');
                    } else {
                        showStep('feedback');
                    }
                });
            });

            widget.on('click', '#spio-survey-submit-feedback', function(e) {
                e.preventDefault();
                var feedbackText = widget.find('#spio-survey-feedback-text').val();

                postSurvey({ survey_action: 'feedback', rating: currentScore, feedback: feedbackText }, function() {
                    showStep('thanks');
                });
            });

            $('#MSG_REVIEW_REMINDER .notice-dismiss').on('click', function() {
                postSurvey({ survey_action: 'dismiss' });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
}
