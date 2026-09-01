# 7.8.1 — Consent text was still being truncated

## The bug

7.5.0 widened `placeholder` and `help_text` from `varchar` to `TEXT`, because
consent wording was being silently cut at a column boundary. That fixed the
database and left the HTML alone: the two admin inputs still carried
`maxlength="200"` and `maxlength="300"`, so the **browser** truncated the
value before it was ever submitted. Same failure, one layer up, and just as
silent.

Found on aqmuftirealty.com on 1 Sep 2026 by exporting a form over the new
7.8.0 REST route and noticing the marketing-consent sentence stored as
exactly 200 characters, ending mid-word at "This consent isn't ne".

Checkbox fields render `placeholder` as the text beside the tick box, so on
that site the sentence people were being asked to agree to was cut in half.

## The fix

Both inputs now allow 2000 characters. Every other `maxlength` in the builder
was checked and matches its real column width — `form_name` 120,
`email_subject` 200, `success_message` 255, option and field labels 120 — so
these two were the only ones wrong.

The REST import capped the same two fields at 20000. Neither number was the
real one, so both are now 2000: one limit, in one place.

## If you are affected

Any consent text longer than 200 characters entered through the builder
before this release is stored truncated. Re-enter it, or push the form
through `aqm/v1` — the REST route never had the browser cap and stores the
full value.

Worth checking on every site running this plugin, not just the one where it
was found.
