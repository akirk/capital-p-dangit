# Capital P Dangit

Capital P Dangit is a WordPress/Gutenberg prototype that uses the same PHP-only RTC bot approach as Auto Linker, but with a narrower behavior: when paragraph text reaches a word delimiter after `wordpress`, `Wordpress`, or another final-word casing of the same name, the bot replaces that final word with `WordPress` through Gutenberg collaboration.

For example:

```text
I build with wordpress.
```

becomes:

```text
I build with WordPress.
```

## How It Works

The plugin attaches to Gutenberg's existing `/wp-sync/v1/updates` REST endpoint. It does not enqueue editor JavaScript and it does not register a separate mutation endpoint.

`Settings -> Capital P Dangit` lets an administrator choose the WordPress user that should act as the bot. The configured user must be able to edit the current post. The plugin derives a stable Yjs client ID from that user ID, temporarily switches to the bot user, and submits bot-authored updates back through Gutenberg's normal sync endpoint.

Incoming RTC updates are decoded with the copied Yjs/Gutenberg helpers from Auto Linker. The plugin tracks paragraph block state in `_cpdangit_room_state` and reacts to touched paragraph text once it ends in a word delimiter, such as a space or punctuation. It runs WordPress core's `capital_P_dangit()` function against the paragraph text and maps any final-word change back into a Yjs replacement. Because core does not change bare lowercase `wordpress`, the plugin also has a narrow final-word fallback for that requested typing case.

The bot clock is stored per post in `_cpdangit_bot_clock`.

## Scope

This intentionally handles a narrow path:

- Top-level Gutenberg paragraph text updates.
- Plain paragraph text.
- The completed paragraph's final word.
- Replacements produced by WordPress core's `capital_P_dangit()` plus a final-word lowercase `wordpress` fallback.

It does not provide a general Yjs runtime, does not handle arbitrary block mutations, and does not currently fix earlier occurrences of `wordpress` inside the same paragraph.

## Composer

The plugin uses `maxschmeling/y-php` through Composer for lib0/Yjs primitives:

```sh
composer install
```

Deployable builds should include `vendor/`, matching the Auto Linker/Shouter dist-branch pattern.
