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

if (false) {
    $fm = new FormManager($module, $form, $event, $project_id);
    //$fm->loadForm();

    $key = '';
    //check private helper method for getting next available field
    $w = $fm->getNextStepInScript($key);
    $module->emDebug("first  next is: ". $w);
    $module->emDebug("first next is: ". $w['field_name']);

    $key = 'sms_start';
    $w = $fm->getNextStepInScript($key);
    $module->emDebug("$key next is: ". $w);
    $module->emDebug("$key next is: ". $w['field_name']); return;

    $w = $fm->getNextStepInScript('abs_6');
    $module->emDebug("next is: ". $w['field_name']);

    $w = $fm->getNextStepInScript('pss_6');
    $module->emDebug("pss_6 next is: ". $w);
}

if (true) {
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
    $all_steps = $fm->getNextSMS($start, 1, 'week_1_sms_arm_1' );
    $module->emDebug("these are the  SMS for starting at $start : ", $all_steps);
    return;

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('sms_start', 1, 'week_1_sms_arm_1' );
    $module->emDebug("these are the steps: ", $all_steps);

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('desd_ctrl', 1, 'week_1_sms_arm_1' );
    $module->emDebug("these are the steps: ", $all_steps);

    //check public method to get next texts given current text
    $all_steps = $fm->getCurrentFormStep('gset', 1, 'week_1_sms_arm_1' );
    $module->emDebug("these are the steps: ", $all_steps);

    //check
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
    //i think this is how you initiate it
    $cs = new ConversationState($module, "CS");
    $cs->setValue("state", "ACTIVE");
    $cs->setValue("number", "6505295666");
    $cs->getId();
    $cs->save();

    var_dump($cs);
}

if (false) {
    $my_num = $module->formatNumber('6505295666');
    if ($found_cs = ConversationState::getActiveConversationByNumber($module, $my_num)) {

        var_dump($found_cs);
        $state = $found_cs->getValue('state');
        $id = $found_cs->getId();
        $module->emDebug("Found record " . $id);
    }
    return;
    $event_id = $found_cs->getValue("event_id");
    $current_question = $found_cs->getValue("current_question");
    $instrument = $found_cs->getValue("instrument");
    $record_id = $module->getRecordByNumber('16505295666');


}


