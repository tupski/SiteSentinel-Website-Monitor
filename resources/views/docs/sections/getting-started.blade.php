{{-- Getting Started — first steps, Telegram bot setup, troubleshooting. --}}
<section id="getting-started" data-doc-section data-search="getting started install setup first steps availability security model http 200" class="docs-section">
    <x-docs.partials.anchor id="getting-started" :title="__('Getting started')" />

    <p class="docs-lead">
        {{ __('This guide describes how SiteSentinel is configured and operated in this deployment. Work through the areas below in order for a fresh install.') }}
    </p>

    <h3 class="docs-subheading">{{ __('The two dimensions of a status') }}</h3>
    <p>
        {{ __('Every monitored website is judged on two independent dimensions, and the dashboard surfaces both:') }}
    </p>
    <ul class="docs-list">
        <li><strong>{{ __('Availability') }}</strong> — {{ __('does the site answer, with which HTTP status, how fast, and is the TLS certificate valid?') }}</li>
        <li><strong>{{ __('Security / content health') }}</strong> — {{ __('has the content been defaced, are spam keywords injected, did it start redirecting elsewhere, or did its SEO structure regress?') }}</li>
    </ul>

    <x-ui.alert variant="warning">
        <strong>{{ __('HTTP 200 does not mean healthy.') }}</strong>
        {{ __('A compromised page can return 200 while serving injected spam or a redirect. Availability passing is not a security pass — always check both dimensions.') }}
    </x-ui.alert>

    <h3 class="docs-subheading">{{ __('Suggested first-run order') }}</h3>
    <ol class="docs-list docs-list--ordered">
        <li>{{ __('Add at least one website (Websites → Add website).') }}</li>
        <li>{{ __('Confirm a manual check records a snapshot and a check result.') }}</li>
        <li>{{ __('Configure a notification channel and send a test message.') }}</li>
        <li>{{ __('Publish a status page if you want a public view.') }}</li>
    </ol>
</section>

<section id="telegram-bot-setup" data-doc-section data-search="telegram bot setup botfather token chat id topic thread secret channel" class="docs-section">
    <x-docs.partials.anchor id="telegram-bot-setup" :title="__('Telegram Bot setup')" />

    <p class="docs-lead">
        {{ __('A step-by-step walkthrough of getting alerts into a Telegram chat or topic. The field labels below match the notification channel form exactly.') }}
    </p>

    <h3 class="docs-subheading">{{ __('1. Create the bot with BotFather') }}</h3>
    <ol class="docs-list docs-list--ordered">
        <li>{{ __('Open Telegram and start a chat with @BotFather.') }}</li>
        <li>{{ __('Send /newbot and follow the prompts to choose a name and username.') }}</li>
        <li>{{ __('BotFather returns the bot token — treat it as a secret.') }}</li>
    </ol>

    <h3 class="docs-subheading">{{ __('2. Find the Chat ID') }}</h3>
    <p>{{ __('Send a message to the bot, then read updates to learn the chat id:') }}</p>
    <pre class="docs-code"><code>https://api.telegram.org/bot{{ '<TOKEN>' }}/getUpdates</code></pre>
    <p>
        {{ __('The token is shown as a placeholder only — never paste a real token into shared notes. The bot token is a secret stored encrypted in secret_ref; the Chat ID is not a secret.') }}
    </p>

    <h3 class="docs-subheading">{{ __('3. Start the chat') }}</h3>
    <p>{{ __('Send /start to the bot so it may deliver messages to you.') }}</p>

    <h3 class="docs-subheading">{{ __('4. Fill in the channel fields') }}</h3>
    <table class="docs-table">
        <thead>
            <tr>
                <th>{{ __('Field') }}</th>
                <th>{{ __('What to enter') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>{{ __('Chat ID') }}</strong></td>
                <td>{{ __('The numeric chat id discovered in step 2.') }}</td>
            </tr>
            <tr>
                <td><strong>{{ __('Topic thread ID (optional)') }}</strong></td>
                <td>{{ __('Set this only for forum topics; leave blank for a plain chat.') }}</td>
            </tr>
            <tr>
                <td><strong>{{ __('Secret (SMTP password or bot token)') }}</strong></td>
                <td>{{ __('The bot token from step 1. Stored encrypted in secret_ref.') }}</td>
            </tr>
        </tbody>
    </table>

    <h3 class="docs-subheading">{{ __('5. Enable, test and verify') }}</h3>
    <ol class="docs-list docs-list--ordered">
        <li>{{ __('Enable the channel.') }}</li>
        <li>{{ __('Use the Test button to send a sample message.') }}</li>
        <li>{{ __('Confirm the message arrives, then let alerts flow.') }}</li>
    </ol>
</section>

<section id="troubleshooting" data-doc-section data-search="troubleshooting problems alerts not arriving diagnose" class="docs-section">
    <x-docs.partials.anchor id="troubleshooting" :title="__('Troubleshooting')" />

    <dl class="docs-list">
        <dt class="docs-term">{{ __('No alerts are arriving') }}</dt>
        <dd>{{ __('Check the Delivery log for the reason, confirm the channel is enabled, and send a test message. A tripped circuit breaker suppresses sends until it resets.') }}</dd>

        <dt class="docs-term">{{ __('A check never runs') }}</dt>
        <dd>{{ __('Confirm the website is enabled and its interval has elapsed, and that a queue worker is running.') }}</dd>

        <dt class="docs-term">{{ __('A website is flagged falsely') }}</dt>
        <dd>{{ __('Review the incident signals and consider tuning the website rule settings; consecutive-failure thresholds gate transitions.') }}</dd>
    </dl>
</section>
