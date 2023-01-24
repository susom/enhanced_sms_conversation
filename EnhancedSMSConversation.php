<?php
namespace Stanford\EnhancedSMSConversation;

class EnhancedSMSConversation extends \ExternalModules\AbstractExternalModule {
    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public function redcap_email( string $to, string $from, string $subject, string $message, $cc, $bcc, $fromName, $attachments ) {

    }


    public function scanConversationsCron( $cronParameters ) {

    }
}
