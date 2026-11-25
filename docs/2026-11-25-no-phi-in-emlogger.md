# Keep phone numbers and SMS content out of em_logger (2026-11-25)

## Problem

Several log lines sent participant phone numbers, SMS text and survey answers to em_logger:

- **`formatNumber()` (every SMS).** It called `emError` four to five times on *every* call, with the number in each message, so every inbound and outbound SMS wrote the participant's number at ERROR level. `emError` ships whether or not debug logging is on.
- **Other ERROR lines** logged:
  - the whole Twilio `$_POST` (From + Body) in `pages/inbound.php`;
  - `func_get_args()` of `redcap_email` (to/subject/body);
  - the `ConversationState` object;
  - stack traces whose frames carry the cell number and body;
  - the phone number in duplicate-record / duplicate-conversation errors;
  - the participant's answer in save-error messages.
- **DEBUG lines** logged phone numbers, inbound SMS text, getData phone lookups, outbound message text and the matched answers.

## Fix

Each line now logs ids, counts and field names instead of the value: record id, `CS#` id, MessageSid, character and error counts. No behavior changes: formatting, lookups and replies work as before.

## How it was reproduced and verified (local, REDCap 17.5.9, PID 271)

A Twilio-style inbound POST went to the no-auth webhook (`pages/inbound`) from a fake `+1 650 555-xxxx` number. The new lines in `enhanced_sms_conversation.json` were then searched for the number and the body.

| Code | Lines with the sender number |
| --- | --- |
| `main` before the fix | 7 (6 at ERROR, from `formatNumber`) |
| this branch | 0 |

A second check covers `SimpleEmLogObject::save()`. It saves a ConversationState, updates it with a note that embeds a fake number (as `closeExistingConversations()` does), and then deletes it. With project debug on, the parameter-update line logged the number: 1 line before the follow-up commit, 0 after.

PID 271 has inbound SMS disabled. It was switched on only for the test and switched back right after. The script is `emlogger-phi-review/e2e/twilio-inbound.sh` in the REDCap EM workspace.
