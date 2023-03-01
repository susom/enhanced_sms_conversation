<?php

namespace Stanford\EnhancedSMSConversation;

use PhpParser\Node\Scalar\String_;
use REDCap;
use Piping;

class FormManager {

    /** @var EnhancedSMSConversation $module */
    private $module;
    private $form;
    private $start_field;
    private $record_id;
    private $event_id;
    private $instance;
    private $project_id;
    private $form_script;    // Parsed version of data dictionary




    private $current_field;  // This is the current field that should be saved to the conversation state after delivery of messages

    private $descriptive_messages = [];
    private $current_question;
    private $instructions;  // This is how to complete the question (is choices for enum)
    private $choices;       // This is an array of the valid choices

    private $invalid_response_message;  // This is the message we give to people with an invalid response

    const VALID_ENUMERATED_FIELD_TYPES = [
        "yesno", "truefalse", "radio", "dropdown"
    ];

    const VALID_TEXT_TYPES = [
        "text"
    ];

    // A second attempt at using aliases to match responses (make sure all are lowercase)
    const ALIASES = [
        "Yes-like"      => [ 'yes', 'y', 'sure', 'yep', 'ok' ],
        "No-like"       => [ 'no', 'n', 'nope', 'na', 'cant' ],
        "True-like"     => [ 'true', 't', 'yes', 'y', 'ok' ],
        "False-like"    => [ 'false', 'f', 'no', 'n', 'nope' ]
    ];

    public function __construct($module, $form, $start_field, $record_id, $event_id, $project_id, $instance = 1) {
        $this->module      = $module;
        $this->form        = $form;
        $this->start_field = $start_field;
        $this->record_id   = $record_id;
        $this->event_id    = $event_id;
        $this->project_id  = $project_id;
        $this->instance    = $instance;

        // Create Parse the metadata for this form
        $this->buildContext();
    }

