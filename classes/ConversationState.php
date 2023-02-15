<?php

namespace Stanford\EnhancedSMSConversation;

require_once "SimpleEmLogObject.php";
require_once "FormManager.php";

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
        'number',
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
    public function executeNextStep() {
        $instrument = $this->getInstrument();
        $event_id = $this->getEventId();
        $project_id = $this->module->getProjectId();
        $record_id = $this->getRecordId();

        $this->module->emDebug("Executing next step with $instrument / $event_id / $project_id");

        $fm = new FormManager($this->module, $instrument, $event_id, $project_id);

        $current_field = $this->getCurrentField();

        $nextSms = $fm->getNextSMS($current_field, $record_id);

    }
















    /** GETTERS */

    public function getEventId() {
        return $this->getValue('event_id');
    }

    public function getInstrument() {
        return $this->getValue('instrument');
    }

    public function getCurrentField() {
        return $this->getValue('current_field');
    }

    public function getNumber() {
        return $this->getValue('number');
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
     * This finds all open conversations for a given number and closes them and is
     * intended to be called before saving a new conversation for a given number
     * @param $number
     * @return void
     * @throws \Exception
     */
    public function closeExistingConversations($number = '') {
        // TODO: Might we want to also send a message to send to the owner of the conversation
        // telling them that we are closing it?  Maybe...
        $number = empty($number) ? $this->getValue('number') : $number;

        if (empty($number)) {
            throw new Exception ("Call to close conversation without valid number");
        }

        /** @var ConversationState $CS */
        foreach (ConversationState::getActiveConversationByNumber($this->module, $number) as $CS) {
            $CS->setState("ERROR");
            $CS->addNote("New conversation state for this number is forcing the close of this conversation");
            $CS->save();
            // TODO: Do we want to send a custom message for this scenario to the participant?
            //       for example. we could expire it instead...
            $this->module->emDebug("Forced to close conversation state " . $CS->getId() .
                "  as ERROR because a new conversation is starting for $number");
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
     * If there is more than one active conversation for a given number, it marks the older ones as ERROR
     * @param $module
     * @param $number
     * @return ConversationState|false
     * @throws \Exception
     */
    public static function getActiveConversationByNumber($module, $number) {
        $type = self::OBJECT_NAME;
        $state = "ACTIVE";
        $filter_clause = "state = ? and number = ? order by timestamp desc";
        $objs = self::queryObjects($module, $type, $filter_clause, [$state, $number]);

        $count = count($objs);
        $module->emDebug("Found $count hits with $filter_clause");
        if ($count == 0) {
            // None found, return false;
            $result = false;
        } else {
            $result = array_shift($objs);
            // Handle multiple active conversations if present
            foreach ($objs as $obj) {
                $module->emError("Found more than one active conversation for $number - this shouldn't happen.");
                $module->emDebug("Updating state to error for object " . $obj->getId());
                $obj->setValue('state', 'ERROR');
                $obj->setValue('notes', "Multiple active conversations for $number");
                $obj->save();
            }
        }
        return $result;
    }


}
