<?php

namespace Stanford\EnhancedSMSConversation;

class ConversationState
{
    /** @var EnhancedSMSConversation $module */
    private $module;

    private $id;
    private $project_id;
    private $record;
    private $instrument;
    private $event_id;
    private $instance;
    private $number;
    private $start_ts;
    private $current_question;
    private $reminder_ts;
    private $expiry_ts;
    private $state;

    /**
     * @return mixed
     */
    public function getStartTs()
    {
        return $this->start_ts;
    }

    /**
     * @return mixed
     */
    public function getState()
    {
        return $this->state;
    }             // active, closed, expired, completed


    public function __construct($module) {
        // Other code to run when object is instantiated
        $this->module = $module;
        $this->module->emDebug("Created!");
    }



}
