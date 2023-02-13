<?php
namespace Stanford\EnhancedSMSConversation;

/** @var EnhancedSMSConversation $module */

include_once 'classes/FormManager.php';

use REDCap;
echo "TESTING : Hello from $module->PREFIX";
$module->emDebug("booya");


$form = "thursday";
$event = "week_1_sms_arm_1";

if (false) {
    $fm = new FormManager($module, $form, $event);
    $fm->loadForm();



    $w = $fm->getNextStepInScript('wplan');
    $module->emDebug("wplan next is: ". $w['field_name']);

    $w = $fm->getNextStepInScript('abs_6');
    $module->emDebug("next is: ". $w['field_name']);

    $w = $fm->getNextStepInScript('pss_6');
    $module->emDebug("pss_6 next is: ". $w);
}


if (true) {
    $fm = new FormManager($module, $form, $event);
    $fm->loadForm();

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
    //i think this is how you initiate it
    $cs = new ConversationState($module, "CS");
    $cs->setValue("state", "ACTIVE");
    $cs->setValue("number", "6505295666");
    $cs->getId();
    $cs->save();

    var_dump($cs);
}

if (false) {
    $found_cs = ConversationState::getActiveConversationByNumber($module, "16505295666");
    var_dump($found_cs);
    $state= $found_cs->getValue('state');
    $id = $found_cs->getId();
    $module->emDebug("Found record ". $id);
    return;
    $event_id = $found_cs->getValue("event_id");
    $current_question = $found_cs->getValue("current_question");
    $instrument = $found_cs->getValue("instrument");
    $record_id = $module->getRecordByNumber('16505295666');


}


