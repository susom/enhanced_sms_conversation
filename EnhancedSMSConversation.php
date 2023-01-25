<?php
namespace Stanford\EnhancedSMSConversation;

require_once "emLoggerTrait.php";
require_once "vendor/autoload.php";

class EnhancedSMSConversation extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public function redcap_email( string $to, string $from, string $subject, string $message, $cc, $bcc, $fromName, $attachments ) {
        //todo: intercept ASI emails, get context, create session
    }







    /**
     * Handle inbound Twilio messages
     * @return void
     */
    public function parseInbound() {

        $CS = ConversationState::findByPhone($phone);

    }


    public function scanConversationsCron( $cronParameters ) {

    }
}
