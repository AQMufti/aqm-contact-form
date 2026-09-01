# 7.8.0 — Forms as files

Adds a REST API so a form can be defined in a JSON file instead of built by
clicking: `aqm/v1`, two routes, `manage_options` on both.

## Why

The builder is a good editor for one form. It is a poor one for several forms
whose labels have to match each other exactly, because the CSV export keys its
columns by `field_key`. One drifted label — `Phone Number` on one form,
`Telephone` on another — becomes a second column that never merges back, and
you find out when you try to sort a merged export.

A form in a JSON file is diffable, reviewable, version controlled, and
identical on every site it is pushed to.

## Routes

| method | route | does |
|---|---|---|
| GET | `/wp-json/aqm/v1/forms` | list forms with field and submission counts |
| GET | `/wp-json/aqm/v1/forms/{id}` | export one form as import-shaped JSON |
| POST | `/wp-json/aqm/v1/forms` | create or update a form from that JSON |

Export and import use the same shape, so export → edit → import is a round
trip that loses nothing.

## What protects you

- **`manage_options` on every route.** Nothing here is public.
- **`dry_run`** returns the full plan and writes nothing.
- **Nothing is deleted unless you ask.** A field on the site but absent from
  the JSON is reported and left alone. `prune: true` removes it — except on a
  form that already holds submissions, which additionally needs
  `force: true`, because deleting a field orphans that answer in every stored
  entry.
- **Over-long values are rejected, not truncated.** 7.5.0 exists because
  consent text was being silently cut at a `varchar` boundary. The import
  names the field, the length and the limit instead.
- **The write runs in a transaction**, so a failure halfway does not leave
  half a form. (InnoDB; on MyISAM the statements are no-ops and the import is
  not atomic.)
- **The rename trap is named.** With no explicit `key`, a field's key is
  derived from its label — so editing a label creates a *new* field and
  orphans the old one rather than renaming it. When the import sees that
  shape it says so, names the field that would be orphaned, and tells you to
  supply the existing key. It does not guess, because a genuine new field
  looks identical from the server's side.

## Validation

Rejected with a per-problem list and nothing written: missing `form_name` or
`fields`; an unknown field type; options on a field type that cannot hold
them; a dropdown, combobox or checkbox group with no options; a duplicate
`key` or duplicate label within a form; an invalid notification address; any
value longer than its column; a number field whose minimum exceeds its
maximum.

## Identifying a form

By `id` if given, otherwise by exact `form_name`, otherwise created. Form IDs
are not predictable — a deleted form leaves its number behind — so name
matching is the reliable route, and every response reports the id it used,
which is the number `[aqm_form id="N"]` needs.

## Not changed

Nothing in the builder, the front end, submissions, or mail. This release
only adds a second way in.
