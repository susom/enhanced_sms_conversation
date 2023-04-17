<?php

namespace Stanford\EnhancedSMSConversation;

use REDCap;
use Piping;

/**
 * The FormManager class exists to help us parse out a data dictionary for conversion to SMS
 * It only allows certain fields and helps prepare the labels and options for piping and presentation
 * over SMS
 */
class FormManager {

    /** @var EnhancedSMSConversation $module */
    private $module;

    private $form;
    private $record_id;
    private $event_id;
    private $instance;
    private $project_id;

    private $dict;                          // Data Dictionary Object

    // THESE ARE THE FIELDS THAT REPRESENT THE CURRENT STATE
    private $start_field;
    private $current_field;                 // This is the current field that should be saved to the conversation
    private $next_field;                    // This is the next field in the dictionary
    private $descriptive_messages = [];     // These are text messages to be sent from descriptive fields, one message per value
    private $current_question;              // This is the active question label
    private $instructions;                  // This is how to complete the question (if enum or text w/ validation)
    private $choices;                       // This is an array of the valid choices for validation

    private $invalid_response;

    private $form_action_tags = [];         // Action tags for the first field in the form - they are loaded always
    private $field_action_tags = [];

    private $invalid_response_message;  // TODO: Rename-- do we need this?  This is the message we give to people with an invalid response

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
     * @throws \Exception
     */
    private function buildContext() {

        $this->dict = REDCap::getDataDictionary($this->project_id, "array", false, null, [$this->form]);

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

        // Build an array of valid field_types
        $valid_field_types = array_merge(
            self::VALID_ENUMERATED_FIELD_TYPES,
            self::VALID_TEXT_TYPES,
            ["descriptive"]
        );

        // Loop through each field on the form until we find our context
        $found=false;
        $first_field = true;    // Variable to tell if it is the first field of the form (where from-level action tags go)
        foreach($this->dict as $field_name => $dd) {
            // Start on the first field of the form if not already set
            if (empty($this->start_field)) $this->start_field = $field_name;

            // Mark true if we find the 'starting field' for our scan
            if (!$found && $this->start_field == $field_name) $found=true;

            // Parse out action tags for form-level properties on first field
            if ($first_field) {
                $this->form_action_tags = $this->parseActionTags($dd["field_annotation"]);
                $first_field = false;
            }

            // If we haven't found the starting place, then skip to the next field
            if (!$found) continue;

            // We know we are one field past the current field when the following is true
            // So record the next field and exit
            if ($this->current_field && $this->current_field !== $field_name) {
                $this->next_field = $field_name;
                $this->module->emDebug("Setting next_field to $field_name");
                break;
            }

            // Set the action tags for the current field
            $this->field_action_tags = $first_field ? $this->form_action_tags : $this->parseActionTags($dd["field_annotation"]);

            // Skip any fields that are hidden or hidden-survey
            if (isset($action_tags["@HIDDEN"])) continue;
            if (isset($action_tags["@HIDDEN-SURVEY"])) continue;

            // Skip fields that have @ESMS-IGNORE
            if (isset($action_tags[$this->module::ACTION_TAG_IGNORE_FIELD])) {
                $this->module->emDebug("Skipping question $field_name as is tagged as ESMS-IGNORE");
                continue;
            }

            // Skip invalid field_types regardless
            if (!in_array($dd["field_type"], $valid_field_types)) {
                $this->module->emDebug("Skipping unsupported field type for $field_name of " . $dd["field_type"]);
                continue;
            }

            // Check to see if we skip question due to branching logic
            if (!empty($dd['branching_logic']) && $this->skipDueToBranching($dd['branching_logic'])) {
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
            } else {
                // This is the next question field
                $this->current_field     = $field_name;
                $this->current_question  = $this->pipe($dd['field_label']);
                $this->module->emDebug("FM: Set current_field = $field_name");

                // Set the choices and instructions properties
                list($this->choices, $this->instructions) = $this->parseChoicesAndInstructions($dd);
            }
        }
    }






    /** GETTERS */
    public function getCurrentField() {
        return $this->current_field;
    }

    public function getNextField() {
        return $this->next_field;
    }

    public function getStartField() {
        return $this->start_field;
    }

    public function getDescriptiveMessages() {
        return $this->descriptive_messages;
    }

    public function getQuestionLabel() {
        return $this->current_question;
    }

    public function getChoices() {
        return $this->choices;
    }

    public function getInstructions() {
        return $this->instructions ?? '';
    }

