<?php
namespace Stanford\EnhancedSMSConversation;

require_once "emLoggerTrait.php";
require_once "vendor/autoload.php";
require_once "classes/ConversationState.php";
require_once "classes/MessageContext.php";
require_once "classes/FormManager.php";

use \REDCap;
use \Twilio\TwiML\MessagingResponse;

class EnhancedSMSConversation extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;


    const NUMBER_PREFIX = "NUMBER:";

    const ACTION_TAG_PREFIX = "@ESC";
    const ACTION_TAG_IGNORE_FIELD = "@ESC_IGNORE";

    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
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
        //xdebug_break();
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

        //     // This is an Enhanced SMS Survey
        //     $params = [
        //         // "instrument" => $Context->get
        //     ];
        //
        // }


        // 1. get record-id + event + survey_id
        $mc_context = $MC->getContextAsArray();
        $record_id  =  $mc_context['record_id'];
        $event_id   =  $mc_context['event_id'];
        $instrument =  $mc_context['instrument'];
        $survey_id  =  $mc_context['survey_id'];

        try {
            // 2. get the withdrawn status of this record_id
            $study_withdrawn = $this->isWithdrawn($record_id);

            // 3. get the SMS optout status of this record_id
            $sms_opt_out = $this->isOptedOut($record_id);

            // 4. get the telephone number by record_id
            $cell_number = $this->getRecordPhoneNumber($record_id);
        } catch (ConfigSetupException $cse) {
            REDCap::logEvent("EM Config not setup. Check with admin.");
        }

        // they have opted out of SMS, do nothing
        if ($sms_opt_out OR $study_withdrawn) {
            return true;
        }

//5. Clear out any existing states for this record in the state table
//   If it comes through the email, then we should start from blank state.
//TODO:

//6. get the first sms to send
        $fm = new FormManager($this, $instrument, $event_id, $this->getProjectId());
        $sms_to_send_list = $fm->getNextSMS('', $record_id, $mc_context['event_name']);

//6. SEND SMS
//TODO:



        return true;
    }

    /**
     * Get the completion timestamp if it exists
     *
     * @param int $survey_id
     * @param string $record
     * @param int $instance
     * @return string | false
     */
    public function getSurveyCompletionTimestamp($survey_id, $event_id, $record, $instance) {
        //TODO: TEST
        $sql = "select rsr.completion_time
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
     * Get the completion timestamp to now()
     *
     * @param int $survey_id
     * @param string $record
     * @param int $instance
     * @return void
     */
    public function setSurveyCompletionTimestamp($survey_id, $event_id, $record, $instance) {
        //TODO: TEST
        $sql = "update redcap_surveys_response rsr
            set rsr.completion_time = NOW()
            join redcap_surveys_participants rsp on rsp.participant_id = rsr.participant_id
            where   rsp.survey_id = ?
                and rsp.event_id = ?
                and rsr.record = ?
                and rsr.instance = ?";
        $instance = is_null($instance) ? 1 : $instance;
        $q = $this->query($sql, [$survey_id, $event_id, $record, $instance]);
        $this->emDebug($q);
    }


    public function isOptedOut($record_id) {
        // TODO:
        $sms_opt_out = $this->getFieldData($record_id, 'sms-opt-out-field', 'sms-opt-out-field-event-id' );

        $this->emDebug("Query for SMS opt out: ",$sms_opt_out);
        $return = $sms_opt_out["1"];
        return $return;
    }


    public function isWithdrawn($record_id) {
        $withdrawn = $this->getFieldData($record_id, 'study-withdrawn-field', 'study-withdrawn-field-event-id' );

        $this->emDebug("Query for withdrawn: ", $withdrawn);
        $return = $withdrawn["1"];
        return $return;
    }

    public function getRecordPhoneNumber($record_id) {
        $number = $this->getFieldData($record_id, 'phone-field', 'phone-field-event-id' );

        $this->emDebug("Query for number: $number");
        if ($number) {
            $number = $this->formatNumber($number);
        }

        return $number;
    }

    public function optOutSMS($record_id) {
        $optout_field = $this->getProjectSetting('sms-opt-out-field');
        $optout_field_event_id = $this->getProjectSetting('sms-opt-out-field-event-id');

        if (empty($optout_field) && empty($optout_field_event_id)) {
            throw new ConfigSetupException("EM Configuration is not complete. Please check the EM setup.");
        }

        $data = array(
            REDCap::getRecordIdField() => $record_id,
            'redcap_event_name' => REDCap::getEventNames(true, false, $optout_field_event_id),
            $optout_field. "___1" => 1
        );

        $this->emDebug("saved opt out", $data);
        $response = REDCap::saveData('json', json_encode(array($data)));
        $this->emDebug("saved opt out", $response['errors']);
        if (empty($response['errors'])) {
            return true;
        } else {
            return false;
        }
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
            if (empty($record)) {
                REDCap::logEvent("Received text from $from_number", "This number is not found in this project. Ignoring...");
                return;
            }

            $body = $_POST['Body'];


            $msg = null;
            switch (strtoupper($body)) {
                case null:
                    $msg = "Received a null text from ". $from_number;
                    break;
                case '':
                    $msg = "Received an empty text from ". $from_number;
                    break;
                case 'STOP':
                    $msg = "Received a STOP message from $from_number. Texts will no longer be sent to this record: $record";
                    break;
                case 'OPT-OUT':
                    if ($this->optOutSMS($record)) {
                        $msg = "Received an OPTOUT message from $from_number. Texts will no longer be sent to this record: $record";
                    } else {
                        $msg = "Received an OPTOUT message from $from_number but was unable to automatically save that information. Please consult admin.";
                    }

                    break;
            }

            //No need to check state for this message. Log event and return
            if ($msg) {
                REDCap::logEvent("Received text from $from_number", $msg);
                return;
            }

            // TODO: Get opt-out-sms status for number
            if ($this->isWithdrawn($record) OR $this->isOptedOut($record)) {
                REDCap::logEvent("Received text from $record", "Received:  $body. No further action since withdrawn");
                return;
            }


            // Check if there is an open conversation
            if ($cs = ConversationState::getActiveConversationByNumber($this, $from_number)) {
                $this->emDebug("Found conversation " . $cs->getId());
                $response = "Found conversation " . $cs->getId();

                $cs->parseReply($record, $body);
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
