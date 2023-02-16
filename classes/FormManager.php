<?php

namespace Stanford\EnhancedSMSConversation;

use PhpParser\Node\Scalar\String_;
use REDCap;

class FormManager {

    /** @var EnhancedSMSConversation $module */
    private $module;
    private $form;
    private $event_id;
    private $project_id;

    private $script;    // Parsed version of data dictionary

    public function __construct($module, $form, $event_id, $project_id) {
        $this->module = $module;
        $this->form = $form;
        $this->event_id = $event_id;
        $this->project_id=$project_id;

        $this->loadForm();
    }

    /**
     * Load Form given form and event (passed in constructor)
     *
     * Returns an array of fields of that form.
     *
     * @return array
     * @throws \Exception
     */
    private function loadForm() {

        $dict = REDCap::getDataDictionary($this->project_id, "array");

        $new_script = array();

        foreach($dict as $field_name => $field) {
            $form_name          = $field["form_name"];
            $field_type         = $field["field_type"];
            $annotation_arr     = explode(" ", trim($field["field_annotation"]));
            $field_label        = $field["field_label"];
            $choices            = $field["select_choices_or_calculations"];
            $branching_logic    = $field["branching_logic"];
            $nonsense           = '';
            $missing            = '';

            // Skip if not this form
            if ($form_name !== $this->form) continue;

            // Skip if hidden-survey
            if (in_array("@HIDDEN-SURVEY", $annotation_arr)) continue;

            // Skip if ESC IGNORE
            if (in_array($this->module::ACTION_TAG_IGNORE_FIELD, $annotation_arr)) continue;

            // TODO: Left off here, need to validate that question is doable by SMS...

            //PROCESS PRESET CHOICES
            $preset_choices = array();
            if($field_type == "yesno" || $field_type  == "truefalse" || $field_type  == "radio" || $field_type == "dropdown"){
                if($field_type == "yesno"){
                    $choices = "1,Yes | 0,No";
                    $nonsense = "We don't understand. Please text Yes or No";
                    $missing  = "We missed your response. Do you plan on drinking this weekend? TXT yes or no";
                }
                if($field_type == "truefalse"){
                    $choices = "1,True | 0,False";
                }

                //THESE WILL HAVE PRESET # , Choice Values
                $choice_pairs   = explode("|",$choices);
                foreach($choice_pairs as $pair){
                    $num_val = explode(",",$pair);
                    $preset_choices[trim($num_val[0])] = trim($num_val[1]);
                }
            }

            //SET UP INITIAL "next_step"  IF ANY KIND OF BRANCHING IS INVOLVED WONT BE RELIABLE
            $new_script[$field_name]  = array(
                "field_name"        => $field_name,
                "field_type"        => $field_type,
                "field_label"       => $field_label,
                "preset_choices"    => $preset_choices,
                "branching_logic"   => $branching_logic,
            );

            //TODO: why was this annotation needed. for which use case?
            if(in_array("@ESC_LASTSTEP",$annotation_arr)){
                $new_script[$field_name]["laststep"] = true;
            }
        }

//        $this->module->emDebug("new script: ", $new_script);

        $this->script = $new_script;
        return $new_script;
    }

    /**
     * Given the current_question (variable name in data dictionary) and the record_id and event_id,
     * this method will return the next series of metadata ready to be sent as SMS
     * Any descriptive fields preceding it and then the next valid question will be returned.
     *
     * @param $current_question String
     * @param $record_id
     * @param $event_id
     * @return void
     */
    public function getNextSMS($current_question, $record_id, $event_id) {
        //if $current_question is blank, send the first sms applicable for this record in this event_id

//        if ($current_question == '') {
//            $script = $this->script;
//            $current_question = key($script);
//        } else {
            $next_step_metadata = $this->getNextStepInScript($current_question);
            $next_step = $next_step_metadata['field_name'];
//        }

        return $this->getCurrentFormStep($next_step, $record_id, $event_id);
    }

    /**
     * Gets the next sendable fields for this record in this event for the form loaded for this form manager
     * We need this for the reminder scenario where the current question needs to be resent.
     * For the reminder scenario, we need to only send the last field (do not send the descriptive fields)
     *
     * Fields can be excluded from this list by adding ACTION TAG : @ESC_IGNORE
     *
     * Given the current_question (variable name in data dictionary) and the record_id and event_id,
     * this method will return the current series of metadata fields to be sent as SMS
     * Any descriptive fields preceding it and then the next valid question will be returned.
     *
     * @param $current_question String
     * @param $record_id
     * @param $event_id
     * @return mixed
     */
    public function getCurrentFormStep($current_question, $record_id, $event_id) {
        $this->module->emDebug("Current question: ". $current_question);

        // GATHER UP STEPs UNTIL REACHING An input step (evaluate branching if need be)
        $total_fields_in_step = $this->recurseCurrentSteps($current_question, $record_id, $event_id, array());
        $this->module->emDebug("FIELDS IN CURRENT STEP", $current_question, $total_fields_in_step);

        return $total_fields_in_step;
    }

    /**
     * Recursive method that traverses the current form loaded in this FormManager.
     *
     * At each recursive level, it adds to the array if the branching logic is applicable to this record in this
     * event for this form. Any number of descriptive fields will be added until a field expecting a response is
     * hit.
     *
     * @param $current_step
     * @param $record_id
     * @param $event_id
     * @param $container
     * @return mixed
     */
    public function recurseCurrentSteps($current_step, $record_id, $event_id, $container) {
        $this_step          = $this->script[$current_step]["field_name"];
        $field_type         = $this->script[$current_step]["field_type"];
        $branching_logic    = $this->script[$current_step]["branching_logic"];

        $next_step = $this->getNextStepInScript($current_step);

        if (empty($next_step)) return $container;

        // CHECK DESCRIPTIVE
        if ($field_type == "descriptive") {
            if ((!empty($branching_logic) ) && ($record_id)  && ($event_id) ){
                $valid = \REDCap::evaluateLogic($branching_logic, $this->project_id, $record_id, $event_id);
                if ($valid) {
                    array_push($container, $this->script[$current_step]);
                }
            } else {
                array_push($container, $this->script[$current_step]);
            }



            if ($next_step) {
                $container = $this->recurseCurrentSteps($next_step['field_name'], $record_id, $event_id, $container);

            }

        } else {
            //NOT DESCRIPTIVE
            if ((!empty($branching_logic) ) && ($record_id)  && ($event_id) ){
                $valid = \REDCap::evaluateLogic($branching_logic, $this->project_id, $record_id, $event_id);
                if ($valid) {
                    array_push($container, $this->script[$current_step]);
                } else {

                    //$next_step = $this->getNextStepInScript($current_step);

                    //if ($next_step) {
                    $container = $this->recurseCurrentSteps($next_step['field_name'], $record_id, $event_id, $container);

                    //}

                }

            } else {
                array_push($container, $this->script[$current_step]);
            }
        }
        return $container;
    }

    /**
     * Helper method to retrieve the next field in the given form.
     *
     * Returns array of metadata if found
     * Returns false if there is no more field to be returned.
     *
     * @param $key
     * @return false|mixed
     */
    private function getNextStepInScript($key) {
        $script = $this->script;

        if ($key == '') {
            return reset($script);
        }

        $currentKey = key($script);

        while ($currentKey !== null && $currentKey != $key) {
            next($script);
            $currentKey = key($script);
        }
        return next($script);

    }
}
