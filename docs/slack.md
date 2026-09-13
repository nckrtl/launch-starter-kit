# Slack notifications

Commander sends Slack notifications with the `Commander` bot and Slack's
`chat.postMessage` API. The app requests `chat:write`, `chat:write.public`, and
`channels:read`.

Set the bot token in `.env`:

```dotenv
SLACK_BOT_USER_OAUTH_TOKEN=xoxb-...
```

Notifications implement `SendsSlackNotifications` and return a `SlackMessage`:

```php
use App\Contracts\SendsSlackNotifications;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

final class DeploymentCompleted extends Notification implements SendsSlackNotifications
{
    public function via(object $notifiable): array
    {
        return [SlackChannel::class];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return new SlackMessage('Deployment completed.');
    }
}
```

Route a notification to the channel ID stored on a Commander project:

```php
Notification::route('slack', $project->slack_channel_id)
    ->notify(new DeploymentCompleted());
```

The bot can post to any public channel by channel ID. Invite `Commander` to a
private channel before routing notifications there; Slack does not allow an app
to bypass private-channel membership.