    /**
     * Create a parsed version of the form script that can be used for SMS replies
     *
     * Returns an array of fields of that form.
     *
     * @return array
     * @throws \Exception
     */
    private function buildContext() {

        $dict = REDCap::getDataDictionary($this->project_id, "array", false, null, [$this->form]);

        /*
         * "field_name":"desc_1",
         * "form_name":"survey_1",
         * "section_header":"",
         * "field_type":"descriptive",
         * "field_label":"Here is a descriptive field",
         * "select_choices_or_calculations":"",
         * "field_note":"",
         * "text_validation_type_or_show_slider_number":"",
         * "text_validation_min":"",
         * "text_validation_max":"",
         * "identifier":"",
         * "branching_logic":"",
         * "required_field":"",
         * "custom_alignment":"",
         * "question_number":"",
         * "matrix_group_name":"",
         * "matrix_ranking":"",
         * "field_annotation":""
         */

        $valid_field_types = array_merge(
            self::VALID_ENUMERATED_FIELD_TYPES,
            self::VALID_TEXT_TYPES,
            ["descriptive"]
        );

        foreach($dict as $field_name => $dd) {
            // Start on the first field of the form if not already set
            if (empty($this->start_field)) $this->start_field = $field_name;

            //

            // Parse out action tags
            $action_tags = $this->parseActionTags($dd["field_annotation"]);

            // Skip any fields that are hidden-survey
            if (isset($action_tags["@HIDDEN-SURVEY"])) continue;

            // Skip fields that have @ESC-IGNORE
            if (isset($action_tags[$this->module::ACTION_TAG_IGNORE_FIELD])) {
                $this->module->emDebug("Skipping question $field_name as is tagged as ESC-IGNORE");
                continue;
            }

            // Skip invalid field_types regardless
            if (!in_array($dd["field_type"], $valid_field_types)) continue;


            // Check to see if we skip question due to branching logic
            if ($this->skipDueToBranching($dd['branching_logic'])) {
                $this->module->emDebug("Skipping $field_name for record $this->record_id due to branching logic");
                continue;
            }

            // Add section headers if present
            if ($dd['section_header']) {
                // Append section header
                $this->descriptive_messages[] = $this->pipe($dd['section_header']);
            }

            if ($dd["field_type"] == "descriptive") {
                $this->descriptive_messages[] = $this->pipe($dd['field_label']);
            } elseif (in_array($dd["field_type"], self::VALID_ENUMERATED_FIELD_TYPES)) {
                $this->current_question = $this->pipe($dd['field_label']);
                $this->choices = $this->parseChoices($dd);
                $this->instructions = "TODO: put choice options in here ";
                $this->current_field = $field_name;
                break;
            } elseif (in_array($dd["field_type"], self::VALID_TEXT_TYPES)) {
                $this->current_question = $this->pipe($dd['field_label']);
                if (!empty($dd['text_validation_max']) && !empty($dd['text_validation_min'])) {
                    $this->instructions = "Please text a value between " . $dd['text_validation_min'] . " and " . $dd['text_validation_max'];
                } elseif (!empty($text_validation_max)) {
                    $this->instructions = "Please text a value less than or equal to " . $dd['text_validation_max'];
                } elseif (!empty($text_validation_min)) {
                    $this->instructions = "Please text a greater than or equal to " . $dd['text_validation_min'];
                }
                $this->current_field = $field_name;
                break;
            } else {
                $this->module->emError("Unable to parse this dd type:", $dd);
            }
        }
            // // // Set the current question to the first question if it is currently empty
            // // if (empty($start_field)) $start_field = $this->getNextField();
            // // $this->start_field = $start_field;
            // //
            // // // Loop through the script until we find the start_field
            // // $found = false;
            // // foreach ($this->form_script as $field_name => $meta) {
            // //     // Once we reach the start question, we stop skipping and start processing
            // //     if (!$found && $field_name !== $start_field) continue;
            // //
            // //     // We've identified our starting position in the form_script
            // //     $found=true;
            // //     $this->module->emDebug("Started at $start_field - now at $field_name");
            // //
            // //
            // //     // Process Label for current question
            // //     $label_raw = $meta['field_label'];
            // //     $label = $this->pipe($label_raw,$record_id,$instance);
            // //     if ($label !== $label_raw) {
            // //         $this->module->emDebug("Piping changed label:", $label_raw, $label);
            // //     }
            // //
            // //     // If it is descriptive, we continue:
            // //     if ($meta['field_type'] == "descriptive") {
            // //         // Load the descriptive label into the pre_labels array
            // //         if (!empty($label)) $pre_labels[] = $label;
            // //         // $this->module->emDebug("$field_name is descriptive, so we will go on to the next field");
            // //     } else {
            // //         // We must have a question where we want to ask something - let's mark this as the current_field
            // //         $current_field = $field_name;
            // //
            // //         // Let's get the label
            // //         $label_raw = empty($label) ? '' : $label;
            // //         $question = $this->pipe($label_raw, $record_id, $instance);
            // //
            // //         if (!empty($meta['preset_choices'])) {
            // //             $opts = [];
            // //             foreach ($meta['preset_choices'] as $k => $v) {
            // //                 // Check value label for piping:
            // //                 $this_v = $this->pipe($v,$record_id,$instance);
            // //                 $opts[] = "[$k] $this_v";
            // //             }
            // //             $choices = implode(", ", $opts);
            // //         }
            // //         break;  // Stop at first question that needs an answer
            // //     }
            // // }
            // //
            // // // Save all the current context
            // // $this->start_field      = $start_field;
            // // $this->current_field    = $current_field;
            // // $this->current_choices  = $choices;
            // // $this->current_question = $question;
            // // $this->pre_labels       = $pre_labels;
            // //
            //
            //
            //
            //
            //
            //
            //
            // $field_type             = $dd["field_type"];
            // $annotation_arr         = $this->parseActionTags($dd["field_annotation"]);
            // $field_label            = $dd["field_label"];
            // $choices                = $dd["select_choices_or_calculations"];
            // $branching_logic        = $dd["branching_logic"];
            // $text_validation_min    = $dd["text_validation_min"];
            // $text_validation_max    = $dd["text_validation_max"];
            //
            //
            // // Build customized metadata object for the given field.  This does not yet include BRANCHING or PIPING
            // $meta = [
            //     "field_name"        => $field_name,
            //     "field_type"        => $field_type,
            //     "field_label"       => $field_label,
            //     "branching_logic"   => $branching_logic,
            //     "instructions"      => '',
            //     "action_tags"       => $annotation_arr
            // ];
            //
            // // PROCESS PRESET CHOICES
            // // Create an array of all choices for enumerated types
            //
            //     // Blow up the choices string into an array
            //     $preset_choices = array();
            //     $choice_pairs = explode("|",$choices);
            //     foreach($choice_pairs as $pair){
            //         list($k, $v) = array_map('trim', explode(",",$pair,2));
            //         $preset_choices[$k] = $v;
            //     }
            //     $meta["preset_choices"] = $preset_choices;
            // }
            //
            //
            // }
            //
            // $form_script[$field_name] = $meta;
        // }

        // $this->module->emDebug("Form script: ", $form_script);
        // return $form_script;
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
     * Pipe a string for the current record
     * @param $text
     * @return string
     */
    private function pipe($text) {
        $pipe = trim(Piping::replaceVariablesInLabel($text, $this->record_id, $this->event_id, $this->instance, array(),
            false, $this->project_id, false, $this->form));
        $strip = trim(strip_tags($pipe));
        return $strip;
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
     * Set Starting Location
     *
     * This method figures out what messages need to be sent depending on where you start.
     *
     * If current_field is empty, then we start at the beginning of the instrument.  The output
     * will contain the next field.
     *
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
    public function setStartingField($start_field, $record_id, $instance=1) {
        $pre_labels    = []; // Array of posts from start_field to current question (descriptive fields)
        $question      = ''; // Actual text for the current question
        $choices       = ''; // Options for the question (e.g. Reply Y for yes)
        $current_field = ''; // This is the question that we are awaiting a response on
        $current_instructions = '';

        // Set the current question to the first question if it is currently empty
        if (empty($start_field)) $start_field = $this->getNextField();
        $this->start_field = $start_field;

        // Loop through the script until we find the start_field
        $found = false;
        foreach ($this->form_script as $field_name => $meta) {
            // Once we reach the start question, we stop skipping and start processing
            if (!$found && $field_name !== $start_field) continue;

            // We've identified our starting position in the form_script
            $found=true;
            $this->module->emDebug("Started at $start_field - now at $field_name");

            // Check to see if we skip question due to branching logic
            $branching_logic = $meta['branching_logic'];
            if (!empty($branching_logic) && $this->skipDueToBranching($meta['branching_logic'], $record_id)) {
                $this->module->emDebug("Skipping $field_name for record $record_id due to branching logic");
                continue;
            }

            // Process Label for current question
            $label_raw = $meta['field_label'];
            $label = $this->pipe($label_raw,$record_id,$instance);
            if ($label !== $label_raw) {
                $this->module->emDebug("Piping changed label:", $label_raw, $label);
            }

            // If it is descriptive, we continue:
            if ($meta['field_type'] == "descriptive") {
                // Load the descriptive label into the pre_labels array
                if (!empty($label)) $pre_labels[] = $label;
                // $this->module->emDebug("$field_name is descriptive, so we will go on to the next field");
            } else {
                // We must have a question where we want to ask something - let's mark this as the current_field
                $current_field = $field_name;

                // Let's get the label
                $label_raw = empty($label) ? '' : $label;
                $question = $this->pipe($label_raw, $record_id, $instance);

                if (!empty($meta['preset_choices'])) {
                    $opts = [];
                    foreach ($meta['preset_choices'] as $k => $v) {
                        // Check value label for piping:
                        $this_v = $this->pipe($v,$record_id,$instance);
                        $opts[] = "[$k] $this_v";
                    }
                    $choices = implode(", ", $opts);
                }
                break;  // Stop at first question that needs an answer
            }
        }

        // Save all the current context
        $this->start_field      = $start_field;
        $this->current_field    = $current_field;
        $this->current_choices  = $choices;
        $this->current_question = $question;
        $this->pre_labels       = $pre_labels;

        // return [
        //     "before"        => $pre_labels,
        //     "question"      => $question,
        //     "options"       => $choices,
        //     "current_field" => $current_field
        // ];
    }


    public function getCurrentField() {
        return $this->current_field;
    }

    public function getPreLabels() {
        return $this->pre_labels;
    }

    public function getQuestionLabel() {
        return $this->current_question;
    }

    public function getChoices() {
        return $this->current_choices;
    }

    public function getInstructions() {
        return $this->form_script[$this->current_field]['instructions'] ?? '';
    }

    public function getInvalidResponseMessage() {
        // Set the invalid message warning as needed
        $default_validation_warning = $this->module->getProjectSetting('nonsense-text-warning', $this->project_id);
        $override = $this->form_script[$this->current_field]['action_tags'][$this->module::ACTION_TAG_VALIDATION_MESSAGE]['params_json'];
        return is_null($override) ? $default_validation_warning : json_decode($override);
    }


    public function parseChoices($dd)
    {
        $choices = $dd['select_choices_or_calculations'];
        if ($dd['field_type'] == "yesno") {
            $choices = "1, Yes | 0, No";
        } elseif ($dd['field_type'] == "truefalse") {
            $choices = "1, True | 0, False";
        }

        // Blow up the choices string into an array
        $preset_choices = array();
        $choice_pairs = explode("|", $choices);
        foreach ($choice_pairs as $pair) {
            list($k, $v) = array_map('trim', explode(",", $pair, 2));
            $choice_label = $this->pipe($v);
            $preset_choices[$k] = $choice_label;
        }
        return $preset_choices;
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
                // current_field not found in keys
                $this->module->emError("Was unable to find $current_field in script - unable to continue to next");
                $next_position = -1; // non-valid position
            } else {
                $next_position = $position + 1;
            }
        }
        return $keys[$next_position] ?? null;
    }


    /**
     * Determine whether or not a question should be skipped
     * @param string $branching_logic
     * @return bool
     */
    private function skipDueToBranching($branching_logic) {
        $skip = false;
        if (!empty($branching_logic)) {
            $skip = !\REDCap::evaluateLogic($branching_logic, $this->project_id, $this->record_id, $this->event_id);
            $this->module->emDebug("Evaluating branching logic for $this->record_id in event $this->event_id with $branching_logic", !$skip);
        }
        return $skip;
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
            return false;

        } elseif ($meta['field_type'] == "text") {
            // TODO - validate TEXT!
            $input=trim($response);
            return $input;
        }

        return false;
    }


    /**
     *
     * Parses a string for arrays of actiontags (optinally filtering by the supplied tag)
     *
     * @param $string           The string to be parsed for actiontags (in the format of <code>@FOO=BAR or @FOO={"param":"bar"} or @FOO="String" </code>
     * @param null $tag_only    If you wish to select a single tag
     * @return array       returns the match array with the key equal to the tag and an array containing keys of 'params, params_json and params_text'
     */
    private function parseActionTags($string, $tag_only = null)
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
        // if (count($matches['actiontag']) == 0) return false;

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
