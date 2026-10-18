# Expense category submit feedback

Base: activated branch-local category release `754bf038af50dcade0d43f2f6b537a5b155de4d0`.

The shared dashboard footer replaces every form submit button with a spinner, including forms whose page script already prevented native submission. A rejected expense category request could therefore leave the button displaying “قيد التنفيذ” despite the completed request. The page also displayed Laravel's generic validation message instead of its field errors.

The three expense-page form handlers now stop subsequent submit handlers. Category save labels are owned by the page's busy/edit state and restored after success or failure. Server field errors are shown as text, with existing session and fallback behavior retained. Category refreshes must not keep a completed save locked while a separate listing read is pending.

The release changes only `public/dashboard/js/branch-expenses.js` on the server. It leaves branch-local names, validation, duplicate detection, UUID behavior, expense records, stock and cash unchanged. Its pinned installer uses scoped hunks, a private backup, file metadata preservation, idempotent reruns and safe inverse rollback, without application bootstrap or migrations.

Regression coverage loads the actual Blade page, actual expense script, actual jQuery and the footer's real submit binding, covering a valid Mahalla rename, field errors, correction/retry, busy labels and the other page forms.
