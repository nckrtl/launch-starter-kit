# Commander Slack app

This directory contains the secret-free Slack CLI manifest for the Commander
bot. Run `npm install` and use the authenticated Slack CLI to validate or update
the app.

```sh
slack manifest validate --team T0BSF3114R2
slack manifest diff --app A0BUAHZHZDK
```

The bot OAuth token belongs only in Commander's root `.env`; it must not be
added here or committed.
