<?php

namespace Stanford\EnhancedSMSConversation;

use PhpParser\Node\Scalar\String_;
use REDCap;
use Piping;

class FormManager {

    /** @var EnhancedSMSConversation $module */
    private $module;
    private $form;
    private $event_id;
    private $project_id;
    private $form_script;    // Parsed version of data dictionary

    const VALID_ENUMERATED_FIELD_TYPES = [
        "yesno", "truefalse", "radio", "dropdown"
    ];

    // A second attempt at using aliases to match responses (make sure all are lowercase)
    const ALIASES = [
        "Yes-like"      => [ 'yes', 'y', 'sure', 'yep', 'ok' ],
        "No-like"       => [ 'no', 'n', 'nope', 'na', 'cant' ],
        "True-like"     => [ 'true', 't', 'yes', 'y', 'ok' ],
        "False-like"    => [ 'false', 'f', 'no', 'n', 'nope' ]
    ];


    const VALID_TEXT_TYPES = [
        "text"
    ];

    public function __construct($module, $form, $event_id, $project_id) {
        $this->module     = $module;
        $this->form       = $form;
        $this->event_id   = $event_id;
        $this->project_id = $project_id;

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

            $field_type             = $field["field_type"];
            $annotation_arr         = explode(" ", trim($field["field_annotation"]));
            $field_label            = $field["field_label"];
            $choices                = $field["select_choices_or_calculations"];
            $branching_logic        = $field["branching_logic"];
            $text_validation_min    = $field["text_validation_min"];
            $text_validation_max    = $field["text_validation_max"];

            // Skip any fields that are hidden-survey
            if (in_array("@HIDDEN-SURVEY", $annotation_arr)) continue;

            // Skip fields that have @ESC-IGNORE
            if (in_array($this->module::ACTION_TAG_IGNORE_FIELD, $annotation_arr)) continue;

            // SET UP INITIAL "next_step"  IF ANY KIND OF BRANCHING IS INVOLVED WONT BE RELIABLE
            $meta = [
                "field_name"        => $field_name,
                "field_type"        => $field_type,
                "field_label"       => $field_label,
                "branching_logic"   => $branching_logic,
                "instructions"      => ''
            ];

            $valid_types = array_merge(
                self::VALID_ENUMERATED_FIELD_TYPES,
                self::VALID_TEXT_TYPES,
                ["descriptive"]
            );

            if (!in_array($field_type, $valid_types)) {
                $this->module->emDebug("Skipping question $field_name as $field_type is not supported");
                continue;
            }

            // PROCESS PRESET CHOICES
            // Create an array of all choices for enumerated types
            if (in_array($field_type, self::VALID_ENUMERATED_FIELD_TYPES)) {
                // For some types, we need to create the choices:
                if($field_type == "yesno") {
                    $choices = "1,Yes | 0,No";
                    $meta["instructions"] = "Please text Yes or No";
                } elseif ($field_type == "truefalse") {
                    $choices = "1,True | 0,False";
                    $meta["instructions"] = "Please text True or False";
                }

                // Blow up the choices string into an array
                $preset_choices = array();
                $choice_pairs = explode("|",$choices);
                foreach($choice_pairs as $pair){
                    list($k, $v) = array_map('trim', explode(",",$pair,2));
                    $preset_choices[$k] = $v;
                }
                $meta["preset_choices"]     = $preset_choices;


            }

            if ($field_type == 'text') {
                if (isset($text_validation_max)) {
                    $meta["instructions"] = "Please text a value between $text_validation_min and $text_validation_max";
                }

            }

            $form_script[$field_name] = $meta;

            // //TODO: why was this annotation needed. for which use case?
            // if(in_array("@ESC_LASTSTEP",$annotation_arr)){
            //     $new_script[$field_name]["laststep"] = true;
            // }
        }

