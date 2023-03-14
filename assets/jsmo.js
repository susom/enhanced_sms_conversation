// This file extends the default JSMO object with methods for this EM
;{
    // Define the jsmo in IIFE so we can reference object in our new function methods
    const module = ExternalModules.Stanford.EnhancedSMSConversation;;

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

                $('#example').DataTable({
                    data: response.data
                    // "ajax": function(data, callback, settings) {
                    //     callback(
                    //         ExternalModules.Stanford.EnhancedSMSConversation.getConversations()
                    //     )
                    // }
                });



                return response;
            }).catch(function (err) {
                console.log("Error", err);
            })
        }


    });
}
