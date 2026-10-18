# Customer follow-up template setup

The customer messaging dialog required an approved, exact-body Utility template,
but an empty selector offered no action to prepare one. It also hid pending and
rejected template states, so the operator could not tell what blocked follow-up.

The dialog now shows the template lifecycle, offers an explicit **تجهيز قالب
المتابعة في واتساب** action, and refreshes approval status. The central dashboard
reuses its existing WhatsApp account and credentials to submit the fixed Arabic
`fasakhansta_order_feedback_v1` template. Existing compatible approved or pending
templates are reused. Provider permission failures and conflicting template names
are shown separately. Customer sending remains restricted to approved templates.

Meta error `100/2388299` rejected the initial submission because its final
parameter had only a period after it. New submissions now end with substantive
static words: `فرع {{2}}. شكرًا لاختيارك فسخانستا.` The original exact body and
the earlier period-only body remain compatible if already approved. Existing
remote templates are reused, never overwritten by this correction.

Preparing a template does not enable messaging, change customer permissions, queue
a campaign, or send a customer message. After approval, open **قائمة العملاء →
متابعة العملاء ورسائل واتساب**, refresh the template status, select the template,
enable the desired follow-up settings, and save. Follow-up remains scheduled for
24 hours after a completed and paid order.

The installer stages only the service, CRM JavaScript, controller, routes and
scoped route checker. It merges changes against the reviewed base, keeps unrelated
server edits, and stops before publishing if a conflict occurs. It backs up
changed files and restores them if post-install checks fail. No migrations,
dependency updates, customer imports, consent changes or worker activation are
part of this update.

`--check` runs staging and syntax checks without publishing application changes.
Run the root entry point with the full release commit:

```sh
bash deployment/apply_customer_followup_template_root.sh FULL_COMMIT --check
bash deployment/apply_customer_followup_template_root.sh FULL_COMMIT
```

Template management API reference: [Meta's WhatsApp Business Management API
collection](https://www.postman.com/meta/whatsapp-business-platform/request/3o32lzu/create-template-w-location-header-text-body-text-footer-and-a-website-buttons).
