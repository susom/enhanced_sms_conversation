<?php

namespace Stanford\EnhancedSMSConversation;

require_once "SimpleEmLogObject.php";

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


    /** METADATA PARSING */






    /**
     * @return mixed
     */
    public function getState()
    {
        return $this->getValue('state');
    }


    public function parseReply($record, $body) {
        //$body = $_POST['body'];

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
