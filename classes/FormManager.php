<?php

namespace Stanford\EnhancedSMSConversation;

use PhpParser\Node\Scalar\String_;
use REDCap;

class FormManager {

    /** @var EnhancedSMSConversation $module */
    private $module;
    private $form;
    private $event_id;      // TODO: Why do we need an event_id in the form manager?  Maybe Branching Logic?
    private $project_id;
    private $form_script;    // Parsed version of data dictionary

    const VALID_ENUMERATED_FIELD_TYPES = [
        "yesno", "truefalse", "radio", "dropdown"
    ];


    public function __construct($module, $form, $event_id, $project_id) {
        $this->module = $module;
        $this->form = $form;
        $this->event_id = $event_id;
        $this->project_id=$project_id;

        // Parse the metadata for this form
        $this->form_script = $this->createFormScript();
    }

    /**
     * Create a parsed version of the form script that can be used for SMS replies
     *
     * Returns an array of fields of that form.
     *
     * @return array
     * @throws \Exception
     */
    private function createFormScript() {

        $dict = REDCap::getDataDictionary($this->project_id, "array");

        $form_script = array();

        foreach($dict as $field_name => $field) {
            $form_name          = $field["form_name"];

            // Skip if not this form
            if ($form_name !== $this->form) continue;

            $field_type         = $field["field_type"];
            $annotation_arr     = explode(" ", trim($field["field_annotation"]));
            $field_label        = $field["field_label"];
            $choices            = $field["select_choices_or_calculations"];
            $branching_logic    = $field["branching_logic"];

            // Skip any fields that are hidden-survey
            if (in_array("@HIDDEN-SURVEY", $annotation_arr)) continue;

            // Skip fields that have @ESC-IGNORE
            if (in_array($this->module::ACTION_TAG_IGNORE_FIELD, $annotation_arr)) continue;

            // SET UP INITIAL "next_step"  IF ANY KIND OF BRANCHING IS INVOLVED WONT BE RELIABLE
            $step = [
                "field_name"        => $field_name,
                "field_type"        => $field_type,
                "field_label"       => $field_label,
                "branching_logic"   => $branching_logic,
            ];

            // PROCESS PRESET CHOICES
            // Create an array of all choices for enumerated types
            if (in_array($field_type, self::VALID_ENUMERATED_FIELD_TYPES)) {
                // For some types, we need to create the choices:
                if($field_type == "yesno") {
                    $choices = "1,Yes | 0,No";
                } elseif ($field_type == "truefalse") {
                    $choices = "1,True | 0,False";
                }

                // Blow up the choices string into an array
                $preset_choices = array();
                $choice_pairs = explode("|",$choices);
                foreach($choice_pairs as $pair){
                    list($k, $v) = array_map('trim', explode(",",$pair,2));
                    $preset_choices[$k] = $v;
                }
                $step["preset_choices"] = $preset_choices;
            } elseif ($field_type == "text" ) {
                // TODO: Handle text input validation in any way?
            }

            $form_script[$field_name] = $step;

            // //TODO: why was this annotation needed. for which use case?
            // if(in_array("@ESC_LASTSTEP",$annotation_arr)){
            //     $new_script[$field_name]["laststep"] = true;
            // }
        }

        $this->module->emDebug("Form script: ", $form_script);
        return $form_script;
    }


    /**
     * Given the current_question (variable name in data dictionary) and the record_id and event_id,
     * this method will return the next series of metadata ready to be sent as SMS
     * Any descriptive fields preceding it and then the next valid question will be returned.
     *
     * @param $current_question String
     * @param $record_id
     * @return string
     */
    public function getNextSMS($current_question, $record_id) {
        // if $current_question is blank, send the first sms applicable for this record in this event_id

//        if ($current_question == '') {
//            $script = $this->script;
//            $current_question = key($script);
//        } else {
            $next_step_metadata = $this->getNextStepInScript($current_question);
            $next_step = $next_step_metadata['field_name'];
//        }

        return $this->getCurrentFormStep($next_step, $record_id);
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
    public function getCurrentFormStep($current_question, $record_id) {
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
        $this_step          = $this->form_script[$current_step]["field_name"];
        $field_type         = $this->form_script[$current_step]["field_type"];
        $branching_logic    = $this->form_script[$current_step]["branching_logic"];

        $next_step = $this->getNextStepInScript($current_step);

        if (empty($next_step)) return $container;

        // CHECK DESCRIPTIVE
        if ($field_type == "descriptive") {
            if ((!empty($branching_logic) ) && ($record_id)  && ($event_id) ){
                $valid = \REDCap::evaluateLogic($branching_logic, $this->project_id, $record_id, $event_id);
                if ($valid) {
                    array_push($container, $this->form_script[$current_step]);
                }
            } else {
                array_push($container, $this->form_script[$current_step]);
            }



            if ($next_step) {
                $container = $this->recurseCurrentSteps($next_step['field_name'], $record_id, $event_id, $container);

            }

        } else {
            //NOT DESCRIPTIVE
            if ((!empty($branching_logic) ) && ($record_id)  && ($event_id) ){
                $valid = \REDCap::evaluateLogic($branching_logic, $this->project_id, $record_id, $event_id);
                if ($valid) {
                    array_push($container, $this->form_script[$current_step]);
                } else {

                    //$next_step = $this->getNextStepInScript($current_step);

                    //if ($next_step) {
                    $container = $this->recurseCurrentSteps($next_step['field_name'], $record_id, $event_id, $container);

                    //}

                }

            } else {
                array_push($container, $this->form_script[$current_step]);
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
        $script = $this->form_script;

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
