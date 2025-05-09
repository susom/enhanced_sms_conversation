<?php
namespace Stanford\EnhancedSMSConversation;
/** @var \Stanford\EnhancedSMSConversation\EnhancedSMSConversation $this */

// initiate REDCap JSMO object
$this->injectJSMO();
?>


<script>
    var phone_field = "<?php echo $this->getProjectSetting('phone-field');?>"
    var record_id = "<?php echo $this->record;?>"
    $(document).ready(function () {
        console.log(phone_field)

        $('input[name="'+phone_field+'"]').on('blur', function () {
            var phoneNumber = $(this).val();
            // Simple phone number validation (US format, 10 digits)
            var phoneRegex = /^\(?([0-9]{3})\)?[-.● ]?([0-9]{3})[-.● ]?([0-9]{4})$/;

            if(phoneRegex.test(phoneNumber)){
                ExternalModules.Stanford.EnhancedSMSConversation.lookupPhoneNumber(phoneNumber, record_id);
            }
        });

    });
</script>
