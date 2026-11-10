# GO opening commands

The opening supports a finite Arabic command vocabulary over the cards already visible to the current account. There is no conversational AI provider and no business-data write endpoint. It does not infer sales, dates, branches, or values absent from the current summary.

| Request | Example | Behavior |
| --- | --- | --- |
| Open a visible section | `افتح العملاء` / `افتح طلبات التطبيق` | Clicks that card's existing same-origin link, preserving dashboard navigation and Owner drawer handling. |
| Read the current summary | `عدد العملاء كام؟` / `رصيد أدراج الفروع كام؟` | Reads the live rendered value, its displayed scope, and a stale warning when applicable. Loading/unavailable money remains unavailable. |
| Enter home | `افتح الرئيسية` | Activates the existing home-entry control. |
| Stop | `وقف الصوت` / `اسكت` | Ends the current microphone session and response speech. |
| Help | `تقدر تعمل إيه؟` / `مساعدة` | Lists supported examples. |

Aliases cover the visible app orders, takeaway, delivery, dining, employees/payroll, customers, expenses, pending expenses, drawer balance/shift closing, and inventory cards. An unknown section, ambiguous match, additional branch/date qualifier, compound request, external URL, or mutating instruction is rejected.

Load `dashboard/js/hud-commands.js` and `dashboard/css/hud-commands.css`. After initial HUD cards render:

```js
var commands = window.GoHudCommands.mount(root, {
    duckGreeting: stopSpeech,
    setSpeaking: function (value) { if (robot) robot.setSpeaking(value); }
});
// In the opening's dispose handler:
if (commands) commands.destroy();
```

The panel is optional and starts closed. Interaction is voice-only: there is no text input or command form. Microphone recognition starts only on an explicit button press, uses one final `ar-EG` phrase, stops after at most 15 seconds, and never restarts itself. Hidden pages, a hidden/removed opening, account changes, and teardown stop speech and recording. The module never calls `getUserMedia` or stores recordings.

The module also tracks the exact card used for the last metric response. If an authorization/scope refresh removes or hides that card, or marks its value unavailable/loading, it clears the private response and cancels both queued speech and delayed voice readiness. An initially empty/loading opening is not treated as an authorization failure.

Recognition availability depends on the browser and its speech service; the browser may send audio to its recognition service. A notice appears before microphone use. Permission refusal and offline/service errors explain how to enable the microphone or retry. Unsupported browsers disable the microphone button and explain that voice support is required. Responses use an installed Arabic browser voice when one exists; no English voice silently substitutes for Arabic. A status message explains when an Arabic voice must be installed. These responses are separate from the existing approved English login greeting and do not replace its recording.

Primary references: [Chrome Web Speech introduction](https://developer.chrome.com/blog/voice-driven-web-apps-introduction-to-the-web-speech-api/) and [Web Speech API specification](https://wicg.github.io/speech-api/). Browser support and recognition-service behavior require deployment-browser verification; automated tests explicitly stub speech services and validate the command/UI lifecycle rather than claiming acoustic accuracy.

Run browser validation with Playwright available:

```sh
DESKTOP_TEST_BROWSER_MODULE=/path/to/playwright CHROMIUM_PATH=/usr/bin/chromium node tests/browser/hud-commands.cjs
```
