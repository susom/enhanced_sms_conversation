<?php
namespace Stanford\EnhancedSMSConversation;

require_once "emLoggerTrait.php";
require_once "vendor/autoload.php";
require_once "classes/ConversationState.php";
require_once "classes/MessageHistory.php";
require_once "classes/MessageContext.php";
require_once "classes/FormManager.php";
require_once "classes/TwilioManager.php";
require_once "classes/CustomExceptions.php";

use \REDCap;
use \Project;
use \Exception;

class EnhancedSMSConversation extends \ExternalModules\AbstractExternalModule {
    use emLoggerTrait;

    public $TwilioManager;

    const NUMBER_PREFIX = "NUMBER:";

    const SUBJECT_TAG_FOR_EMAIL = "@ESMS";
    const ACTION_TAG_IGNORE_FIELD = "@ESMS-IGNORE";
    const ACTION_TAG_INVALID_RESPONSE = "@ESMS-INVALID-RESPONSE";
    const ACTION_TAG_REMINDER_MESSAGE = "@ESMS-REMINDER-MESSAGE";
    const ACTION_TAG_REMINDER_TIME = "@ESMS-REMINDER-TIME";
    const ACTION_TAG_REMINDER_MAX_COUNT = "@ESMS-REMINDER-MAX-COUNT";
    const ACTION_TAG_EXPIRY_MESSAGE = "@ESMS-EXPIRY-MESSAGE";
    const ACTION_TAG_EXPIRY_TIME = "@ESMS-EXPIRY-TIME";




    const GENERIC_SMS_REPLY_ERROR = "We're sorry - but something went wrong on our end.";

    const DEFAULT_OPT_IN_MESSAGE = "You have been opted-in to SMS communication.  Reply with 'STOP' or 'UNSUBSCRIBE' to be removed.";

    const DEFAULT_OPT_OUT_MESSAGE = "You have opted out to SMS communications.  Reply with 'START' to resume using SMS.";

    const LAST_RESPONSE_EXPIRY_DELAY_SEC = 180; // If it has been less than this time since a response, do not expire a conversation yet.

    const VALID_SURVEY_TIMESTAMP_STAGES = ['completion_time','start_time', 'first_submit_time'];

    const OPT_OUT_KEYWORDS = ['stop', 'optout'];

