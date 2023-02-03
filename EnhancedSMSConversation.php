<?php
namespace Stanford\EnhancedSMSConversation;

require_once "emLoggerTrait.php";
require_once "vendor/autoload.php";
require_once "classes/ConversationState.php";
require_once "classes/MessageContext.php";

use \REDCap;
use \Twilio\TwiML\MessagingResponse;

class EnhancedSMSConversation extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    const NUMBER_PREFIX = "NUMBER:";

    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
    }


    public function validateConfiguration() {
        // TODO: validate phone number field is validated as phone
        // TODO: make sure phone field exists (in event if longitudial)
    }


    public function redcap_email( string $to, string $from, string $subject, string $message, $cc, $bcc, $fromName, $attachments ) {
        //todo: intercept ASI emails, get context, create session
        $Context = new MessageContext($this);
        $this->emDebug("Context array for email is: ", $Context->getContextAsArray());
        // todo: if source is asi, then we want to decide if asi was for conversation, and if so go...
        if (false) {
            // This is an Enhanced SMS Survey
            $params = [
                // "instrument" => $Context->get
            ];

        }
        return true;
    }

    public function processInboundMessage() {
        // TODO: Validate message as Twilio
        try {
            // Confirm to number matches configured twilio number
            $twilio_number = $this->getProjectSetting('twilio-number');
            if ($_POST['To'] !== $twilio_number) {
                $error = "Received inbound message addressed to " . $_POST['To'] . " when project is configured to use $twilio_number";
                $this->emError($error);
                // Still try to process / could change to exception
                // throw new \Exception($error);
            }

            // See if record exists on project
            $from_number = $_POST['From'];
            if (empty($from_number)) {
                throw new \Exception("Missing required `From` number");
            }
            $record = $this->getRecordByNumber($from_number);

            // Check if there is an open conversation
            if ($cs = ConversationState::getActiveConversationByNumber($this, $from_number)) {
                $this->emDebug("Found conversation " . $cs->getId());
                $response = "Found conversation " . $cs->getId();
                $body = $_POST['body'];
                $cs->parseReply();
            } else {
                $this->emDebug("No conversation for this number");
                $response = "No conversations right now";
            }

        } catch (\Exception $e) {
            $this->emError("Exception thrown: " . $e->getMessage(), $e->getTraceAsString());
            $response = "We're sorry - but something went wrong on our end.";
        }

        // Create response
        $mr = new MessagingResponse();
        $mr->message($response);
        return $mr;
    }

    /**
     * Format a phone number
     * @param string $number
     * @param string $type E164 | redcap | digits
     * @return string
     */
    public function formatNumber($number, $type = "E164") {
        // REDCap stores numbers like '(650) 123-4567' -- convert to +16501234567
        $digits = preg_replace('/[^\d]/', '', $number);
        if ($type== "E164") {
            // For US, append a 1 to 10 digit numbers that dont start with a 1
            if (strlen($digits) === 10 && left($digits,1) != "1") {
                $digits = "1".$digits;
            }
            return "+".$digits;
        } elseif ($type == "redcap") {
            if (strlen($digits) === 11 && left($digits,1,) == "1") {
                // 16503803405
                $digits = mid($digits, 2, 10);
            }
            if (strlen($digits) === 10) {
                // 6503803405
                return "(" . mid($digits,1,3) . ") " . mid($digits,4,3) . "-" . mid($digits,7,4);
            }
        } elseif ($type == "digits") {
            return $digits;
        }
        $this->emDebug("Unable to parse $number to $digits into type $type - returning digits");
        return $digits;
    }


    /**
     * Try to pull a record by the phone number
     * @param $number
     * @return int|string|null
     */
    public function getRecordByNumber($number) {
        $phone_field = $this->getProjectSetting('phone-field');
        $phone_field_event_id = $this->getProjectSetting('phone-field-event-id');
        $fields = [REDCap::getRecordIdField()];
        $filter_logic = (REDCap::isLongitudinal() ? '[' . REDCap::getEventNames(true,true,$phone_field_event_id) . ']' : '') .
            '[' . $phone_field . '] = "' . $this->formatNumber($number,"redcap") . '"';

        $params = [
            'return_format' => 'array',
            'filterLogic' => $filter_logic,
            'fields' => $fields
        ];
        $results = REDCap::getData($params);

        if (count($results) > 1) {
            $this->emError("More than one record is registered with phone number $number: " . implode(",",array_keys($results)));
        }
        $result = empty($results) ? null : key($results);
        $this->emDebug("Query for $number", $result);
        return $result;
    }

    public function getNumberStatus($number) {
        $numberStatus = $this->getProjectSetting(self::NUMBER_PREFIX . $number);
        return $numberStatus;
    }

    public function setNumberStatus($number, $numberStatus) {
        $this->setProjectSetting(self::NUMBER_PREFIX . $number, $numberStatus);
    }






    /**
     * Handle inbound Twilio messages
     * @return void
     */
    public function parseInbound() {

//        $CS = ConversationState::findByPhone($phone);

    }


    public function scanConversationsCron( $cronParameters ) {

    }
}
