# AQM Contact Form 7.4.0

**Database version 9 → 10.** Three new columns are added automatically on the
next admin page load: `form_fields.help_text`, `form_fields.default_value`,
`forms.form_intro`. Nothing existing is touched or lost.

---

## A new field type: Checkboxes (pick several)

Every other type takes one answer. This one takes as many as apply — a row of
tick boxes built from the field's options, **plus a free-text box underneath**
for anything not on the list, so the list never has to be exhaustive. Allergies,
accessibility needs, which sessions you are attending, which days you can help:
all the questions where "pick one" was the wrong question.

The value is stored as one comma-joined string, so the notification email, the
auto-reply, the CSV export and the Submissions screen all keep working with no
knowledge of the new type.

**Hardened, because tick boxes are the easiest field to tamper with.** Submitted
values are resolved against the real option list; a typed value that happens to
match one takes the list's own capitalisation, so "peanut" and "Peanut" never
both appear in a report. Duplicates collapse, entries are capped at 200
characters and 50 per field, and a forged non-array submission is handled rather
than fataling.

## Two option lists on every new form

New forms now arrive with a **Dietary requirements** combobox (Halal, Kosher,
Vegetarian, Vegan, Gluten free, Dairy free, No pork, No beef, None) and a **Food
allergies** checkbox group (Peanut, Tree nut, Shellfish, Fish, Egg, Dairy, Soy,
Sesame, Wheat or gluten, None).

**Both are seeded hidden.** Most forms are not registrations — a quote request
or a job enquiry should not open with two catering questions. Hidden means the
lists are always *there*, already filled in, one click on the OFF badge away
from being used, and invisible to visitors until someone asks for them.

## What a visitor now sees

**Help text on every field.** A grey line under the box that says *why* you are
asking and *what* to put. Until now the only place to explain a field was the
label, which is why "Allergies or anything else we should know" is a sentence
pretending to be a label. Set on Full Name, Email, Telephone and the combobox
of every newly created form, so the feature shows itself rather than hiding in
the documentation.

**An introduction above the form.** Form Settings → Introduction. This is where
the date, the place, the price and the deadline belong — a form pasted onto a
bare page previously opened with no context at all. Blank lines make paragraphs.

**Number limits in words.** A field with a minimum and maximum now reads
"Between 1 and 20" *before* the visitor gets it wrong, instead of the browser's
flat "Value must be less than or equal to 20" afterwards. If your own help text
already carries the numbers, the generated line is suppressed rather than
printed twice in different words.

**A character counter on long text boxes.** `maxlength` was silent: a person
writing a long note simply stopped being able to type. The count appears in the
last 300 characters and turns red at zero. Pure progressive enhancement — the
limit was always enforced without it.

**"What you sent" after a successful send.** The thank-you message is followed
by a list of what was actually submitted, blanks omitted. On a registration
this is the difference between confidence and a follow-up email asking whether
it went through.

**Default values on text fields.** Real text, already in the box, submitted if
untouched. Applies to a *fresh* form only — coming back from a validation error
never re-injects a default over something the visitor deliberately cleared.

**Screen readers now hear the guidance.** `aria-describedby` pointed at the
error message alone, so help text would have been announced to nobody. It now
carries both.

## What the builder now does

**The blue field-type list is a control, not decoration.** Click any type and
it sets the Field Type above. The current type is outlined.

**Placeholder says what it is.** It is labelled and described as grey example
text that vanishes on typing and is *never submitted* — explicitly not a default
value, which now exists separately. On a **checkbox** the same box does a
different job, so it relabels itself to "Text beside the tick box".

**Default value is hidden where it has no meaning** — checkbox, dropdown, and
Number (which keeps its own Default in the number panel).

**Add several options at once.** The Add Options box is now a text area.
One per line, and `·` `|` `;` also separate. **Commas do not** — an ordinary
label such as "Yes, with a guest" stays whole. Bullets and leading dashes are
stripped, duplicates are skipped, and the notice says how many were added so a
wrong split is visible immediately. Editing a single option still edits exactly
one.

**The combobox panel says what a combobox is not.** The field opens *empty*;
nothing on the list is filled in or submitted unless the visitor picks it or
types their own. Dropdowns now get the opposite note.

**The field list shows what visitors read.** Each row carries its help text
under the label, so the whole form's wording can be reviewed without opening
nine field editors one at a time.

## Fixed

**Duplicating a form silently reset every number field.** 7.3.0 copied only the
first eight columns, so whole-numbers-only, minimum, maximum and default all
reverted to their defaults on the copy. The whole field is now copied.

---

### Upgrading

Nothing to do beyond the usual update. Existing forms keep working exactly as
before — every new column defaults to empty, and an empty help text or intro
renders nothing at all.
