// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.EnhancedSMSConversation;

    // Extend the official JSMO with new methods
    Object.assign(module, {

        ExampleFunction: function() {
            console.log("Example Function showing module's data:", module.data);
        },

        // Ajax function calling 'TestAction'
        InitFunction: function () {
            console.log("Example Init Function");

            // Note use of jsmo to call methods
            module.ajax('TestAction', module.data).then(function (response) {
                // Process response
                console.log("Ajax Result: ", response);
            }).catch(function (err) {
                // Handle error
                console.log(err);
            });
        },

        getConversations: function () {
            module.ajax('getConversations').then(function (response) {
                console.log("RESPONSE", response);
                module.conversationTable = $('#conversationTable').DataTable({
                    data: response.data
                });
                return response;
            }).catch(function (err) {
                console.log("Error", err);
            })
        },

        refreshConversations() {
            module.conversationTable.destroy();
            module.getConversations();
        },

        deleteSelectedConversations: function() {
            // Get selected rows
            const rowsSelected = module.conversationTable.rows('.selected').data().length;
            if (rowsSelected === 0) {
                alert('You must select rows first by clicking on them');
                return;
            }

            if (window.confirm('Are you sure you want to delete ' + rowsSelected + ' conversations?')) {
                // Get selected conversation IDs
                let ids = module.conversationTable.rows('.selected').data().pluck(0).toArray();
                console.log(ids);
                module.deleteConversations(ids);
            }
        },

        deleteConversations: function(ids) {
            if (!ids) ids = [];
            module.ajax('deleteConversations', ids).then(function (response) {
                console.log("DELETE RESPONSE", response);
                module.refreshConversations();
            }).catch(function (err) {
                console.log("Error", err);
            });
        },

        lookupPhoneNumber: function(number, record_id) {
            module.ajax('LookupPhoneNumbers', {'phone_number' : number, 'record_id' : record_id}).then(function (response) {
                console.log("LookupPhone RESPONSE", response);
            }).catch(function (err) {
                console.log("Error", err);
            });
        }


    });
}
