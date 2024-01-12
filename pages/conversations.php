<?php
namespace Stanford\EnhancedSMSConversation;

/** @var EnhancedSMSConversation $module */

// TODO: Make a list of all conversations
$module->injectJSMO();

?>

<div>

<table id="conversationTable" class="display pr-2" style="width:100%">
    <thead>
        <tr>
            <th>LogID</th>
            <th>Date Created</th>
            <th>Record</th>
            <th>Reminder TS</th>
            <th>Expiry TS</th>
            <th>Number</th>
            <th>State</th>
            <th>Current Field</th>
            <th>Last Response</th>
        </tr>
    </thead>
</table>
    <div>
        <button id="refreshConversations" type="button" class="btn btn-sm btn-success">Refresh Conversation List</button>
        <button id="deleteSelectedConversations" type="button" class="btn btn-sm btn-danger">Delete Selected Conversations</button>
        <button id="deleteConversations" type="button" class="btn btn-sm btn-danger">Delete All Conversations</button>
    </div>
</div>


<script>

$(document).ready(function () {

    ExternalModules.Stanford.EnhancedSMSConversation.getConversations();

    $('#conversationTable').on( 'click', 'tr', function () {
        $(this).toggleClass('selected');
    } );

    $('#refreshConversations').click(function() {
        ExternalModules.Stanford.EnhancedSMSConversation.refreshConversations();
    });

    $('#deleteSelectedConversations').click(function() {
        ExternalModules.Stanford.EnhancedSMSConversation.deleteSelectedConversations();
    });

    $('#deleteConversations').click(function() {
        if (window.confirm('Are you sure you want to delete all conversations?')) {
            ExternalModules.Stanford.EnhancedSMSConversation.deleteConversations();
        }
    });

});
</script>


<?php
/**
    select reml.log_id,
    reml.timestamp,
    reml.record,
    remlp1.value as 'reminder_ts',
    remlp2.value as 'expiry_ts',
    remlp3.value as 'cell_number',
    remlp4.value as 'state',
    remlp5.value as 'current_field',
    remlp6.value as 'last_response_ts'
    from
    redcap_external_modules_log reml
    left join redcap_external_modules_log_parameters remlp1 on reml.log_id = remlp1.log_id and remlp1.name='reminder_ts'
    left join redcap_external_modules_log_parameters remlp2 on reml.log_id = remlp2.log_id and remlp2.name='expiry_ts'
    left join redcap_external_modules_log_parameters remlp3 on reml.log_id = remlp3.log_id and remlp3.name='cell_number'
    left join redcap_external_modules_log_parameters remlp4 on reml.log_id = remlp4.log_id and remlp4.name='state'
    left join redcap_external_modules_log_parameters remlp5 on reml.log_id = remlp5.log_id and remlp5.name='current_field'
    left join redcap_external_modules_log_parameters remlp6 on reml.log_id = remlp6.log_id and remlp6.name='last_response_ts'

    where
    reml.message = 'ConversationState'
    and  reml.project_id = 27832
    ;
*/