    public function getInvalidResponseMessage() {
        // First, try the field:
        $message = $this->parseActionTagValue($this->field_action_tags, $this->module::ACTION_TAG_INVALID_RESPONSE);
        if (is_null($message)) {
            // Then try the form
            $message = $this->parseActionTagValue($this->form_action_tags, $this->module::ACTION_TAG_INVALID_RESPONSE);
        }
        if (is_null($message)) {
            // Then goto the EM Settings
            $message = $this->module->getProjectSetting('invalid-response-text');
        }
        return $message ?? '';
    }

    public function getFieldDict() {
        return $this->dict[$this->current_field];
    }


    /**
     * Combine previous section headers, descriptive fields, and the actual question into an array of messages
     * @return array{string}
     */
    public function getNewQuestionMessages() {
        return array_filter(
            array_merge(
                $this->getDescriptiveMessages(),
                [ $this->getQuestionSms() ]
            )
        );
    }

    /**
     * Based on the project settings, include or do not include the instructions in a new question
     * @return string
     */
    public function getQuestionSms() {
        // Used when asking a new question
        $qas = $this->module->getProjectSetting('question-asking-style');
        if ($qas == "high") {
            $result = $this->getQuestionLabel() . " " . $this->getInstructions();
        } else {
            $result = $this->getQuestionLabel();
        }
        return $result;
    }


    /**
     * Get the reminder message from action tag or use default
     * @return string
     */
    public function getReminderMessage() {
        $message = $this->parseActionTagValue($this->form_action_tags, $this->module::ACTION_TAG_REMINDER_MESSAGE);
        if (is_null($message)) {
            $message = $this->module->getProjectSetting('default-reminder-text');
        }
        return $message;
    }

    public function getReminderTime() {
        $minutes = $this->parseActionTagValue($this->form_action_tags, $this->module::ACTION_TAG_REMINDER_TIME);
        if (is_null($minutes)) {
            $minutes = $this->module->getProjectSetting('default-expiry-minutes');
        }
        return $minutes;

    }

    public function getReminderMaxCount() {
        $count = $this->parseActionTagValue($this->form_action_tags, $this->module::ACTION_TAG_REMINDER_MAX_COUNT);
        if (is_null($count)) {
            $count = $this->module->getProjectSetting('default-reminder-max-count');
        }
        return $count;
    }

    public function getExpiryMessage() {
        $message = $this->parseActionTagValue($this->form_action_tags, $this->module::ACTION_TAG_EXPIRY_MESSAGE);
        if (is_null($message)) {
            $message = $this->module->getProjectSetting('default-expiry-text');
        }
        return $message;
    }

    /**
     * Return the number of minutes until expiration based on action tags or defaults
     * @return mixed|null
     */
    public function getExpiryTime() {
        $minutes = $this->parseActionTagValue($this->form_action_tags, $this->module::ACTION_TAG_EXPIRY_TIME);
        if (is_null($minutes)) {
            $minutes = $this->module->getProjectSetting('default-expiry-minutes');
        }
        return $minutes;
    }


    /** HELPERS **/

    /**
     * Return the action tag value or null if not specified
     * @param $action_tags
     * @param $tag
     * @return mixed|null
     */
    private function parseActionTagValue($action_tags, $tag) {
        $result = null;
        if (isset($action_tags[$tag])) {
            $val = json_decode($action_tags[$tag]['params_json']);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->module->emDebug("Setting $tag to $val");
                $result = $val;
            } else {
                $this->module->emDebug("Error parsing $tag", $action_tags[$tag]);
            }
        }
        return $result;
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
     * Parse out the enumerated choices and instructions for them
     * returns an array with two members:
     *   The first is an array of valid choices: [ "a" => "Apple", "b" => "Banana" ]
     *   The second is a string version of the choice_instructions: "[a] for Apple, [b] for Banana"
     * @param $dd
     * @return array
     */
    private function parseChoicesAndInstructions($dd)
    {
        $arr_choices = [];
        $instructions = "";

        if (in_array($dd["field_type"], self::VALID_ENUMERATED_FIELD_TYPES)) {
            $choices = $dd['select_choices_or_calculations'];
            if ($dd['field_type'] == "yesno") {
                $choices = "1, Yes | 0, No";
            } elseif ($dd['field_type'] == "truefalse") {
                $choices = "1, True | 0, False";
            }

            // Blow up the choices string into an array
            $arr_instructions = [];
            $choice_pairs = explode("|", $choices);
            foreach ($choice_pairs as $pair) {
                list($k, $v) = array_map('trim', explode(",", $pair, 2));
                $choice_label = $this->pipe($v);
                $arr_choices[$k] = $choice_label;
                $arr_instructions[] = "'$k' for $choice_label";
            }
            $arr_ins_count = count($arr_instructions);
            if ($arr_ins_count == 2) {
                $instructions = implode(" or ", $arr_instructions);
            } elseif ($arr_ins_count > 10) {
                // Convert a long list into a smaller set of example values, e.g. :  AL for Alabama, AK for Alaska, ..., WI for Wisconsin, WY for Wyoming
                $subset = array_merge(array_slice($arr_instructions, 0, 2), ["..."], array_slice($arr_instructions, $arr_ins_count - 2, 2));
                $instructions = implode(", ", $subset);
            } else {
                $instructions = implode(", ", array_slice($arr_instructions, 0, $arr_ins_count - 1)) . ", or" . $arr_instructions[$arr_ins_count];
            }
        } elseif (in_array($dd["field_type"], self::VALID_TEXT_TYPES)) {
            if (!empty($dd['text_validation_max']) && !empty($dd['text_validation_min'])) {
                $instructions = "Please text a value between " . $dd['text_validation_min'] . " and " . $dd['text_validation_max'] . ".\n";
            } elseif (!empty($text_validation_max)) {
                $instructions = "Please text a value less than or equal to " . $dd['text_validation_max'] . ".\n";
            } elseif (!empty($text_validation_min)) {
                $instructions = "Please text a greater than or equal to " . $dd['text_validation_min'] . ".\n";
            } else {
                // TODO: Add date validation, etc...
            }
            $arr_choices = [];
        }
        return [$arr_choices, $instructions];
    }



