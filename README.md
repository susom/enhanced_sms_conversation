# Enhanced SMS Conversation

This module mimics the built-in REDCap SMS survey conversation but adds more flexibility around reminders, expiration, and custom context based on event/instance.




### Action Tags

| Tag                      | Where        | Description and Use                                                                                   |
|--------------------------|--------------|-------------------------------------------------------------------------------------------------------|
| @ESMS                    | ASI Subject  | Used in ASI Email Subject field to denote <br/>that the instrument should use the Enhanced SMS Module |
| @ESMS-IGNORE             | Field        | Used on a                                                                                             |
| @ESMS-INVALID-RESPONSE   | Form / Field | When someone replies with an invalid response, this message will be send back to the user |



#### The following are 'form-level' action-tags.  In order to take effect, they MUST be placed on the first field on the survey as action-tags
| Tag                      | Where  | Description and Use                                                                                   |
|--------------------------|--------|-------------------------------------------------------------------------------------------------------|
| @ESMS-REMINDER-TIME      | Form   | Set the time in minutes to send a reminder message when someone hasn't responded to a survey question |
| @ESMS-REMINDER-MESSAGE   | Form   | Set a custom reminder message for the instrument                                                      |
| @ESMS-REMINDER-MAX-COUNT | Form   | Maximum number of reminder messages to send during a survey                                           |
| @ESMS-EXPIRY-TIME        | Form   | Set the time in minutes before a survey expires                                                       |
| @ESMS-EXPIRY-MESSAGE     | Form   | Set a custom message to send when expiration occurs for the instrument                                |



Initial Question:
-(*) Label Only
- Label + instructions (if validated/enumerated)


Invalid Response:
- invalid response message
- invalid response message + label
-(*) invalid response message + label + instructions (if validated/enumerated)





    const ACTION_TAG_IGNORE_FIELD = "@ESMS-IGNORE";
    const ACTION_TAG_INVALID_RESPONSE = "@ESMS-INVALID-RESPONSE";
