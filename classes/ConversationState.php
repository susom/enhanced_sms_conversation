<?php

namespace Stanford\EnhancedSMSConversation;

require_once "SimpleEmLogObject.php";

class ConversationState extends SimpleEmLogObject
{
    /** @var EnhancedSMSConversation $this->module */

    /**
     * The object holds Conversation States in the EM
     *
     *
     */

    CONST VALID_OBJECT_PARAMETERS = [
        'instrument', 'event_id', 'instance','number',
        'start_ts','current_question','reminder_ts','expiry_ts','state'
    ];

    CONST OBJECT_NAME = __CLASS__; //'ConversationState';   // This is the 'name' of the object and stored in the message column

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


    public function parseReply() {
        $body = $_POST['body'];

        // TODO: Check for expiration
        // TODO: get current question and verify response is valid
        //       if valid, save, continue - if invalid, repeat the question
        // TODO: update last_activity timestamp for conversation
        //

        // Determine current

    }


    /**
     * @return mixed
     */
    public function getStartTs()
    {
        return $this->getValue('start_ts');
    }

    /**
     * @return mixed
     */
    public function getState()
    {
        return $this->getValue('state');
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
