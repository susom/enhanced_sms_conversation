<?php
namespace Stanford\EnhancedSMSConversation;

require_once "emLoggerTrait.php";
require_once "vendor/autoload.php";
require_once "classes/ConversationState.php";
require_once "classes/MessageContext.php";
require_once "classes/FormManager.php";

use \REDCap;
use \Twilio\TwiML\MessagingResponse;
use \Twilio\Rest\Client;

class EnhancedSMSConversation extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    public $TwilioClient;
    public $twilio_number;

    const NUMBER_PREFIX = "NUMBER:";

    const ACTION_TAG_PREFIX = "@ESC";
    const ACTION_TAG_IGNORE_FIELD = "@ESC_IGNORE";

    const VALID_SURVEY_TIMESTAMP_STAGES = ['completion_time','start_time', 'first_submit_time'];

    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    /**
     * @return Client TwilioClient
     * @throws \Twilio\Exceptions\ConfigurationException
     */
    public function getTwilioClient() {
        if (empty($this->TwilioClient)) {
            $sid = $this->getProjectSetting('twilio-sid');
            $token = $this->getProjectSetting('twilio-token');
            $this->TwilioClient = new Client($sid, $token);
        }
        return $this->TwilioClient;
    }

    /**
     * Get the twilio number from the project settings.
     * @return mixed
     */
    public function getTwilioNumber() {
        if (empty($this->twilio_number)) {
            $this->twilio_number = $this->formatNumber($this->getProjectSetting('twilio-number'));
        }
        return $this->twilio_number;
    }


    public function validateConfiguration() {
        // TODO: validate phone number field is validated as phone
        // TODO: make sure phone field exists (in event if longitudial)
        // Has designated email field
        // TODO: make sure opt-out field exists and is of type text
        // TODO: make sure withdraw field exists in event

    }

    /**
     * Hijacking redcap_email hook to send texts rather than the project's ASI.
     * Convention is to add @ESMS to the subject line that this is to be hijacked.
     *
     * Any entry by this method signals a new state. Existing state should be cleared.
     *
     * @param $to
     * @param $from
     * @param $subject
     * @param $message
     * @param $cc
     * @param $bcc
     * @param $fromName
     * @param $attachments
     * @return true
     * @throws \Exception
     */
    public function redcap_email($to, $from, $subject, $message, $cc, $bcc, $fromName, $attachments) {
        //todo: intercept ASI emails, get context, create session

        // Exit if this is not an @ESMS email
        if (strpos($subject, "@ESMS") === false) return true;

        $this->emDebug("This email is an ESMS email");
        $MC = new MessageContext($this);
        $this->emDebug("Context array for email is: ", $MC->getContextAsArray(), PAGE);

        /*
            // Immediate ASI works:
            [source] => ASI
            [source_id] => 177
            [project_id] => 81
            [record_id] => 17
            [event_id] => 167
            [instance] => 1
            [event_name] => event_1_arm_1
            [instrument] => survey_1
            [survey_id] => 177

            // immediate alert - instgrument isn't correct
            [source] => Alert
            [source_id] => 51
            [project_id] => 81
            [record_id] => 18
            [event_id] => 167
            [instance] => 1
            [event_name] => event_1_arm_1
            [instrument] => record_information
            [survey_id] =>
        */

        // 1. get record-id + event + survey_id
        $mc_context = $MC->getContextAsArray();
        $project_id = $mc_context['project_id'];
        $record_id  = $mc_context['record_id'];
        $event_id   = $mc_context['event_id'];
        $instrument = $mc_context['instrument'];
        $survey_id  = $mc_context['survey_id'];

        try {
            $errors = [];

            // 2. get the withdrawn status of this record_id
            if ($this->isWithdrawn($record_id, $project_id)) {
                // Do not create a new CS
                $errors[] = "New conversation for record $record_id aborted because record is withdrawn";
            }

            // 3. get the SMS optout status of this record_id
            if ($this->isOptedOut($record_id, $project_id)) {
                // Do not create a new CS
                $errors[] = "New conversation for record $record_id aborted because record is opted out";
            }

            // 4. get the telephone number by record_id
            $cell_number = $this->getRecordPhoneNumber($record_id, $project_id);
            if (empty($cell_number)) {
                $errors[] = "Invalid or missing phone number for record $record_id";
            }

            // If there are any reasons not to proceed, then log them and abort the email.
            if (!empty($errors)) {
                $this->emDebug($errors);
                REDCap::logEvent(implode("\n",$errors),"","",$record_id,$event_id,$project_id);
                return false;
            }

        } catch (ConfigSetupException $cse) {
            // TODO REVIEW
            REDCap::logEvent("EM Config not setup. Check with admin.");
            return false;
        }

        // Create a new Conversation State
        $CS = new ConversationState($this);
        $CS->setValues([
            "instrument"    => $instrument,
            "event_id"      => $event_id,
            "instance"      => $mc_context['instance'] ?? 1,
            "cell_number"        => $cell_number
            // "current_field" => "",
            // "state"         => "ACTIVE"
            //    "project_id", $project_id
        ]);

        //5. Clear out any existing states for this record in the state table
        $CS->closeExistingConversations();
        $CS->setState("ACTIVE");
        $CS->setExpiryTs();
        $CS->setReminderTs();
        $CS->save();

        $CS->sendCurrentMessages();

        // Do not send the actual email
        return false;
    }


    /**
     * Get the completion timestamp if it exists
     *
     * @param int $survey_id
     * @param string $record
     * @param int $instance
     * @param string $stage completion_time
     * @return string | false
     */
    public function getSurveyTimestamp($survey_id, $event_id, $record, $instance, $stage = "completion_time") {
        //TODO: TEST
        $stage = strtolower($stage);
        if (!in_array($stage, self::VALID_SURVEY_TIMESTAMP_STAGES)) {
            $this->emError("Invalid stage: $stage");
            return false;
        }
        $sql = "select rsr." . $stage . "
            from redcap_surveys_response rsr
            join redcap_surveys_participants rsp on rsp.participant_id = rsr.participant_id
            where   rsp.survey_id = ?
                and rsp.event_id = ?
                and rsr.record = ?
                and rsr.instance = ?";
        $instance = is_null($instance) ? 1 : $instance;
        $q = $this->query($sql, [$survey_id, $event_id, $record, $instance]);
        if ($row = $q->fetch_assoc()) {
            $this->emDebug($row);
            $result = $row['completion_time'] ?? false;
        } else {
            $result = false;
        }
        return $result;
    }


    /**
     * Set the survey timestamp to value or now if omitted
     *
     * @param int $survey_id
     * @param string $record
     * @param int $instance
     * @param string $stage
     * @param string $datetime
     * @return void
     */
    public function setSurveyTimestamp($survey_id, $event_id, $record, $instance, $stage = "completion_time", $datetime = null) {
        //TODO: TEST
        $stage = strtolower($stage);
        if (!in_array($stage, self::VALID_SURVEY_TIMESTAMP_STAGES)) {
            $this->emError("Invalid stage: $stage");
            return false;
        }
        if (is_null($datetime)) {
            $datetime = date("Y-m-d H:i:s");
        } else {
            $datetime = date("Y-m-d H:i:s", strtotime($datetime));
        }

        $sql = "update redcap_surveys_response rsr
            set rsr." . $stage . " = ?
            join redcap_surveys_participants rsp on rsp.participant_id = rsr.participant_id
            where   rsp.survey_id = ?
                and rsp.event_id = ?
                and rsr.record = ?
                and rsr.instance = ?";
        $instance = is_null($instance) ? 1 : $instance;
        $q = $this->query($sql, [$datetime, $survey_id, $event_id, $record, $instance]);
        $this->emDebug($q);
    }


    /**
     * Determine if a record has opted out to SMS communication
     * @param $record_id
     * @return mixed
     * @throws ConfigSetupException
     */
    public function isOptedOut($record_id) {
        // TODO:
        $sms_opt_out = $this->getFieldData($record_id, 'sms-opt-out-field', 'sms-opt-out-field-event-id' );

        $this->emDebug("Query for SMS opt out: ",$sms_opt_out);
        $return = $sms_opt_out["1"] == 1;
        return $return;
    }


    /**
     * Determine if a record is withdrawn
     * @param $record_id
     * @return bool
     * @throws ConfigSetupException
     */
    public function isWithdrawn($record_id) {
        $withdrawn = $this->getFieldData($record_id, 'study-withdrawn-field', 'study-withdrawn-field-event-id' );

        $this->emDebug("Query for withdrawn: ", $withdrawn);
        $return = $withdrawn["1"] == 1;
        return $return;
    }


    /**
     * Get the phone number for a record based on the project settings
     * @param $record_id
     * @return mixed|string
     * @throws ConfigSetupException
     */
    public function getRecordPhoneNumber($record_id) {
        $number = $this->getFieldData($record_id, 'phone-field', 'phone-field-event-id' );

        $this->emDebug("Query for number: $number");
        if ($number) {
            $number = $this->formatNumber($number);
        }
        return $number;
    }


    /**
     * @param $record_id
     * @param $this_field_config
     * @param $this_field_event_id_config
     * @return mixed
     * @throws ConfigSetupException
     */
    private function getFieldData($record_id, $this_field_config, $this_field_event_id_config) {
        $this_field = $this->getProjectSetting($this_field_config);
        $this_field_event_id = $this->getProjectSetting($this_field_event_id_config);
        $fields = [REDCap::getRecordIdField(), $this_field];

        if (empty($this_field) && empty($this_field_event_id)) {
            throw new ConfigSetupException("EM Configuration is not complete. Please check the EM setup.");
        }

        $params = [
            'return_format' => 'array',
            'events'        => $this_field_event_id,
            'fields'        => $fields,
            'records'       => array($record_id)
        ];
        $results = REDCap::getData($params);
        $return_field = $results[$record_id][$this_field_event_id][$this_field];

        return $return_field;
    }

    /**
     * Process Inbound SMS
     * @return MessagingResponse
     */
    public function processInboundMessage() {
        // TODO: Validate message as Twilio
        try {
            // Confirm to number matches configured twilio number
            $twilio_number = $this->getTwilioNumber();
            if ($_POST['To'] !== $twilio_number) {
                $error = "Received inbound message addressed to " . $_POST['To'] . " when project is configured to use $twilio_number";
                $this->emError($error);
                // Still try to process / could change to exception
                // throw new \Exception($error);
            }

            // See if record exists on project
            // TODO: Do I need to do this?
            $from_number = $_POST['From'];
            if (empty($from_number)) {
                throw new \Exception("Missing required `From` number");
            }
            $record_id = $this->getRecordIdByCellNumber($from_number);

            // Check if there is an open conversation
            if ($CS = ConversationState::getActiveConversationByNumber($this, $this->formatNumber($from_number))) {
                $this->emDebug("Found conversation " . $CS->getId());
                $response = "Found conversation " . $CS->getId();
                //$body = $_POST['body'];
                $CS->parseReply();
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
    public function getRecordIdByCellNumber($number) {
        $phone_field = $this->getProjectSetting('phone-field');
        $phone_field_event_id = $this->getProjectSetting('phone-field-event-id');
        $number_in_redcap_format = $this->formatNumber($number, 'redcap');

        $fields = [REDCap::getRecordIdField()];
        $filter_logic = (REDCap::isLongitudinal() ? '[' . REDCap::getEventNames(true,true,$phone_field_event_id) . ']' : '') .
            '[' . $phone_field . '] = "' . $number_in_redcap_format . '"';

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






    /**
     *
     * Parses a string for arrays of actiontags (optinally filtering by the supplied tag)
     *
     * @param $string           The string to be parsed for actiontags (in the format of <code>@FOO=BAR or @FOO={"param":"bar"}</code>
     * @param null $tag_only    If you wish to select a single tag
     * @return array|bool       returns the match array with the key equal to the tag and an array containing keys of 'params, params_json and params_text'
     */
    function parseActionTags($string, $tag_only = null)
    {
        $re = "/  (?(DEFINE)
     (?<number>    -? (?= [1-9]|0(?!\\d) ) \\d+ (\\.\\d+)? ([eE] [+-]? \\d+)? )
     (?<boolean>   true | false | null )
     (?<string>    \" ([^\"\\\\\\\\]* | \\\\\\\\ [\"\\\\\\\\bfnrt\\/] | \\\\\\\\ u [0-9a-f]{4} )* \" )
     (?<array>     \\[  (?:  (?&json)  (?: , (?&json)  )*  )?  \\s* \\] )
     (?<pair>      \\s* (?&string) \\s* : (?&json)  )
     (?<object>    \\{  (?:  (?&pair)  (?: , (?&pair)  )*  )?  \\s* \\} )
     (?<json>      \\s* (?: (?&number) | (?&boolean) | (?&string) | (?&array) | (?&object) )  ) \\s*
     (?<tag>       \\@(?:[[:alnum:]])*)
    )

    (?'actiontag'
    (?:\\@(?:[[:alnum:]_-])*)
    )
    (?:\\=
    (?:
     (?'params'
      (
       (?:(?'params_json'(?&json)))
       |
       (?:(?'params_text'(?:[[:alnum:]_-]+)))
      )
     )
    )
    )?/ixm";

        preg_match_all($re, $string, $matches);

        // Return false if none are found
        if (count($matches['actiontag']) == 0) return false;

        $result = array();

        foreach ($matches['actiontag'] as $i => $tag) {
            $tag = strtoupper($tag);
            if ($tag_only && ($tag != strtoupper($tag_only))) continue;
            $result[$tag] = array(
                'params' => $matches['params'][$i],
                'params_json' => $matches['params_json'][$i],
                'params_text' => $matches['params_text'][$i]
            );
        }

        return $result;
    }



}
