# GO ERP program center and local operation

The dashboard's **برنامج الكمبيوتر** section is the entry point for a new Windows installation, first preparation, synchronization status, and automatic update status. The first-install download must use the current compatible GO ERP publication; it must never fall back to the retained POS installer.

After successful preparation, the main dashboard opens the retained local runtime with or without Internet connectivity. Sales, branch expenses, stock and shifts retain their existing local journal and acknowledgment rules. Pending command uploads and incoming-change checks are admitted on 30-second slots when connectivity permits. Incoming preparation runs independently, so a slow download does not delay the next upload slot. One incoming task runs at a time; the short final handoff excludes an in-flight upload or acknowledgement. Missed or busy slots never create overlapping copies. A lost acknowledgment retains the same command UUID. Business conflicts remain visible for review rather than being discarded or automatically changed.

Incoming server data uses scoped row deltas. A private consistent capture of the current database produces a multiset index; unchanged row values never cross the network. A table schema change replaces only that table in a fresh candidate database. Matching existing pictures and private attachments are verified and reused, with a safe copy fallback when a hardlink is unavailable. Newly downloaded content is cached so a deferred activation does not redownload it.

Capture, network transfer, reconstruction, full verification and replay-history preparation run before any cashier write fence. The final handoff requires a pure idle check from the exact business page and an unchanged local command sequence. New sales during preparation cause deferral, preserving their original database and UUIDs. Unknown pages, unsaved invoices, stock/expense drafts, open shift forms, printing and unresolved commands prevent activation. A persistent conflict notice opens the existing review dialog; it never retries a conflicting business operation automatically.

Executable updates are a separate automatic operation. The updater checks the compatible publication every minute, stages verified bytes, and performs the existing silent replacement and automatic relaunch after its idle and journal guards pass. A user does not need to download or run another installer for routine updates. It does not install while a live social composition window remains open.

Meta messages and social comments use a separate live server surface. Opening it must not switch the main dashboard to central mode, change its active local generation, or join the 30-second operation queue. Its navigation and request policy is limited to messaging/comment functions. It cannot submit ordinary ERP writes from that surface. Internet failures show a fixed, understandable notice and never automatically resend an outbound message or comment.

The existing recovery fence for an uncertain historical direct server write still applies. It cannot be bypassed to satisfy automatic startup or update behavior.

Acceptance checks:

- First preparation activates the local dashboard immediately, and a subsequent offline launch uses the same verified local account and generation.
- The sidebar section is visible in the modern desktop configuration, independently of the retained POS setting. A normal browser offers only the verified first-install download and instructions.
- Local sales, expenses, inventory and shifts retain commands during disconnection and reconcile at scheduled boundaries after reconnection without creating new UUIDs for uncertain acknowledgments.
- Meta messages and comments open from the pinned server while the main dashboard remains local. Initial and later connectivity failures display an Internet-required notice, preserving any unsent composition.
- Live messaging requests and responses do not wait for the scheduled ERP cycle. They do not acquire a central-mode latch or send ordinary ERP business writes.
- While an incoming update is being prepared, create and acknowledge a new sale. The old database and sale must survive; activation must defer when its captured sequence is older.
- Reconcile an unchanged account twice. No unchanged row values or picture bodies should be downloaded, and no replacement generation should be activated.
- Change one row and one picture on the server. Only the changed rows and missing picture content should cross the network; existing matching pictures must be reused.
- During the final handoff, all input and native writes are fenced in one renderer turn. The old document is retired before releasing that fence. A failed retirement keeps the fence and requests a restart.
- Server data refresh and software updates defer safely during editing, printing, pending work or a conflicting operation and resume automatically when their guards permit.

The delta endpoint admits at most two simultaneous account scans using zero-wait connection-owned advisory locks. It acquires a slot before decoding the index, caps that request at 512MiB, and releases its slot on success or failure. Excess devices receive a fixed retry response without entering an account scan. This bounds concurrent delta work; it is not evidence of an unlimited account size or a completed 20-device production load test. See `desktop-delta-protocol.md` for explicit request, response and row limits.
