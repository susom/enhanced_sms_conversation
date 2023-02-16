<?php

namespace Stanford\EnhancedSMSConversation;

require_once "SimpleEmLogObject.php";
require_once "FormManager.php";
use Twilio;

class ConversationState extends SimpleEmLogObject
{
    /** @var EnhancedSMSConversation $this->module */

    /**
     * The object holds Conversation States in the EM
     * EM LOG table already has: record, timestamp
     */
    CONST VALID_OBJECT_PARAMETERS = [
        'instrument',
        'event_id',
        'instance',
        'cell_number',
        'current_field',
        'reminder_ts',  // each time participant responds, we re-set the reminder time
        'expiry_ts',    // each time a participant responds, we re-calc the expiry time
        'state',        // ACTIVE (created) -> EXPIRED / COMPLETE / ERROR?
        'note'          // A place to log any notes about the CS
    ];

    CONST OBJECT_NAME = 'ConversationState';   // This is the 'name' of the object and stored in the message column

    CONST VALID_STATES = [
        'ACTIVE',
        'EXPIRED',
        'COMPLETE',
        'ERROR'
    ];

    public function __construct($module, $type = self::OBJECT_NAME, $log_id = null, $limit_params = [])
    {
        parent::__construct($module, $type, $log_id, $limit_params);
    }

    /** METADATA PARSING */

    /**
     * Send the current question to the participant, include previous fields if necessary
     * @return void
     * @throws Twilio\Exceptions\ConfigurationException
     * @throws Twilio\Exceptions\TwilioException
     */
    public function sendCurrentMessages() {
        $instrument = $this->getInstrument();
        $event_id = $this->getEventId();
        $project_id = $this->module->getProjectId();
        $record_id = $this->getRecordId();
        $instance = 1;      // Not supported yet!
        $current_field = $this->getCurrentField();

        $this->module->emDebug("Executing next step with $instrument / $event_id / $project_id / $current_field");

        $fm = new FormManager($this->module, $instrument, $event_id, $project_id);

        // If current field is blank, then we start at the beginning.
        // If current field is not blank, we find that point in the form_script
        list($messages, $new_current_field) = $fm->getMessagesAndCurrentQuestion($current_field,$record_id, $instance);

        foreach ($messages as $message) {
            $TC = $this->module->getTwilioClient();
            $sms = $TC->messages->create(
                // To Number
                $this->getCellNumber(),
                [
                    'from' => $this->module->getTwilioNumber(),
                    'body' => $message
                ]
            );
            $this->module->emDebug("Sent Message to " . $this->getCellNumber(), $message, $sms);
            // TODO: Check for errors and opt out for error 30004
            // https://www.twilio.com/docs/api/errors/30004
        }

        if ($current_field !== $new_current_field) {
            $this->module->emDebug("Changing current field from $current_field to $new_current_field");
            $this->setValue('current_field', $new_current_field);
            $this->save();
        }
    }


    /**
     * Deliver a message to the conversation state
     * @param $body
     * @return true
     * @throws Twilio\Exceptions\ConfigurationException
     * @throws Twilio\Exceptions\TwilioException
     */
    public function sendSms($body) {
        $TC = $this->module->getTwilioClient();
        $sms = $TC->messages->create(
        // To Number
            $this->getCellNumber(),
            [
                'from' => $this->module->twilio_number,
                'body' => $body
            ]
        );
        $this->module->emDebug("Send Message", $body, $sms);
        // TODO: Check for errors and opt out for error 30004
        // https://www.twilio.com/docs/api/errors/30004
        return true;
    }















    /** GETTERS */

    public function getEventId() {
        return $this->getValue('event_id');
    }

    public function getInstrument() {
        return $this->getValue('instrument');
    }

    public function getCurrentField() {
        return $this->getValue('current_field') ?? '';
    }

    public function getCellNumber() {
        return $this->getValue('cell_number');
    }

    public function getRecordId() {
        return $this->getValue('record_id');
    }

    public function getState()
    {
        return $this->getValue('state');
    }



    /** SETTERS */

    public function setState($state) {
        $state = strtoupper($state);
        if (!in_array($state, self::VALID_STATES)) {
            throw new Exception ("Invalid State: $state");
        }
        $this->setValue('state', $state);
    }


    /**
     * Add a note to the conversation state
     * @param $note
     * @return void
     */
    public function addNote($note) {
        $note = $this->getValue('note') ?? '';
        $prefix = empty($note) ? "" : "\n----\n";
        $this->setValue('note', $prefix. "[" . date("Y-m-d H:i:s") . "]" . $note);
    }


    /**
     * This finds all open conversations for a given cell number and closes them and is
     * intended to be called before saving a new conversation for a given cell number
     * @param $cell_number
     * @return void
     * @throws \Exception
     */
    public function closeExistingConversations($cell_number = '') {
        // TODO: Might we want to also send a message to send to the owner of the conversation
        // telling them that we are closing it?  Maybe...
        $cell_number = empty($cell_number) ? $this->getCellNumber() : $cell_number;

        if (empty($cell_number)) {
            throw new Exception ("Call to close conversation without valid cell number");
        }

        /** @var ConversationState $CS */
        foreach (ConversationState::getActiveConversationByNumber($this->module, $cell_number) as $CS) {
            $CS->setState("ERROR");
            $CS->addNote("New conversation state for this cell number is forcing the close of this conversation");
            $CS->save();
            // TODO: Do we want to send a custom message for this scenario to the participant?
            //       for example. we could expire it instead...
            $this->module->emDebug("Forced to close conversation state " . $CS->getId() .
                "  as ERROR because a new conversation is starting for $cell_number");
        }
    }





    public function parseReply() {
        $body = $_POST['body'];


        // TODO: Check for expiration
        // TODO: get current question and verify response is valid
        //       if valid, save, continue - if invalid, repeat the question
        // TODO: update last_activity timestamp for conversation

        // Determine current

    }




    /**
     * Load the active conversation by phone number
     * If there is more than one active conversation for a given cell number, it marks the older ones as ERROR
     * @param $module
     * @param $cell_number
     * @return ConversationState|false
     * @throws \Exception
     */
    public static function getActiveConversationByNumber($module, $cell_number) {
        $type = self::OBJECT_NAME;
        $state = "ACTIVE";
        $filter_clause = "state = ? and cell_number = ? order by timestamp desc";
        $objs = self::queryObjects($module, $type, $filter_clause, [$state, $cell_number]);

        $count = count($objs);
        $module->emDebug("Found $count hits with $filter_clause");
        if ($count == 0) {
            // None found, return false;
            $result = false;
        } else {
            $result = array_shift($objs);
            // Handle multiple active conversations if present
            foreach ($objs as $obj) {
                $module->emError("Found more than one active conversation for $cell_number - this shouldn't happen.");
                $module->emDebug("Updating state to error for object " . $obj->getId());
                $obj->setValue('state', 'ERROR');
                $obj->setValue('notes', "Multiple active conversations for $cell_number");
                $obj->save();
            }
        }
        return $result;
    }


}
