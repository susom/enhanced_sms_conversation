<?php
namespace Stanford\EnhancedSMSConversation;

require_once "emLoggerTrait.php";
require_once "vendor/autoload.php";
require_once "classes/ConversationState.php";
require_once "classes/MessageContext.php";
require_once "classes/FormManager.php";
require_once "classes/TwilioManager.php";

use \REDCap;
use \Twilio\TwiML\MessagingResponse;
use \Twilio\Rest\Client;
use Twilio\TwiML\Voice\Conversation;

class EnhancedSMSConversation extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    public $TwilioClient;
    public $twilio_number;
    public $twilio_manager;

    const NUMBER_PREFIX = "NUMBER:";

    const ACTION_TAG_PREFIX = "@ESC";
    const ACTION_TAG_IGNORE_FIELD = "@ESC_IGNORE";

    const LAST_RESPONSE_EXPIRY_DELAY_SEC = 180; // If it has been less than this time since a response, do not expire a conversation yet.

    const VALID_SURVEY_TIMESTAMP_STAGES = ['completion_time','start_time', 'first_submit_time'];

    const OPT_OUT_KEYWORDS = [ 'stop', 'optout'];

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

    public function getTwilioManager($project_id) {
        if ($this->twilio_manager === null) {
            $this->twilio_manager = new TwilioManager($this, $project_id);
        }
        return $this->twilio_manager;
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
        //if (strpos($subject, "@ESMS") === false) return true;

        if (strpos($message, "@ESMS") === false) return true;

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
    public function isOptedOut($record_id, $project_id) {
        // TODO:
        $sms_opt_out = $this->getFieldData($record_id, 'sms-opt-out-field', 'sms-opt-out-field-event-id', $project_id );

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
    public function isWithdrawn($record_id, $project_id) {
        $withdrawn = $this->getFieldData($record_id, 'study-withdrawn-field', 'study-withdrawn-field-event-id', $project_id );

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
    public function getRecordPhoneNumber($record_id, $project_id) {
        $number = $this->getFieldData($record_id, 'phone-field', 'phone-field-event-id', $project_id );

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

        // $data = array(
        //     REDCap::getRecordIdField() => $record_id,
        //     'redcap_event_name' => REDCap::getEventNames(true, false, $optout_field_event_id),
        //     $optout_field. "___1" => 1
        // );
        //
        // $this->emDebug("saved opt out", $data);
        // $response = REDCap::saveData('json', json_encode(array($data)));

        // Array method
        $data = [ $record_id => [ $optout_field_event_id => [ $optout_field[1] => 1 ] ] ];
        $response = REDCap::saveData('array', $data);

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
    private function getFieldData($record_id, $this_field_config, $this_field_event_id_config, $project_id) {
        global $Proj;
        $this_field = $this->getProjectSetting($this_field_config, $project_id);
        $this_field_event_id = $this->getProjectSetting($this_field_event_id_config, $project_id);

        //$rec_id_field = REDCap::getRecordIdField(); //This needs project id context
        $_Proj = $Proj->project_id == $project_id ? $Proj : new \Project($project_id);
        $record_id_field = $_Proj->table_pk;

        $fields = [$record_id_field, $this_field];  //

        if (empty($this_field) && empty($this_field_event_id)) {
            throw new ConfigSetupException("EM Configuration is not complete. Please check the EM setup.");
        }

        $params = [
            'project_id'    => $project_id,
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
            $twilio_number = $this->formatNumber($this->getProjectSetting('twilio-number'));
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
            $record_id = $this->getRecordIdByCellNumber($from_number);

            if (empty($record_id)) {
                REDCap::logEvent("Received text from $from_number", "This number is not found in this project. Ignoring...");
                return;
            }

            $body = $_POST['Body'];
            $this->emDebug("Received $body from $record_id");


            // Check for Opt Out Reply
            $opt_msg_check = preg_replace( '/[\W]/', '', strtolower($body));
            if (in_array($opt_msg_check,self::OPT_OUT_KEYWORDS)) {
                $this->optOutSMS($record_id);
                $this->emDebug("Opted out $record_id");
                return;
            }

            // TODO: Get opt-out-sms status for number
            if ($this->isWithdrawn($record_id, $this->getProjectId())) {
                REDCap::logEvent("Received text from withdrawn participant", "Received:  $body. No further action since withdrawn","",$record_id);
                return;
            }
            // TODO: Get opt-out-sms status for number
            if ($this->isOptedOut($record_id, $this->getProjectId())) {
                REDCap::logEvent("Received text from opted-out participant", "Received:  $body. No further action since opted out","",$record_id);
                return;
            }

            //this is a real reply
            $this->handleReply($record_id, $from_number, $body);

            // Check if there is an open conversation
            //pushed to handleReply method
/**
            if ($CS = ConversationState::getActiveConversationByNumber($this, $this->formatNumber($from_number))) {
                $this->emDebug("Found conversation " . $CS->getId());
                $response = "Found conversation " . $CS->getId();
                $response = $CS->parseReply();
            } else {
                $this->emDebug("No conversation for this number");
                $response = "No conversations right now";

            }
 */

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

        //given cell_number, see what is the current state in the ConversationState
        if ($found_cs = ConversationState::getActiveConversationByNumber($this, $cell_number)) {
            $this->emDebug("IN LOG ID: ". $found_cs->getId());
            $this->emDebug("EXPECTING RESPONSES FOR CURRENT FIELD: " . $found_cs->getCurrentField());

            //get FormManager to get validation and response info.
            $fm = new FormManager($this, $found_cs->getInstrument(), $found_cs->getEventId(), $found_cs->module->getProjectId());
            //get the TwilioManager as well
            $tm = $this->getTwilioManager($this->getProjectId());



            //according to state table this is the current question
            $current_field = $found_cs->getCurrentField();
            $event_id = $found_cs->getEventId();

            // Check the participant response and try to confirm it is a valid response
            if (false !== $response = $fm->validateResponse($current_field, $msg)) {

                // VALID RESPONSE
                $this->emDebug("We have validated response of $msg as $response to save in field " . $current_field);

                // Save the response to redcap?
                $result = $this->saveResponseToRedcap($this->getProjectId(), $record_id, $current_field, $event_id, $response);
                if ($result['errors']) {
                    $this->emError("There were errors while saving $response to record id $record_id for $current_field", $result['errors']);
                    REDCap::logEvent("Error saving response:  $response","Response in wrong format. Sending nonsense warning.","",$record_id, $found_cs->getEventId(),$found_cs->module->getProjectId());

                    // Since we are not validating the min/max, using REDCap save to validate and warn?
                    $this->emDebug("There were errors while saving $response to $record_id", $result['errors']);

                    $instructions = $fm->getFieldInstruction($current_field);
                    $label = $fm->getFieldLabel($current_field); // should this be added to the text??

                    $outbound_sms = implode("\n", array_filter([$nonsense_text_warning, $instructions]));
                    $tm->sendTwilioMessage($cell_number, $outbound_sms);
                    return;

                }

                //Since valid get next SMS to send and save field to state
                $sms_to_send_list = $fm->getNextSMS($current_field, $record_id);
                $active_field     = $fm->getActiveQuestion($sms_to_send_list);
                $this->emDebug("ACTIVE FIELD: ". $active_field, $sms_to_send_list);


                //sometimes there are final descriptive coaching messages, but no active field.
                if (!empty($sms_to_send_list)) {
                    // Send out the next set of messages
                    foreach ($sms_to_send_list as $k => $v) {
                        $sms = $v['field_label'];
                        $tm->sendTwilioMessage($cell_number, $sms);
                        $this->emDebug("Sent to $cell_number: ". $sms);
                    }

                    //persist  in state table
                    $found_cs->setCurrentField($active_field);
                    $found_cs->save(); //current field not saving?? there seems to be a need for saving after each dirty

                    $found_cs->setReminderTs();
                    $found_cs->save();

                    $this->emDebug("Persisted to : ". $found_cs->getId());

                }

                //if active field is empty then set state to complete
                if (empty($active_field)) {
                    // We are at the end of the survey
                    // $this->module->setSurveyTimestamp()
                    $found_cs->setState('COMPLETE');
                    $found_cs->save();
                    // TODO: Is there a 'thank you' or is that part of the descriptive...
                }
            } else {
                // INVALID response
                $this->emDebug("Response of $msg was not valid for " . $current_field);
                $nonsense_text_reply = $nonsense_text_warning . " " . $fm->getFieldInstruction($current_field);
                $found_cs->setReminderTs();
                $tm->sendTwilioMessage($cell_number, $nonsense_text_reply);
                //TODO: repeat question
            }

        } else {
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