    const OPT_IN_KEYWORDS = ['start'];

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
     * @return bool true or false to send the email
     * @throws \Exception
     */
    public function redcap_email($to, $from, $subject, $message, $cc, $bcc, $fromName, $attachments) {
        // Exit if this is not an @ESMS email
        if (strpos($subject, self::SUBJECT_TAG_FOR_EMAIL) === false) return true;

        $this->emDebug("This email is an ESMS email");

        // We determine the project/record/etc from a complex helper object
        $MC = new MessageContext($this);
        $mc_context = $MC->getContextAsArray();
        $this->emDebug("MC (" . PAGE . ") => " . json_encode($mc_context));

        $source     = $mc_context['source'];
        $project_id = $mc_context['project_id'];
        $record_id  = $mc_context['record_id'];
        $event_id   = $mc_context['event_id'];
        $instrument = $mc_context['instrument'];
        $survey_id  = $mc_context['survey_id'];
        $instance   = $mc_context['instance'] ?? 1;

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
                $this->emDebug("REDCAP EMAIL ERRORS",$errors);
                REDCap::logEvent(implode("\n",$errors),"","",$record_id,$event_id,$project_id);
                return false;
            }

        } catch (ConfigSetupException $cse) {
            // TODO: REVIEW
            REDCap::logEvent("EM Config not setup. Check with admin.");
            return false;
        }

        try {
            // If this is an instrument survey - then clear out any older surveys
            if (!$instrument && $source == 'Alert') {
                // This is an ALERT message -- let's just send it.  No need to create a conversation state
                $TM = $this->getTwilioManager($project_id);
                $msg = strip_tags($message);
                $TM->sendTwilioMessage($cell_number, $msg);
                // TODO: Do we need to log in redcap_log_event here?
                REDCap::logEvent("ESMS Alert Sent", $msg,"",$record_id,$event_id,$project_id);
            } elseif (!empty($instrument)) {
                //xdebug_break();
                //6. get the first sms to send
                $FM = new FormManager($this, $instrument, '', $record_id, $event_id, $project_id, $instance);
                $TM = $this->getTwilioManager($project_id);
                // TODO: Do we want to do a first-time orientation or just build that into the survey?
                $TM->sendBulkTwilioMessages($cell_number, $FM->getArrayOfMessagesAndQuestion());
                $current_field = $FM->getCurrentField();

                //7. Set the state table
                // Create a new Conversation State
                $CS = new ConversationState($this);
                $params = [
                    "project_id"    => $project_id,
                    "record"        => $record_id,
                    "instrument"    => $instrument,
                    "event_id"      => $event_id,
                    "instance"      => $instance,
                    "cell_number"   => $cell_number,
                    "current_field" => $current_field
                ];
                $CS->setValues($params);
                $this->emDebug("Instrument $instrument - current field: " . json_encode($current_field));

                if (empty($current_field)) {
                    // Survey is complete on creation - was only descriptive messages
                    // $this->emDebug("Setting Expired");
                    $CS->setState("COMPLETE");
                    // TODO: Maybe add complete method that updates survey timestamps,since it can happen from two locations
                    // either here or during normal inbound processing...
                } else {
                    $CS->setState("ACTIVE");
                    $CS->setExpiryTs();
                    $CS->setReminderTs();
                }
                // $this->emDebug("About to create CS:" . $CS->getId());
                $CS->save();
                $this->emDebug("SAVED CS#" . $CS->getId() . " status " . $CS->getState());

            } else {
                $this->emError("Not sure how we ended up here:", func_get_args());
            }

            // Cancel sending the REDCap Email
            return false;

        } catch (Exception $e) {
            $this->emError("Exception in REDCap Email: " . $e->getMessage());
        }

    }


    /**
     * Only show the test page for a super-user
     * @param $project_id string
     * @param $link array
     * @return null|array
     */
    public function redcap_module_link_check_display($project_id, $link) {
        // $this->emDebug("Checking for $project_id",$link);
        if (isset($link['superuseronly']) && $link['superuseronly']) {
            if (!$this->getUser()->isSuperUser()) return null;
        }
        return $link;
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
        } elseif (!empty($project_id) && $project_id != $this->TwilioManager->getProjectId()) {
            // If the project_id is different, then make sure we have the correct Twilio Manager
            $this->TwilioManager = new TwilioManager($this, $project_id);
        }
        return $this->TwilioManager;
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
     * @param $project_id
     * @return boolean True if opted out, false if still in.
     * @throws ConfigSetupException
     */
    public function getRecordOptOutStatus($record_id, $project_id) {
        $sms_opt_out = $this->getFieldDataFromConfigSettings($record_id, 'sms-opt-out-field', 'sms-opt-out-field-event-id', $project_id ) ?? '';
        // return $sms_opt_out !== '';
        return $sms_opt_out[1] == '1';  // Checkbox flavor
    }


    /**
     * Determine if a record is withdrawn using the study-withdrawn-logic
     * CAN BE CALLED OUT OF PROJECT CONTEXT
     * @param string $record_id
     * @param int $project_id
     * @return bool
     */
    public function isWithdrawn($record_id, $project_id) {
        if( $withdrawn_logic = $this->getProjectSetting('study-withdrawn-logic', $project_id) ?? false ) {
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

        // Text version
        // $value = $opt_out ? date("Y-m-d H:i:s") : '';
        // $data = [ $record_id => [ $this_field_event_id => [ $this_field => $value ]]];

        // Checkbox Version
        $value = $opt_out ? 1 : 0;
        $data = [ $record_id => [ $this_field_event_id => [ $this_field => [ 1 => $value ]]]];

        $params = [
            'data'=>$data,
            // 'overwriteBehavior'=>'overwrite'
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
            return '';
        } else {
            $this->emError("Updated record $record_id opt-out status to $value WITH ERRORS:", $response, $params, $this_field);
            return self::GENERIC_SMS_REPLY_ERROR;
        }
    }


    /**
     * Helper Function to pull record-level data based on a field-event pair specified in the EM Config
     * @param $record_id
     * @param $this_field_config
     * @param $this_field_event_id_config
     * @return mixed
     * @throws ConfigSetupException
     */
    private function getFieldDataFromConfigSettings($record_id, $this_field_config, $this_field_event_id_config, $project_id) {
        // We need the table_pk from Proj for getData to be cron safe
        // global $Proj;
        // $_Proj = $Proj->project_id == $project_id ? $Proj : new Project($project_id);

        $this_field          = $this->getProjectSetting($this_field_config, $project_id);
        $this_field_event_id = $this->getProjectSetting($this_field_event_id_config, $project_id);
        // $record_id_field     = $_Proj->table_pk;

        // $fields = [$record_id_field, $this_field];  //TODO: I don't think we need the record_id field - Can we remove this and the $Proj stuff?

        if (empty($this_field) && empty($this_field_event_id)) {
            throw new ConfigSetupException("EM Configuration is not complete. Please check the EM setup for $this_field_config / $this_field_event_id_config.");
        }

        $params = [
            'project_id'    => $project_id,
            'return_format' => 'array',
            'events'        => $this_field_event_id,
            'fields'        => [$this_field], //$fields,
            'records'       => array($record_id)
        ];
        $results = REDCap::getData($params);
        return $results[$record_id][$this_field_event_id][$this_field] ?? "";
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
            $from_number = $this->formatNumber($_POST['From']);
            if (empty($from_number)) {
                throw new InboundException("Missing required `From` number in POST");
            }
            $record_id = $this->getRecordIdByCellNumber($from_number);

            // TODO: Log inbound message with number and record_id.  Anything else?

            if (empty($record_id)) {
                // REDCap::logEvent("Received text from $from_number", "This number is not found in this project. Ignoring...");
                throw new InboundException("Inbound message from $from_number cannot be matched with record in project " . $this->getProjectId());
            }

            $body = trim($_POST['Body'] ?? '');
            //xxyjl: are 0s being considered empty here? try isset?
            $this->emDebug("[$record_id] Inbound response: $body");

            if (!isset($body)) {
                throw new InboundException("Empty body from $from_number, record $record_id -- skipping");
            }

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

            return "";
        } catch (InboundException $e) {
            $this->emDebug("Caught Inbound Exception: " . $e->getMessage());
            return "";
        } catch (\Exception $e) {
            $this->emError("Exception thrown: " . $e->getMessage(), $e->getTraceAsString());
            return self::GENERIC_SMS_REPLY_ERROR;
        }
    }


    /**
     * Handles incoming text response:
     * - this method always runs in project context
     * - If valid response, gets next text and sends it
     *                      updates state table
     * - if invalid response, construct nonsense warning and sends it
     *
     * @param $record_id
     * @param $cell_number
     * @param $inbound_body
     * @return void
     * @throws \Exception
     */
    public function handleReply($record_id, $cell_number, $inbound_body) {
        // Load up the TM
        $TM = $this->getTwilioManager($this->getProjectId());

        // Find ACTIVE conversations for this number
        if ($CS = ConversationState::getActiveConversationByNumber($this, $cell_number)) {
            $this->emDebug("Found CS#". $CS->getId() . " for Record $record_id / " . $CS->getInstance() .
                " / $cell_number at field [" . $CS->getCurrentField() . "]");

            // according to state table this is the current question
            $current_field = $CS->getCurrentField();
            $event_id = $CS->getEventId();

            if (empty($current_field)) {
                // If we do not have a current field, then something has gone wrong and we should be ending the survey
                $CS->setState('ERROR');
                $CS->addNote('Missing required current_field state.  Check change logs for details');
                $this->emError("Invalid Conversation State", $CS);
                $CS->Save();

                $TM->sendTwilioMessage($cell_number, "We're sorry - but something went wrong on our end (#" . $CS->getId() . ")");
                throw new Exception("Something went wrong with " . $CS->getId() . " - Invalid conversation state");
            }

            // Update Timestamps
            $CS->setReminderTs();
            $CS->setLastResponseTs();

            // Get FormManager to get validation and response info.
            $FM = new FormManager($this, $CS->getInstrument(), $current_field, $record_id, $event_id, $this->getProjectId(), $CS->getInstance());

            // Check the participant response and try to confirm it is a valid response
            if (false !== $response = $FM->validateResponse($inbound_body)) {
                // POTENTIALLY VALID RESPONSE
                if ($response !== $inbound_body) {
                    $this->emDebug("Response for $current_field of $inbound_body has been mapped to $response");
                }

                // Save the response to redcap?
                // $result = $this->saveResponseToRedcap($this->getProjectId(), $record_id, $current_field, $event_id, $response, $CS->getInstance());
                $result = $FM->saveResponseToREDCap($current_field, $response);

                // TODO: Check for save validation warnings on text fields
                if (!empty($result['warnings']) || !empty($result['errors'])) {
                    $this->emError("Issues while saving $response to $current_field", $result);
                }

                $saveSuccessful = empty($result['errors']);
                if ($saveSuccessful) {

                    $this->emDebug("Saved $current_field");

                    // Check if there is a field after the current question
                    if ($next_field = $FM->getNextField()) {
                        $this->emDebug("After save, the next field is $next_field");

                        // Let's load the next field
                        $FM = new FormManager($this, $CS->getInstrument(), $next_field, $record_id, $event_id, $this->getProjectId(), $CS->getInstance());

                        // And send next round of SMS messages if any
                        $TM->sendBulkTwilioMessages($CS->getCellNumber(), $FM->getArrayOfMessagesAndQuestion());

                        $current_field = $FM->getCurrentField();
                        $this->emDebug("New Form Manager Current Field: " . $current_field);

                        // If the last field was just descriptive, we could be done here.  We can tell by seeing if
                        // the Form Manager has a current_field or not
                        $this->emDebug("[$record_id] Response saved, moving current field from $current_field to " . $FM->getCurrentField());
                    } else {
                        // That was it - let's end the survey
                        $current_field = '';
                    }

                    if (empty($current_field)) {
                        // We have reached the end of the survey
                        $CS->setState('COMPLETE');
                        // TODO: Do we display the end-of-survey message?
                    } else {
                        // Update to the new current_field
                        $CS->setCurrentField($current_field);
                    }
                } else {
                    // Error path during save - so we don't advance the current field
                    $this->emError("There were errors while saving $response to record id $record_id for $current_field", $result['errors'], $FM->getFieldDict());
                    REDCap::logEvent("Error saving response:  $response","Response in wrong format. Sending nonsense warning.","",$record_id, $CS->getEventId(),$this->getProjectId());

                    // Since we are not validating the min/max, using REDCap save to validate and warn?
                    // $this->emDebug("There were errors while saving $response to $record_id", $result['errors']);


                    // Repeat the question without advancing the current_field (TODO: He may not want the question label repeated or instructions here?
                    $invalid_response = $FM->getInvalidResponse();
                    $outbound_sms = implode("\n", array_filter([$invalid_response, $FM->getQuestionLabel()]));
                    $TM->sendTwilioMessage($cell_number, $outbound_sms);
                }
            } else {
                // False here means this is an invalid response for an enumerated field
                // $this->emDebug("Response of $inbound_body was not valid for $current_field");
                $invalid_response = $FM->getInvalidResponse();
                $outbound_sms = implode("\n", array_filter([$FM->getInstructions(), $invalid_response]));
                // $nonsense_text_reply = $nonsense_text_warning . " " . $FM->getFieldInstruction($current_field);
                $TM->sendTwilioMessage($cell_number, $outbound_sms);
            }
            $CS->save();
            $this->emDebug("CS #". $CS->getId() . " updated!");
        } else {
            // No ACTIVE conversations were found
            $no_open_conversation_message = $this->getProjectSetting('no-open-conversation-message', $this->getProjectId());
            if (!empty($no_open_conversation_message)) {
                $TM->sendTwilioMessage($cell_number,$no_open_conversation_message);
            }
            $this->emDebug("No ACTIVE conversation for this number $cell_number");
            REDCap::logEvent("Received text outside of conversation", "Text from $cell_number as $inbound_body ignored because there were no open surveys/conversations - replied with $no_open_conversation_message","",$record_id);
        }
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
        // $this->emDebug("INCOMING : $number");
        $output = "";
        if ($type== "E164") {
            // For US, append a 1 to 10 digit numbers that dont start with a 1
            if (strlen($digits) === 10 && left($digits,1) != "1") {
                $output = "1".$digits;
            } else {
                $output = $digits;
            }
            $output = "+".$output;
        } elseif ($type == "redcap") {
            if (strlen($digits) === 11 && left($digits,1,) == "1") {
                // 16503803405 => 6503803405
                $digits = mid($digits, 2, 10);
            }
            if (strlen($digits) === 10) {
                // 6503803405 => (650) 380-3405
                // TODO: ANDY? in redcap_data it's stored as 6503803405
                $output = "(" . mid($digits,1,3) . ") " . mid($digits,4,3) . "-" . mid($digits,7,4);
                //$output = $digits;
            }
        } elseif ($type == "digits") {
            $output = $digits;
        }
        if ($output == "") $this->emDebug("Unable to parse $number to $digits into type $type");
        // $this->emDebug("FORMATNUMBER $type $number => $output");
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

            // $this->emDebug("Phone Lookup Params:" , $params);
            $results = REDCap::getData($params);
            $this->emDebug("Phone Lookup Result:" . json_encode($results));

            if (count($results) > 1) {
                $this->emError("More than one record is registered with phone number $number: " . implode(",",array_keys($results)) . " -- taking first value");
            }
            $result = empty($results) ? null : strval(key($results));
            // $this->emDebug("Query for $number", $result, $params);
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


    // public function getNumberStatus($number) {
    //     $numberStatus = $this->getProjectSetting(self::NUMBER_PREFIX . $number);
    //     return $numberStatus;
    // }
    //
    // public function setNumberStatus($number, $numberStatus) {
    //     $this->setProjectSetting(self::NUMBER_PREFIX . $number, $numberStatus);
    // }



    public function cronPurgeSeloChangeLogs() {
        //TODO: Add cron for purging change logs
        // Purge ConversationState and MessageHistory change logs
    }

    /**
     * Cron is called every minute to check for outbound messages
     * @param array $cronParameters
     * @return string Cron message
     * @throws Exception
     */
    public function cronScanConversationState( $cronParameters ) {
        if ($this->getSystemSetting('ignore-cron')) {
            return;
        }

        $originalPid = $_GET['pid'];

        // Attempt to use the single thread/multi-project cron strategy
        foreach($this->getProjectsWithModuleEnabled() as $project_id){
            // $this->emDebug("Running cron on pid $project_id");

            // Load a Twilio Client
            $TM = $this->getTwilioManager($project_id);
            $this->emDebug("[CRON] PID#" . $TM->getProjectId());   // TP-2787

            // Set the PID (but not really sure this is being used)
            $_GET['pid'] = $project_id;

            // Record the timestamp for reminders / expiration
            $timestamp = time();

            // Loop through all conversations that might be expired or require a reminder
            foreach (ConversationState::getActiveConversationsNeedingAttention($this, $project_id, $timestamp) as $CS) {
                /** @var $CS ConversationState **/
                $this->emDebug("Processing Needed Action for ID: ". $CS->getId());
                if ($CS->getExpiryTs() < $timestamp ) {
                    // CS has expired
                    if ($timestamp - $CS->getLastResponseTs() <= self::LAST_RESPONSE_EXPIRY_DELAY_SEC) {
                        // Participant responded recently, let's not expire the conversation yet
                        $this->emDebug("Skipping expiration due to recent response", $timestamp, $CS->getLastResponseTs());
                    } else {
                        // Expire it!
                        $CS->setState('EXPIRED');

                        // TODO: Replace with function to lookup expiration method based on instrument and config.json
                        $expiration_message = $this->getProjectSetting('default-expiry-text', $project_id);

                        $to_number = $CS->getCellNumber();
                        $result = $TM->sendTwilioMessage($to_number,$expiration_message);
                        $this->emDebug("Result of expiration message", $result);

                        \REDCap::logEvent("Expired Conversation " . $CS->getId(), "","",$CS->getRecordId(),$CS->getEventId(), $project_id);
                    }
                } elseif($CS->getReminderTs() < $timestamp) {
                    // Time to send a reminder
                    $reminder_test_warning = $this->getProjectSetting('default-reminder-text', $project_id);
                    $FM = new FormManager($this, $CS->getInstrument(), $CS->getCurrentField(), $CS->getRecordId(), $CS->getEventId(), $project_id, $CS->getInstance());


                    // $current_step = $FM->getCurrentFormStep($current_field,$CS->getRecordId());
                    // $active_label = $FM->getActiveQuestion($current_step, true);     // label only
                    // $active_variable = $FM->getActiveQuestion($current_step);              // field_name (dquant)
                    //
                    // $instructions = $FM->getFieldInstruction($active_variable);             // Text Yes or No

                    // TODO: Standardize on space or CR for multiple lines in a single text...
                    $outbound_sms = implode("\n", array_filter([
                        $reminder_test_warning,
                        $FM->getQuestionLabel(),
                        $FM->getInstructions()]
                    ));
                    $result = $TM->sendTwilioMessage($CS->getCellNumber(),$outbound_sms);
                    $this->emDebug("Send reminder message", $result);
                    REDCap::logEvent("Reminder sent for " . $CS->getCurrentField() . " (#" . $CS->getId() . ")",
                        "","",$CS->getRecordId(),$CS->getEventId(), $project_id);

                    $currentReminder = $CS->getReminderTs();
                    $newReminder = $CS->getExpiryTs() + 600;    // Just add some time to ensure the reminder doesn't go off again
                    $this->emDebug("Cron updating #" . $CS->getId() . " reminder from $currentReminder to $newReminder");
                    $CS->setReminderTs($newReminder);
                    $this->emDebug("CS Reminder: " . $CS->getReminderTs());
                } else {
                    $this->emDebug("This shouldn't happen", $timestamp, $CS);
                }
                $CS->save();
                $this->emDebug("CS Reminder after save: " . $CS->getReminderTs());
            }

        }

        // Put the pid back the way it was before this cron job (likely doesn't matter, but is good housekeeping practice)
        $_GET['pid'] = $originalPid;
        return "The \"{$cronParameters['cron_description']}\" cron job completed successfully.";
    }



    /**
     * Adding JSMO
     * @param $data
     * @param $init_method
     * @return void
     */
    public function injectJSMO($data = null, $init_method = null) {
        echo $this->initializeJavascriptModuleObject();
        $cmds = [
            "const module = " . $this->getJavascriptModuleObjectName()
        ];
        if (!empty($data)) $cmds[] = "module.data = " . json_encode($data);
        if (!empty($init_method)) $cmds[] = "module.afterRender(module." . $init_method . ")";
        ?>
        <script src="<?=$this->getUrl("assets/jsmo.js",true)?>"></script>
        <script>
            $(function() { <?php echo implode(";\n", $cmds) ?> })
        </script>
        <?php
    }


    /**
     * Ajax handler
     * @param $action
     * @param $payload
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $repeat_instance
     * @param $survey_hash
     * @param $response_id
     * @param $survey_queue_hash
     * @param $page
     * @param $page_full
     * @param $user_id
     * @param $group_id
     * @return array
     * @throws Exception
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance,
                                       $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id)
    {
        switch($action) {
            case "getConversations":
                $sql = "select reml.log_id,
                           reml.timestamp,
                           reml.record,
                           remlp1.value as 'reminder_ts',
                           remlp2.value as 'expiry_ts',
                           remlp3.value as 'cell_number',
                           remlp4.value as 'state',
                           remlp5.value as 'current_field',
                           from_unixtime(remlp6.value, '%Y-%m-%d %H:%i:%s') as 'last_response_ts'
                    from
                        redcap_external_modules_log reml
                    left join redcap_external_modules_log_parameters remlp1 on reml.log_id = remlp1.log_id and remlp1.name='reminder_ts'
                    left join redcap_external_modules_log_parameters remlp2 on reml.log_id = remlp2.log_id and remlp2.name='expiry_ts'
                    left join redcap_external_modules_log_parameters remlp3 on reml.log_id = remlp3.log_id and remlp3.name='cell_number'
                    left join redcap_external_modules_log_parameters remlp4 on reml.log_id = remlp4.log_id and remlp4.name='state'
                    left join redcap_external_modules_log_parameters remlp5 on reml.log_id = remlp5.log_id and remlp5.name='current_field'
                    left join redcap_external_modules_log_parameters remlp6 on reml.log_id = remlp6.log_id and remlp6.name='last_response_ts'

                    where
                         reml.message = 'ConversationState'
                    and  reml.project_id = ?";
                //TODO: Could also do this with EMSO query method...
                $q = $this->query($sql,[$this->getProjectId()]);
                $results = [];
                while ($row = db_fetch_row($q)) $results[] = $row;
                $result = [
                    "data" => $results
                    ];
                break;
            case "getConversationStates":
                break;
            case "deleteConversations":
                // Set the payload to all conversations if the payload is empty...
                if (empty($payload)) {
                    $ids = ConversationState::queryIds($this, "ConversationState");
                } else {
                    $ids = $payload;
                }

                // Delete conversations
                $result=[];
                foreach ($ids as $id) {
                    $q = $this->removeLogs("log_id = ?", [$id]);
                    $this->emDebug("Deleting id $id, $q");
                    $result[$id]=$q;
                }
                break;
            case "TestAction":
                \REDCap::logEvent("Test Action Received");
                $result = [
                    "success"=>true,
                    "user_id"=>$user_id
                ];
                break;
            default:
                // Action not defined
                throw new Exception ("Action $action is not defined");
        }

        // Return is left as php object, is converted to json automatically
        return $result;
    }






}