        //$this->module->emDebug("Form script: ", $form_script);
        return $form_script;
    }

    public function getFieldInstruction($current_step) {
        $instructions = $this->form_script[$current_step]["instructions"];
        return $instructions;
    }

    public function getFieldLabel($current_step) {
        $label = $this->form_script[$current_step]["field_label"];
        return $label;
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
    public function getNextSMS($current_question, $record_id) {
        //if $current_question is blank, send the first sms applicable for this record in this event_id

        $next_step_metadata = $this->getNextStepInScript($current_question);
        $next_step = $next_step_metadata['field_name'];

        if ($next_step == null) {
            //no more steps
            return null;
        }

        return $this->getCurrentFormStep($next_step, $record_id);
    }

    /**
     * Given list of sms to send (includes descriptive field,
     * return the first (and shuld be only) active field to be saved
     * as current conversation field.
     *
     * @param $sms_list
     * @return String
     */
    public function getActiveQuestion($sms_list, $label = false) {
        //return the first non-descriptive field
        foreach ($sms_list as $key => $value) {
            if ($value['field_type'] !== 'descriptive') {
                $result = $value['field_name'];
                if ($label) {
                    $result = $value['field_label'];
                }
                return $result;
            }
        }
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
     * @return mixed
     */
    public function getCurrentFormStep($current_question, $record_id) {
        $this->module->emDebug("Current question: ". $current_question);
        $container = array();


        // GATHER UP STEPs UNTIL REACHING An input step (evaluate branching if need be)
        $container = $this->recurseCurrentSteps($current_question, $record_id, $container);

        return $container;
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
     * @param $container
     * @return mixed
     */
    public function recurseCurrentSteps($current_step, $record_id, $container) {
        $this_step          = $this->form_script[$current_step]["field_name"];
        $field_type         = $this->form_script[$current_step]["field_type"];
        $branching_logic    = $this->form_script[$current_step]["branching_logic"];

        // IS CURRENT STEP VALID
        if ((!empty($branching_logic) ) && ($record_id)  && ($this->event_id) ) {
            $valid = \REDCap::evaluateLogic($branching_logic, $this->project_id, $record_id, $this->event_id);
            if ($valid) {
                array_push($container, $this->form_script[$current_step]);
            }
        } else {
            array_push($container, $this->form_script[$current_step]);
        }


        //terminating conditions: no more steps or the last time in the sms_list is not descriptive
        $next_step = $this->getNextStepInScript($current_step);
        $last_field_type_in_container = end($container)['field_type'];
        if (empty($next_step) || (($last_field_type_in_container!= null) && ($last_field_type_in_container !== "descriptive"))) {
            //this is the last
            return $container;
        } else {
            $container = $this->recurseCurrentSteps($next_step['field_name'], $record_id, $container);
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


    /****************************************/
    /*  ANDY's VERSION                      */
    /****************************************/




    /**
     * This is Andy's attempt and understanding how I'd do this...
     * GET MESSAGE OPTIONS
     * If current_field is empty, then we start at the beginning:
     *  - We stop at the first question equal to
     * call getNextField($current_field, $record);
     * - loop to find starting place
     * - has branching logic, evaluate - if false, skip
     * - is descriptive, just take label and then goto next
     * - is valid question, then create question
     *     output of this function is the Next SMS Message AND the new Current Question.
     *   if new question is empty, then I presume we are done and can close the conversation.
     *
     * @param string $start_field
     * @param string $record_id
     * @param int $instance
     * @return array{pre_question: array, question: string, options: string, current_field: string}
     *
     */
    public function getMessageOptions($start_field, $record_id, $instance=1) {
        $pre_question  = []; // Array of posts before the actual question (descriptive fields, likely)
        $question      = '';     // Actual text for the current question
        $options       = '';      // Options for the question (e.g. Reply Y for yes)
        $current_field = '';     // This is the question that we are awaiting a response on

        // Set the current question to the first question if it is currently empty
        if (empty($start_field)) {
            $start_field = $this->getNextField();
        }

        // Loop through the script until we find the start_question
        $skip = true;
        foreach ($this->form_script as $field_name => $meta) {
            // Once we reach the start question, we stop skipping and start processing
            if ($skip && $field_name == $start_field) $skip=false;

            // Skip as we haven't reached current question yet
            if ($skip) {
                $this->module->emDebug("Skipping: waiting for $start_field - now at $field_name");
                continue;
            }

            // Start aggregating messages
            $this->module->emDebug("Started at $start_field - now at $field_name");

            // Check to see if we skip question due to branching logic
            $branching_logic = $meta['branching_logic'];
            if (!empty($branching_logic) && $this->skipDueToBranching($meta['branching_logic'], $record_id)) {
                $this->module->emDebug("Skipping $field_name due to branching logic");
                continue;
            }

            // Process Label for current question
            $label_raw = $meta['field_label'];
            $label = trim(Piping::replaceVariablesInLabel($label_raw, $record_id, $this->event_id, $instance, array(),
                                                          false, $this->project_id, false, $this->form));
            if ($label !== $label_raw) {
                $this->module->emDebug("Piped label from raw to:", $label_raw, $label);
            }

            // If it is descriptive, we continue:
            if ($meta['field_type'] == "descriptive") {
                // We are just sending a message, no questions:
                if (!empty($label)) $pre_question[] = $label;
                $this->module->emDebug("$field_name is descriptive, so we will go on to the next field");
            } else {
                // We must have a question where we want to ask something
                $current_field = $field_name;

                // Lets get the question
                $this_question = empty($label) ? '' : $label;

                if (!empty($meta['preset_choices'])) {
                    $opts = [];
                    foreach ($meta['preset_choices'] as $k => $v) {
                        $opts[] = "[$k] $v";
                    }
                    $options = implode("\n", $opts);
                }

                $question = $this_question;
                break;  // Stop at first question that needs an answer
            }
        }

        return [
            "before"        => $pre_question,
            "question"      => $question,
            "options"       => $options,
            "current_field" => $current_field
        ];

    }


    /**
     * Get Next Field
     *  - returns the field_name (key) of script for next question
     *      - starts at q1 if current_question is empty
     *      - return null if there are no more questions or if current_question isn't in script
     * @param string $current_field
     * @return string|null $next_question
     */
    public function getNextField($current_field = '') {
        $keys = array_keys($this->form_script);
        // If current_question is empty, then assume the first question is next, otherwise
        // look to find the next question
        if (empty($current_field)) {
            $next_position = 0;
        } else {
            $position = array_search($current_field, $keys);
            if ($position === false) {
                // current question not found in keys
                $this->module->emError("Was unable to find $current_field in script - unable to continue to next");
                $next_position = -1; // non-valid position
            } else {
                $next_position = $position + 1;
            }
        }
        return $keys[$next_position] ?? null;
    }


    /**
     * Determine whether or not a question is branched
     * @param $meta
     * @param $record_id
     * @return bool
     */
    private function skipDueToBranching($branching_logic, $record_id) {
        $valid = true;
        if (!empty($branching_logic)) {
            $valid = \REDCap::evaluateLogic($branching_logic, $this->project_id, $record_id, $this->event_id);
            $this->module->emDebug("Evaluating branching logic for $record_id in event " . $this->event_id,
                                   $branching_logic, $valid);
        }
        return !$valid;
    }


    /**
     * Try to take in a response to a field_name and see if it is valid - return the value to save, or false if invalid.
     * @param $field_name
     * @param $response
     * @return false|string
     */
    public function validateResponse($field_name, $response) {

        // Make sure we have a valid field_name
        $meta = $this->form_script[$field_name] ?? null;
        if (is_null($meta)) {
            $this->module->emError("Unable to find $field_name in form script");
            return false;
        }

        // Check for presets
        if ($choices = $meta['preset_choices']) {

            // In testing with cases I found issues, so I think we should lowercase everything
            $input = strtolower(trim($response));
            // Lowercase everything (keys and values)
            $choices = array_map('strtolower', array_change_key_case($choices, CASE_LOWER));

            // Also think we should remove any punctuation marks or other non-alphanum chars, etc..
            $input = preg_replace("/[^a-z0-9]/", '', $input);

            // $this->module->emDebug("INPUT IS " . $input);
            // $this->module->emDebug("FIELDTYPE  IS " . $meta['field_type']);
            // $foo_keys = array_keys($choices);
            // $this->module->emDebug("ARRAY KEYS  IS ",  $foo_keys);
            // $valid = in_array($input, $foo_keys, true);
            // $this->module->emDebug("IS IT VAILD ",  $valid);

            // Try to find a key-match first
            // Input is always a string, but numeric-like keys are made into ints when part of an array
            // It appears isset coerces a numeric string to an int for comparison: so isset($a["1"]) is same as $a[1]
            //var_dump($input, $choices, array_keys($choices), isset($choices["0"]));
            if (isset($choices[$input])) {
                // Found a direct match to an array key
                $this->module->emDebug("Matched $input to choice key for label $choices[$input]");
                return $input;
            }

            // Try to find a value match
            if (false !== $key = array_search($input, $choices)) {
                // Found a match to a label of a choice
                $this->module->emDebug("Matched $input to choice $key of $field_name");
                return strval($key);
            }

            // Use value aliases
            // If we have a radio field type with Yes, No, and Maybe - it isn't strict boolean field, however, this
            // match should match a response of 'y' to the 'Yes' response as both are in the same alias group
            foreach (self::ALIASES as $type => $group) {
                if (in_array($input,$group)) {
                    // response is part of an alias group
                    foreach ($choices as $k => $v) {
                        if (in_array($v, $group)) {
                            $this->module->emDebug("Response $input is in same group $type as choice value $v - setting value as $k");
                            return strval($k);
                        }
                    }
                }
            }
            $this->module->emDebug("We were unable to match $input to any of the choices:", $choices);

        } elseif ($meta['field_type'] == "text") {
            // TODO - validate TEXT!
            $input=trim($response);
            return $input;
        }

        return false;
    }

}
