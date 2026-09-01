# 7.8.2 — The From name is now filterable

## The gap

`aqm_mail_from_address()` has had an `aqm_from_address` filter since 7.x. The
From **name** had none: it was `get_bloginfo( 'name' )` — the WordPress site
title — hard-wired into both mail headers.

That is fine until a site's title is not what you want in someone's inbox. A
title that is a domain, a trading name, or a legal entity name reads oddly as
the sender of a personal reply, and there was no way to change one without
changing the other.

## The fix

```php
function aqm_mail_from_name() {
	$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	return apply_filters( 'aqm_from_name', $name );
}
```

Used by both the notification and the auto-reply. **Default behaviour is
unchanged** — with no filter it still returns the site title.

## Three things that are deliberately separate

| | what it answers | how to change it |
|---|---|---|
| From address | where a reply goes | `aqm_from_address` filter |
| From name | who is writing to me | `aqm_from_name` filter |
| `{site_name}` token | which website is this | the site title |

The token was left alone. It appears inside auto-reply bodies where it means
the site, not the sender.

## Example

```php
add_filter( 'aqm_from_address', fn() => 'info@example.com' );
add_filter( 'aqm_from_name',    fn() => 'Jane Smith' );
```

⚠ Setting the From address to the same mailbox the notification is sent *to*
means From and To match on that email. Some filters score that pattern as
spoofing. Worth watching that notifications keep arriving after such a change.
