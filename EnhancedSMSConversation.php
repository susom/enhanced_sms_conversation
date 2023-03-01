<?php
namespace Stanford\EnhancedSMSConversation;

require_once "emLoggerTrait.php";
require_once "vendor/autoload.php";
require_once "classes/ConversationState.php";
require_once "classes/MessageContext.php";
require_once "classes/FormManager.php";
require_once "classes/TwilioManager.php";
require_once "classes/CustomExceptions.php";

use \REDCap;
use \Project;
use \Twilio\TwiML\MessagingResponse;
use \Exception;

class EnhancedSMSConversation extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    public $twilio_number;

    public $TwilioManager;

    const NUMBER_PREFIX = "NUMBER:";

    const ACTION_TAG_PREFIX = "@ESC";
    const ACTION_TAG_IGNORE_FIELD = "@ESC_IGNORE";

    const ACTION_TAG_VALIDATION_MESSAGE = "@ESC_VALIDATION_MESSAGE";

    const DEFAULT_OPT_IN_MESSAGE = "You have been opted-in to SMS communication.  Reply with 'STOP' or 'UNSUBSCRIBE' to be removed.";
    const DEFAULT_OPT_OUT_MESSAGE = "You have opted out to SMS communications.  Reply with 'START' to resume using SMS.";

    const LAST_RESPONSE_EXPIRY_DELAY_SEC = 180; // If it has been less than this time since a response, do not expire a conversation yet.

    const VALID_SURVEY_TIMESTAMP_STAGES = ['completion_time','start_time', 'first_submit_time'];

    const OPT_OUT_KEYWORDS = ['stop', 'optout'];
    const OPT_IN_KEYWORDS = ['start'];
    public function __construct() {
        parent::__construct();
        // Other code to run when object is instantiated
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
        // Exit if this is not an @ESMS email
        if (strpos($message, "@ESMS") === false) return true;

        $this->emDebug("This email is an ESMS email");

        // We determine the project/record/etc from a complex helper object
        $MC = new MessageContext($this);
        $mc_context = $MC->getContextAsArray();
        $this->emDebug("EMAIL context: " . PAGE, $mc_context);

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
            if ($this->getRecordOptOutStatus($record_id, $project_id)) {
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

        //5. Clear out any existing states for this record in the state table
        //   If it comes through the email, then we should start from blank state.
        if ($found_cs = ConversationState::getActiveConversationByNumber($this, $cell_number)) {
            $id = $found_cs->getId();
            $this->emDebug("Found record $id. Closing this conversation...");
            $found_cs->expireConversation();
            $found_cs->save();
        }


        //6. get the first sms to send
        $fm = new FormManager($this, $instrument, $event_id, $project_id);
        $sms_to_send_list = $fm->getNextSMS('', $record_id, $event_id);
        $active_field     = $fm->getActiveQuestion($sms_to_send_list);


        //7. Set the state table
        // Create a new Conversation State
        $CS = new ConversationState($this);
        $CS->setValues([
            "record_id"     => $record_id,
            "instrument"    => $instrument,
            "event_id"      => $event_id,
            "instance"      => $mc_context['instance'] ?? 1,
            "cell_number"   => $cell_number,
            "current_field" => $active_field
        ]);
        $CS->setState("ACTIVE");
        $CS->setExpiryTs($project_id);
        $CS->setReminderTs($project_id);
        $CS->save();

        //6. SEND SMS
        $tm = $this->getTwilioManager($project_id);
        foreach ($sms_to_send_list as $k => $v) {
            $msg = $v['field_label'];
            $tm->sendTwilioMessage($cell_number, $msg);
        }

        // Do not send the actual email
        return false;
    }


    /**
     * Only show the test page for a super-user
     * @param $project_id string
     * @param $link array
     * @return void|null
     */
    public function redcap_module_link_check_display($project_id, $link) {
        $this->emDebug("Checking for $project_id",$link);
        if (isset($link['superuseronly']) && $link['superuseronly']) {
            if (!$this->getUser()->isSuperUser()) return null;
        }
        return $link;
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


    public function validateConfiguration() {
        // TODO: validate phone number field is validated as phone
        // TODO: make sure phone field exists (in event if longitudial)
        // Has designated email field
        // TODO: make sure opt-out field exists and is of type text
        // TODO: make sure withdraw field exists in event

    }


    /**
     * @param $project_id
     * @return TwilioManager
     * @throws \Exception
     */
    public function getTwilioManager($project_id) {
        if ($this->TwilioManager === null) {
            $this->TwilioManager = new TwilioManager($this, $project_id);
        }
        return $this->TwilioManager;
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
     * @param $project_id
     * @return boolean True if opted out, false if still in.
     * @throws ConfigSetupException
     */
    public function getRecordOptOutStatus($record_id, $project_id) {
        $sms_opt_out = $this->getFieldDataFromConfigSettings($record_id, 'sms-opt-out-field', 'sms-opt-out-field-event-id', $project_id ) ?? '';
        return $sms_opt_out !== '';
    }


    /**
     * Determine if a record is withdrawn using the study-withdrawn-logic
     * @param string $record_id
     * @param int $project_id
     * @return bool
     */
    public function isWithdrawn($record_id, $project_id) {
        if( $withdrawn_logic = $this->getProjectSetting('study-withdrawn-logic') ?? false ) {
            $result = REDCap::evaluateLogic($withdrawn_logic, $project_id, $record_id) == "1";
            if ($result) $this->emDebug("$record_id is withdrawn");
        } else {
            $result = false;
        };
        return $result;
    }


    /**
     * Get the phone number in E164 format for a record based on the project config settings
     * @param $record_id
     * @return string
     * @throws ConfigSetupException
     */
    public function getRecordPhoneNumber($record_id, $project_id) {
        $number = $this->getFieldDataFromConfigSettings($record_id, 'phone-field', 'phone-field-event-id', $project_id);
        // $this->emDebug("Query for number: $number");
        if ($number) {
            $number = $this->formatNumber($number);
        }
        return $number;
    }



    /**
     * Change the record opt-in/opt-out status and send a confirming text message to record.
     * @param $record_id
     * @param bool $opt_out
     * @return string returns empty if something went wrong
     * @throws ConfigSetupException
     */
    public function setRecordOptOutStatus($record_id, $opt_out = true) {
        $this_field_config = 'sms-opt-out-field';
        $this_field_event_id_config = 'sms-opt-out-field-event-id';
        $this_field = $this->getProjectSetting($this_field_config);
        $this_field_event_id = $this->getProjectSetting($this_field_event_id_config);
        if (empty($this_field) && empty($this_field_event_id)) {
            throw new ConfigSetupException("EM Configuration is not complete. Please check the EM setup around $this_field_config and $this_field_event_id_config");
        }

        $value = $opt_out ? date("Y-m-d H:i:s") : '';
        $data = [ $record_id => [ $this_field_event_id => [ $this_field => $value ]]];

        // Switching from checkbox to text value
        // $checkbox_value = $opt_out ? 1 : 0;
        // $data = [
        //     $record_id => [
        //         $this_field_event_id => [
        //             $this_field[1] => $checkbox_value ]
        //     ]
        // ];

        $params = [
            'data'=>$data,
            'overwriteBehavior'=>'overwrite'
        ];

        $response = REDCap::saveData($params);

        if (empty($response['errors'])) {
            $this->emDebug("Updated record $record_id opt-out status to $value");

            // TODO: send message to confirm change - since this can only be updated via an inbound message,
            // we can use the reply to return the message.
            // $TM = $this->getTwilioManager();
            // $number = $this->getRecordPhoneNumber($record_id, $this->getProjectId());
            $sms = $opt_out ? $this->getOptOutMessage() : $this->getOptInMessage();
            // $TM->sendTwilioMessage($number, $message);
            return $sms;
        } else {
            $this->emError("Updated record $record_id opt-out status to $value WITH ERRORS:", $response);
            return "";
        }
    }


    /**
     * Given a config field_name and event_id setting, pull the resulting data from the project for the specified record
     * @param $record_id
     * @param $this_field_config
     * @param $this_field_event_id_config
     * @return mixed
     * @throws ConfigSetupException
     */
    private function getFieldDataFromConfigSettings($record_id, $this_field_config, $this_field_event_id_config, $project_id) {
        // We need the table_pk from Proj for getData to be cron safe
        global $Proj;
        $_Proj = $Proj->project_id == $project_id ? $Proj : new Project($project_id);

        $this_field          = $this->getProjectSetting($this_field_config, $project_id);
        $this_field_event_id = $this->getProjectSetting($this_field_event_id_config, $project_id);
        $record_id_field     = $_Proj->table_pk;

        $fields = [$record_id_field, $this_field];  //TODO: I don't think we need the recorD_id field, do we?  Can we remove this and the $Proj stuff?

        if (empty($this_field) && empty($this_field_event_id)) {
            throw new ConfigSetupException("EM Configuration is not complete. Please check the EM setup for $this_field_config / $this_field_event_id_config.");
        }

        $params = [
            'project_id'    => $project_id,
            'return_format' => 'array',
            'events'        => $this_field_event_id,
            'fields'        => $fields,
            'records'       => array($record_id)
        ];
        $results = REDCap::getData($params);
        $return_field = $results[$record_id][$this_field_event_id][$this_field] ?? "";
        return $return_field;
    }


    /**
     * Process Inbound SMS
     *  - ASSUMES PROJECT CONTEXT
     * @return string Response or empty string
     */
    public function processInboundMessage() {
        try {
            // Confirm to number matches configured twilio number
            $twilio_number = $this->formatNumber($this->getProjectSetting('twilio-number'));
            if ($_POST['To'] !== $twilio_number) {
                $error = "Received inbound message addressed to " . $_POST['To'] . " when project is configured to use $twilio_number";
                $this->emError($error);
                // Still try to process / could result from a change in study number... Could change to exception in the future:
                // throw new InboundException($error);
            }

            // See if record exists on project
            $from_number = $_POST['From'];
            if (empty($from_number)) {
                throw new InboundException("Missing required `From` number in POST");
            }
            $record_id = $this->getRecordIdByCellNumber($from_number);

            // TODO: Log inbound message with number and record_id.  Anything else?

            if (empty($record_id)) {
                // REDCap::logEvent("Received text from $from_number", "This number is not found in this project. Ignoring...");
                throw new InboundException("Inbound message from $from_number cannot be matched with record in project");
            }

            $body = trim($_POST['Body'] ?? '');
            if (empty($body)) {
                throw new InboundException("Empty body from $from_number, record $record_id -- skipping");
            }

            $this->emDebug("Received $body from $record_id");

            // Check for Opt Out Reply - remove all non-alpha-num chars and lowercase
            $opt_msg_check = preg_replace( '/[\W]/', '', strtolower($body));
            if (in_array($opt_msg_check,self::OPT_OUT_KEYWORDS)) {
                $message = $this->setRecordOptOutStatus($record_id, true);
                REDCap::logEvent("Opted Out of SMS", "Record $record_id has opted out into the SMS communications - received: $body","",$record_id);
                // throw new InboundException("Opting out record $record_id with inbound message of $body");
                return $message;
            }

            if ($this->isWithdrawn($record_id, $this->getProjectId())) {
                REDCap::logEvent("Received text from withdrawn participant", "Received:  $body. No further action since withdrawn","",$record_id);
                // throw new InboundException("Record $record_id is withdrawn.  Ignoring reply: $body");
                return "";
            }

            if ($this->getRecordOptOutStatus($record_id, $this->getProjectId())) {
                // Check for re-opt-in
                if (in_array($opt_msg_check, self::OPT_IN_KEYWORDS)) {
                    $message = $this->setRecordOptOutStatus($record_id, false);
                    REDCap::logEvent("Opted Into SMS", "Record $record_id has opted back into the SMS communications - received: $body","",$record_id);
                    return $message;
                } else {
                    REDCap::logEvent("Received text from opted-out participant", "Received: $body. No further action since opted out","",$record_id);
                    // throw new InboundException("Record $record_id has opted-out.  Ignoring reply: $body");
                    return "";
                }
            }

            // This is a real reply
            $this->handleReply($record_id, $from_number, $body);

        } catch (InboundException $e) {
            $this->emDebug("Caught Inbound Exception: " . $e->getMessage());
            return "";
        } catch (\Exception $e) {
            $this->emError("Exception thrown: " . $e->getMessage(), $e->getTraceAsString());
            return "We're sorry - but something went wrong on our end.";
        }
    }


    /**
     * Handles incoming text response:
     * - If valid response, gets next text and sends it
     *                      updates state table
     * - if invalid response, construct nonsense warning and sends it
     *
     * @param $record_id
     * @param $cell_number
     * @param $msg
     * @return void
     * @throws \Exception
     */
    public function handleReply($record_id, $cell_number, $msg) {
        $nonsense_text_warning = $this->getProjectSetting('nonsense-text-warning',  $this->getProjectId());

        // Load up the TM
        $TM = $this->getTwilioManager($this->getProjectId());

        // Find ACTIVE conversations for this number
        if ($CS = ConversationState::getActiveConversationByNumber($this, $cell_number)) {
            $this->emDebug("Found CS#". $CS->getId() . " for Record $record_id / $cell_number at field [" . $CS->getCurrentField() . "]");

            // Get FormManager to get validation and response info.
            $FM = new FormManager($this, $CS->getInstrument(), $CS->getEventId(), $this->getProjectId());

            // according to state table this is the current question
            $current_field = $CS->getCurrentField();
            $event_id = $CS->getEventId();

            // Check the participant response and try to confirm it is a valid response
            if (false !== $response = $FM->validateResponse($current_field, $msg)) {

                // VALID RESPONSE
                $this->emDebug("We have validated response of $msg as $response to save in field " . $current_field);

                // Save the response to redcap?
                $result = $this->saveResponseToRedcap($this->getProjectId(), $record_id, $current_field, $event_id, $response);
                if ($result['errors']) {
                    $this->emError("There were errors while saving $response to record id $record_id for $current_field", $result['errors']);
                    REDCap::logEvent("Error saving response:  $response","Response in wrong format. Sending nonsense warning.","",$record_id, $CS->getEventId(),$CS->module->getProjectId());

                    // Since we are not validating the min/max, using REDCap save to validate and warn?
                    $this->emDebug("There were errors while saving $response to $record_id", $result['errors']);

                    $instructions = $FM->getFieldInstruction($current_field);
                    $label = $FM->getFieldLabel($current_field); // should this be added to the text??

                    $outbound_sms = implode("\n", array_filter([$nonsense_text_warning, $instructions]));
                    $TM->sendTwilioMessage($cell_number, $outbound_sms);
                    return;

                }

                //Since valid get next SMS to send and save field to state
                $sms_to_send_list = $FM->getNextSMS($current_field, $record_id);
                $active_field     = $FM->getActiveQuestion($sms_to_send_list);
                $this->emDebug("ACTIVE FIELD: ". $active_field, $sms_to_send_list);


                //sometimes there are final descriptive coaching messages, but no active field.
                if (!empty($sms_to_send_list)) {
                    // Send out the next set of messages
                    foreach ($sms_to_send_list as $k => $v) {
                        $sms = $v['field_label'];
                        $TM->sendTwilioMessage($cell_number, $sms);
                        $this->emDebug("Sent to $cell_number: ". $sms);
                    }

                    //persist  in state table
                    $CS->setCurrentField($active_field);
                    $CS->save(); //current field not saving?? there seems to be a need for saving after each dirty

                    $CS->setReminderTs();
                    $CS->save();

                    $this->emDebug("Persisted to : ". $CS->getId());

                }

                //if active field is empty then set state to complete
                if (empty($active_field)) {
                    // We are at the end of the survey
                    // $this->module->setSurveyTimestamp()
                    $CS->setState('COMPLETE');
                    $CS->save();
                    // TODO: Is there a 'thank you' or is that part of the descriptive...
                }
            } else {
                // False means this is an invalid response for an enumerated field
                $this->emDebug("Response of $msg was not valid for " . $current_field);

                $nonsense_text_reply = $nonsense_text_warning . " " . $FM->getFieldInstruction($current_field);
                $CS->setReminderTs();
                $TM->sendTwilioMessage($cell_number, $nonsense_text_reply);
                //TODO: repeat question
            }

        } else {
            $no_open_conversation_message = $this->getProjectSetting('no-open-conversation-message', $this->getProjectId());
            if (!empty($no_open_conversation_message)) {
                $TM = $this->getTwilioManager($this->getProjectId());
                $TM->sendTwilioMessage($cell_number,$no_open_conversation_message);

            }

            $this->emDebug("No ACTIVE conversation for this number $cell_number");
            //TODO: text back?

            //TODO: logEvent?
            REDCap::logEvent("Received text from $cell_number", "There is no active conversation currently. Ignoring:  $msg");
        }
    }

    public function saveResponseToREDCap($project_id, $record_id, $field_name, $event_id, $response) {
        $data       = [ $record_id => [ $event_id => [ $field_name => $response ] ] ];
        $result     = REDCap::saveData($project_id, 'array', $data);
        $this->emDebug("Saving $response to record: $record_id", $result);
        return $result;
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
        $output = "";
        if ($type== "E164") {
            // For US, append a 1 to 10 digit numbers that dont start with a 1
            if (strlen($digits) === 10 && left($digits,1) != "1") {
                $output = "1".$digits;
            } else {
                $output = $digits;
            }
            // return "+".$digits;
        } elseif ($type == "redcap") {
            if (strlen($digits) === 11 && left($digits,1,) == "1") {
                // 16503803405 => 6503803405
                $digits = mid($digits, 2, 10);
            }
            if (strlen($digits) === 10) {
                // 6503803405 => (650) 380-3405
                $output = "(" . mid($digits,1,3) . ") " . mid($digits,4,3) . "-" . mid($digits,7,4);
            }
        } elseif ($type == "digits") {
            $output = $digits;
        }
        if ($output == "") $this->emDebug("Unable to parse $number to $digits into type $type");
        return strval($output);
    }


    /**
     * Try to pull a record by the phone number
     * @param string $number
     * @param string $project_id
     * @return string|null
     * @throws ConfigSetupException
     */
    public function getRecordIdByCellNumber($number, $project_id = '') {

        $number_in_redcap_format = $this->formatNumber($number, 'redcap');
        if (empty($number_in_redcap_format)) {
            $this->emDebug("Invalid number: $number");
            $result = null;
        } else {
            // Try to look up record

            // We need the table_pk from Proj for getData to be cron safe
            if (empty($project_id)) $project_id = $this->getProjectId();
            global $Proj;
            $_Proj = ($Proj && $Proj->project_id == $project_id) ? $Proj : new Project($project_id);
            $record_id_field = $_Proj->table_pk;

            $this_field_config          = 'phone-field';
            $this_field_event_id_config = 'phone-field-event-id';
            $this_field                 = $this->getProjectSetting($this_field_config, $project_id);
            $this_field_event_id        = $this->getProjectSetting($this_field_event_id_config, $project_id);
            if (empty($this_field) && empty($this_field_event_id)) {
                throw new ConfigSetupException("EM Configuration is not complete. Please check the EM setup for $this_field_config / $this_field_event_id_config.");
            }

            // Build logic
            $filter_logic_event = ($_Proj->longitudinal ? '[' . $_Proj->getUniqueEventNames($this_field_event_id) . ']' : '');
            $filter_logic = $filter_logic_event . '[' . $this_field . '] = "' . $number_in_redcap_format . '"';

            $params = [
                'return_format' => 'array',
                'filterLogic' => $filter_logic,
                'fields' => [ $record_id_field ],
                'project_id' => $project_id
            ];
            $results = REDCap::getData($params);

            if (count($results) > 1) {
                $this->emError("More than one record is registered with phone number $number: " . implode(",",array_keys($results)) . " -- taking first value");
            }
            $result = empty($results) ? null : strval(key($results));
            $this->emDebug("Query for $number", $result);
        }
        return $result;
    }




    private function getOptInMessage() {
        // TODO: allow override with project setting
        return self::DEFAULT_OPT_IN_MESSAGE;
    }

    private function getOptOutMessage() {
        // TODO: allow override with project setting
        return self::DEFAULT_OPT_OUT_MESSAGE;
    }








    public function getNumberStatus($number) {
        $numberStatus = $this->getProjectSetting(self::NUMBER_PREFIX . $number);
        return $numberStatus;
    }

    public function setNumberStatus($number, $numberStatus) {
        $this->setProjectSetting(self::NUMBER_PREFIX . $number, $numberStatus);
    }


    public function cronScanConversationState( $cronParameters ) {
        //get the current Active crons in cron table where
        // No Project Context here

        $originalPid = $_GET['pid'];

        foreach($this->getProjectsWithModuleEnabled() as $project_id){
            $_GET['pid'] = $project_id;

            // Project specific method calls go here.

            $this->emDebug("Running cron on pid $project_id");

            $timestamp = time();

            foreach (ConversationState::getActiveConversationsNeedingAttention($this, $project_id, $timestamp) as $CS) {
                /** @var $CS ConversationState **/
                $this->emDebug("working on ID: ". $CS->getId());
                if ($CS->getExpiryTs() < $timestamp ) {
                    // Is expired?
                    if ($timestamp - $CS->getLastResponseTs() <= self::LAST_RESPONSE_EXPIRY_DELAY_SEC) {
                        // Participant responded recently, let's not expire the conversation yet
                        $this->emDebug("Skipping expiration due to recent response", $timestamp, $CS->getLastResponseTs());
                    } else {
                        // Expire it!
                        $CS->expireConversation();

                        if ($CS->getInstrument()=='thursday') {
                            $expiration_message =  $this->getProjectSetting('thur-expiry-text', $project_id);
                        } else {
                            $expiration_message =  $this->getProjectSetting('sun-expiry-text', $project_id);
                        }

                        $result = $this->getTwilioManager($project_id)->sendTwilioMessage($CS->getCellNumber(),$expiration_message);
                        $this->emDebug("Send expiration message", $result);

                        \REDCap::logEvent("Expired Conversation " . $CS->getId(), "","",$CS->getRecordId(),$CS->getEventId(), $project_id);
                    }
                } elseif($CS->getReminderTs() < $timestamp) {
                    // Send a reminder
                    $reminder_test_warning = $this->getProjectSetting('reminder-text-warning', $project_id);
                    $current_field = $CS->getCurrentField();
                    $FM = new FormManager($this,$CS->getInstrument(),$CS->getEventId(),$project_id);
                    $current_step = $FM->getCurrentFormStep($current_field,$CS->getRecordId());
                    $active_label = $FM->getActiveQuestion($current_step, true);
                    $active_variable = $FM->getActiveQuestion($current_step);

                    $instructions = $FM->getFieldInstruction($active_variable);

                    $outbound_sms = implode("\n", array_filter([$reminder_test_warning, $active_label, $instructions]));
                    $result = $this->getTwilioManager($project_id)->sendTwilioMessage($CS->getCellNumber(),$outbound_sms);
                    $this->emDebug("Send reminder message", $result);
                    \REDCap::logEvent("Reminder sent for $current_field (#" . $CS->getId() . ")", "","",$CS->getRecordId(),$CS->getEventId(), $project_id);
                    $CS->setReminderTs($CS->getExpiryTs());
                    $CS->save();

                } else {
                    $this->emDebug("This shouldn't happen", $timestamp, $CS);
                }
            }

        }

        // Put the pid back the way it was before this cron job (likely doesn't matter, but is good housekeeping practice)
        $_GET['pid'] = $originalPid;
        $this->emDebug("cron completed.");

        return "The \"{$cronParameters['cron_description']}\" cron job completed successfully.";

    }









}
