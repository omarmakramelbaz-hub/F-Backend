# Encrypted legacy customer transfer

The payload is the original pinned customer import ZIP, protected with a fresh
random AES-256-GCM key and nonce. Only authenticated ciphertext is committed.
The key is kept outside the repository and supplied separately to the server on
standard input. It must never be added to a commit, issue, log, or command argument.

Format: ASCII `FASAKHANSTA-CUSTOMERS-AES256GCM-V1\n` magic (including newline),
12-byte nonce, 16-byte GCM tag, then ciphertext. The magic is authenticated as AAD.
Both encrypted bytes and decrypted ZIP have pinned SHA-256 and exact sizes.

The guarded root entry is pinned to srv1836524. It stages the completed decoder
and ciphertext privately, then runs the decoder as fasakha. The decoder writes
the decrypted ZIP with 0600 permissions outside public_html, using no-overwrite atomic publication.
It accepts an existing exact ZIP and refuses different content or symlink paths.
After decoding, it invokes the existing guarded V3 importer, which stages privately
and bootstraps Laravel only as fasakha. Existing WhatsApp capture and lookup remain
installed. No clear customer data or decryption key is published to GitHub.

Validation: PHP/bash syntax checks and 24 independent local checks passed,
including exact decryption, repeat without rewriting, authentication failure,
corrupted/truncated payload, symlink and webroot refusal, preservation of a
different existing file, private permissions, cleanup, and concurrent publication.
