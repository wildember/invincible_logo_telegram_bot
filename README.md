This script pulls last messages using Telegram API getUpdates endpoint,
then filters out the already answered messages and answers the commands with an image or a sticker generated from user input.

Implied to be run by cron job.

Uses [Nevduplenysh](https://online-fonts.com/fonts/nevduplenysh) font for cyrillic texts and [Shadow of Xizor](https://online-fonts.com/fonts/shadow-xizor) for others.

As bot's `/help` command states:

`/generate Hello world` will return an image with the text "Hello world"

`/generatesticker Hello world` will return a sticker with the text "Hello world"

`/generate Hello\world` will return an image (or a sticker) with multi-line text

`/help` will return this message

Accepted characters: latin, cyrillic, numbers, spaces, punctuation and backslash (`\`)
