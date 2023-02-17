<?php
namespace Stanford\EnhancedSMSConversation;

/** @var EnhancedSMSConversation $module */

include_once 'classes/FormManager.php';

use REDCap;
echo "TESTING : Hello from $module->PREFIX";

$project_id = $module->getProjectId();

$url = $module->getUrl('pages/inbound.php', true, true);
echo "<br><br>This is the TRAM innbound link: <br>".$url;

$module->emDebug("INBOUND: $url");


$form = "thursday";
$event = "week_1_sms_arm_1";


//TEST1:  TEST OF ALL DESCRIPTIVE FIELDS
if (false) {

    $record_id = 1;
    $form = "welcome_sms";
    $event = "baseline_arm_1";

    $fm = new FormManager($module, $form, $event, $project_id);

    $all_steps = $fm->getNextSMS('', $record_id,$event);
    $module->emDebug("from 0", $all_steps);

    $all_steps = $fm->getNextSMS('welcome_1', $record_id,$event);
    $module->emDebug("from welcome 1", $all_steps);

    $all_steps = $fm->getNextSMS('welcome_2', $record_id,$event);
    $module->emDebug("from welcome_2", $all_steps);

    $andy_steps = $fm->getNextQuestion('');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id, $event);
    $module->emDebug("a: from 0", $foo);
    $andy_steps = $fm->getNextQuestion('welcome_2');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id, $event);
    $module->emDebug("a: from welcome_2", $foo);
}

if (false) {

    $record_id_control = 1; //CONTROL
    $record_id_goal_support = 2; //GOAL SUPPORT
    $record_id_coaching = 3;  //COACHING

    $form = "thursday";
    $event = "week_1_sms_arm_1";

    $fm = new FormManager($module, $form, $event, $project_id);

    $all_steps = $fm->getNextSMS('desd', $record_id_control, $event);
    $module->emDebug("for control", $all_steps);
}

//TEST3:  TEST BRANCHING OF EVENT
if (true) {

    $record_id_control = 1; //CONTROL
    $record_id_goal_support = 2; //GOAL SUPPORT
    $record_id_coaching = 3;  //COACHING

    $form = "thursday";
    $event = "week_1_sms_arm_1";

    $current_sms = 'wplan';

    //set record 3: wplan=0


    $fm = new FormManager($module, $form, $event, $project_id);

    //expect "your body will reward you"
    $all_steps = $fm->getNextSMS($current_sms, $record_id_coaching,$event);
    $module->emDebug("for $current_sms", $all_steps);


    $andy_steps = $fm->getNextQuestion($current_sms);
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_coaching, $event);
    $module->emDebug("a: for $current_sms", $foo);



    $event = "week_2_sms_arm_1";

//expect "Don't be surprise...
    $all_steps = $fm->getNextSMS($current_sms, $record_id_coaching,$event);
    $module->emDebug("for $current_sms", $all_steps);


    $andy_steps = $fm->getNextQuestion($current_sms);
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_coaching, $event);
    $module->emDebug("a: for $current_sms", $foo);

}



//TEST2:   TEST BRANCHING OF RANDOMIZED GROUP
if (false) {

    $record_id_control = 1; //CONTROL
    $record_id_goal_support = 2; //GOAL SUPPORT
    $record_id_coaching = 3;  //COACHING

    $form = "thursday";
    $event = "week_1_sms_arm_1";

    $fm = new FormManager($module, $form, $event, $project_id);

    //expecting desd_ctrol
    $all_steps = $fm->getNextSMS('desd', $record_id_control,$event);
    $module->emDebug("for control", $all_steps);

    //expecting gconf
    $all_steps = $fm->getNextSMS('desd', $record_id_goal_support,$event);
    $module->emDebug("for goal support", $all_steps);


//expecting desd_ctrol
    $andy_steps = $fm->getNextQuestion('desd');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_control, $event);
    $module->emDebug("a: for control", $foo);
    //expecting gconf
    $andy_steps = $fm->getNextQuestion('desd');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id_goal_support, $event);
    $module->emDebug("a: for goal support", $foo);
}

if (false) {

    $record_id = 1;
    $form = "thursday";
    $event = "week_1_sms_arm_1";

    $record_id = 1;
    $form = "welcome_sms";
    $event = "baseline_arm_1";

    $fm = new FormManager($module, $form, $event, $project_id);

    $all_steps = $fm->getNextSMS('', $record_id, $event);
    $module->emDebug("from 0", $all_steps);
    $all_steps = $fm->getNextSMS('welcome_2', $record_id,$event);
    $module->emDebug("from sms_start", $all_steps);


    $andy_steps = $fm->getNextQuestion('');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id, $event);
    $module->emDebug("a: from 0", $foo);
    $andy_steps = $fm->getNextQuestion('welcome_2');
    $foo = $fm->getMessagesAndCurrentQuestion($andy_steps, $record_id, $event);
    $module->emDebug("a: from sms_start", $foo);

}

if (false) {
    $fm = new FormManager($module, $form, $event, $project_id);

    //check public method to get next texts given current text
    $all_steps = $fm->getNextSMS('', 1, 'week_1_sms_arm_1' );
    $all_steps = $fm->getNextSMS('sms_start', 1, 'week_1_sms_arm_1' );
    //$module->emDebug("these are the steps: ", $all_steps);

}

if (false) {
    $fm = new FormManager($module, $form, $event, $project_id);


    $start = 'wplan';
    //check public method to get next texts given current text
    $all_steps = $fm->getNextSMS($start, 1, 'week_1_sms_arm_1');
    $module->emDebug("these are the  SMS for starting at $start : ", $all_steps);
    return;

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('sms_start', 1, 'week_1_sms_arm_1');
    $module->emDebug("these are the steps: ", $all_steps);

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('desd_ctrl', 1, 'week_1_sms_arm_1');
    $module->emDebug("these are the steps: ", $all_steps);

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('gset', 1, 'week_1_sms_arm_1');
    $module->emDebug("these are the steps: ", $all_steps);
}
if (false) {
    $fm = new FormManager($module, $form, $event);

    $all_steps = $fm->getCurrentFormStep('desd_ctrl', 1, 'week_1_sms_arm_1' );
    $module->emDebug("these are the steps: ", $all_steps);
}

//set up the test CS
/**

These are the supported parameters
 * 'instrument',
 * 'event_id',
 * 'instance',
 * 'number',  - telephone number
 * 'start_ts',
 * 'current_question', - variable name?
 * 'reminder_ts',
 * 'expiry_ts',
 * 'state' - ACTIVE
 */

if (false) {

    $my_num = $module->formatNumber('6505295666');

    if ($found_cs = ConversationState::getActiveConversationByNumber($module, $my_num)) {

        var_dump($found_cs);
        $state = $found_cs->getValue('state');
        $id = $found_cs->getId();
        $module->emDebug("Found record " . $id);
    }

    $event_id = $found_cs->getValue("event_id");
    $current_question = $found_cs->getValue("current_question");
    $instrument = $found_cs->getValue("instrument");
    $record_id = $module->getRecordByNumber('16505295666');


}


