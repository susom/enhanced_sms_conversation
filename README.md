# Enhanced SMS Conversation

This module mimics the built-in REDCap SMS survey conversation but adds more flexibility around reminders, expiration, and custom context based on event/instance.

For an ASI to trigger ESMS, you must place '@ESMS' in the subject of the email

The conversations page on the left sidebar can be used to clear the state of past/active conversations.  If you delete
records, you should purge any conversations related to those records or else a new record with the same record_id might
inherit those conversation states.



TODO:
- Improve documentation
- Add actiontags to menu
- Add cron to purge conversations from records that are no longer part of the project (auto-delete handling)
-
