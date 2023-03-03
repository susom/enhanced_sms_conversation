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
    private $descriptive_messages = [];     // These are text messages to be sent
    private $current_question;              // This is the active question label
    private $instructions;                  // This is how to complete the question (is choices for enum)
    private $choices;                       // This is an array of the valid choices
    private $action_tags = [];
    private $invalid_response;

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
        foreach($this->dict as $field_name => $dd) {
            // Start on the first field of the form if not already set
            if (empty($this->start_field)) $this->start_field = $field_name;

            // Mark true if we find the 'starting field' for our scan
            if (!$found && $this->start_field == $field_name) $found=true;

            // If we haven't found the starting place, then skip to the next field
            if (!$found) continue;

            // We know we are one field past the current field when the following is true
            // So record the next field and exit
            if ($this->current_field && $this->current_field !== $field_name) {
                $this->next_field = $field_name;
                break;
            }

            // Parse out action tags
            $action_tags = $this->parseActionTags($dd["field_annotation"]);
            $this->module->emDebug("Action Tags for $field_name", $action_tags);

            // Skip any fields that are hidden-survey
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
            } else {
                // This is the next question field
                $this->current_field = $field_name;
                $this->current_question = $this->pipe($dd['field_label']);
                $this->action_tags = $action_tags;

                if (in_array($dd["field_type"], self::VALID_ENUMERATED_FIELD_TYPES)) {
                    list($this->choices, $this->instructions) = $this->parseChoicesAndInstructions($dd);
                } elseif (in_array($dd["field_type"], self::VALID_TEXT_TYPES)) {
                    $this->choices = [];
                    if (!empty($dd['text_validation_max']) && !empty($dd['text_validation_min'])) {
                        $this->instructions = "Please text a value between " . $dd['text_validation_min'] . " and " . $dd['text_validation_max'];
                    } elseif (!empty($text_validation_max)) {
                        $this->instructions = "Please text a value less than or equal to " . $dd['text_validation_max'];
                    } elseif (!empty($text_validation_min)) {
                        $this->instructions = "Please text a greater than or equal to " . $dd['text_validation_min'];
                    }
                    // TODO: Support things like date fields...
                } else {
                    throw new \Exception("Unable to parse this dd type:", $dd);
                }
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

    public function getMessages() {
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

    public function getInvalidResponse() {
        return $this->invalid_response;
    }


    /**
     * Combine previous section headers, descriptive fields, and the actual question into an array of messages
     * @return array{string}
     */
    public function getArrayOfMessagesAndQuestion() {
        return array_merge($this->getMessages(), [ $this->getQuestionLabel()]);
    }



    /** HELPERS **/

    /**
     * TODO:
     * @return mixed
     */
    public function getInvalidResponseMessage() {
        // // Set the invalid message warning as needed
        // $default_validation_warning = $this->module->getProjectSetting('nonsense-text-warning', $this->project_id);
        // $override = $this->form_script[$this->current_field]['action_tags'][$this->module::ACTION_TAG_VALIDATION_MESSAGE]['params_json'];
        // return is_null($override) ? $default_validation_warning : json_decode($override);
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
     * @param $dd
     * @return array
     */
    private function parseChoicesAndInstructions($dd)
    {
        $choices = $dd['select_choices_or_calculations'];
        if ($dd['field_type'] == "yesno") {
            $choices = "1, Yes | 0, No";
        } elseif ($dd['field_type'] == "truefalse") {
            $choices = "1, True | 0, False";
        }

        // Blow up the choices string into an array
        $arr_choices = [];
        $arr_instructions = [];
        $choice_pairs = explode("|", $choices);
        foreach ($choice_pairs as $pair) {
            list($k, $v) = array_map('trim', explode(",", $pair, 2));
            $choice_label = $this->pipe($v);
            $arr_choices[$k] = $choice_label;
            $arr_instructions[] = "[$k] $choice_label";
        }
        $instructions = implode(", ", $arr_instructions);
        return [$arr_choices, $instructions];
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

            // Set the invalid response
            if (isset($this->action_tags[$this->module::ACTION_TAG_INVALID_RESPONSE])) {
                $response = "custom"; //todo
            } else {
                $response = $this->module->getProjectSetting('nonsense-text-warning', $this->project_id);
            }
            $this->invalid_response = $response;

            $this->module->emDebug("We were unable to match $input to any of the choices:", $choices);
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