    /**
     * Determine whether or not a question should be skipped
     * @param string $branching_logic
     * @return bool
     */
    private function skipDueToBranching($branching_logic) {
        if (!empty($branching_logic)) {
            $result = \REDCap::evaluateLogic($branching_logic, $this->project_id, $this->record_id, $this->event_id);
            // if result is true, then do not skip.  If result is false, the skip.
            $this->module->emDebug("Evaluating " . json_encode($branching_logic) . " for record $this->record_id - " . ($result ? "TRUE": "FALSE"));
            $skip = !$result;
        } else {
            $skip = false;
        }
        return $skip;
    }


    /**
     * Given our current field, see if the response is valid
     * @param $response
     * @return false|string
     */
    public function validateResponse($response) {

        if (!empty($this->choices)) {
            // This is an enumerated field_type

            // In testing with cases I found issues, so I think we should lowercase everything
            $input = strtolower(trim($response));

            // Also think we should remove any punctuation marks or other non-alphanum chars, etc..
            $input = preg_replace("/[^a-z0-9]/", '', $input);

            // Lowercase everything (keys and values)
            $choices = array_map('strtolower', array_change_key_case($this->choices, CASE_LOWER));

            if (isset($choices[$input])) {
                // Found a direct match to an array key
                // Input is always a string, but numeric-like keys are made into ints when part of an array
                // It appears isset coerces a numeric string to an int for comparison: so isset($a["1"]) is same as $a[1]
                $this->module->emDebug("Matched $input to choice key for label $choices[$input]");
                return $input;
            } else if (false !== $key = array_search($input, $choices)) {
                // Try to find a value match in the choices array (e.g. yes for 1, yes)
                // Found a match to a label of a choice
                $this->module->emDebug("Matched $input to choice $key of $this->current_field");
                return strval($key);
            } else {
                // Try to use value aliases, e.g. a radio field type with 1,Yes | 0,No, | 2,Maybe - it isn't a strict
                // boolean field, however, this match should match a response of 'y' to the 'Yes' label because
                // they are in the same yes-like alias group
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
            }

            $this->module->emDebug("We were unable to match $input to any of the choices: " . json_encode($choices));
            return false;
        } else {
            // TODO - validate TEXT!
            $input=trim($response);
            return $input;
        }
    }


    /**
     *
     * Parses a string for arrays of actiontags (optinally filtering by the supplied tag)
     *
     * @param $string           The string to be parsed for actiontags (in the format of <code>@FOO=BAR or @FOO={"param":"bar"} or @FOO="String" </code>
     * @param null $tag_only    If you wish to select a single tag
     * @return array            returns the match array with the key equal to the tag and an array containing keys of 'params, params_json and params_text'
     */
    private function parseActionTags($string, $tag_only = null)
    {
        // EXAMPLE OUTPUT ARRAY:
        // [@ESMS-INVALID-RESPONSE] => Array
        // (
        //     [params] => "We don't understand. Please text Yes or No"
        //     [params_json] => "We don't understand. Please text Yes or No"
        //     [params_text] =>
        // )

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
