<?php
namespace Stanford\EnhancedSMSConversation;

/** @var EnhancedSMSConversation $module */

// Replace this with your module code
echo "Hello from $module->PREFIX";

// $CS = ConversationState::buildConversationStateFromId($module, 5);


$c = 3;


if (false) {
    $CS = new ConversationState($module, "CS");

    //$CS->setValue("foo","bar");

    $vals = [
        "fruit" => "apple",
        "number" => 6503803405
    ];

    $CS->setValues($vals);

    //$CS->setValue("timestamp",date("Y-m-d H:i:s") );

    $r = $CS->save();
    var_dump($r);
    var_dump($CS);
}

if (false) {
    // 1782 and 1783
    $CS = new ConversationState($module, "CS", 1783);
    var_dump($CS);


    var_dump($CS->delete());


    // $CS->t();
    // $CS->setValue("FOO", "bar");
    // $CS->setValue("instrument", "TEST1");
    // $CS->save();

    // var_dump($CS);




}

if (true) {
    $CS = new ConversationState($module, "CS");
    $CS->setValue("state", "ACTIVE");
    $CS->setValue("number", "16503803405");
    $CS->save();

    var_dump($CS);
}
