# Capacity seed CI policy retention

The installed historical-baseline-v2 source watcher deliberately injects two
pinned CI policy files into each candidate. Its old workflow replaces the
capacity seed's independent validator with the 75c3 historical validator. The
actual preview .23 candidate therefore correctly stopped at CANDIDATE_PARENT.

For the exact reviewed seed 7523ff6888a6e3d0a6f5b7712c553909f12c043b only,
the updated source producer retains the seed's existing raw CI and validator
bytes. Other seeds retain the original pinned historical policy. The producer
and consumer use a matching capacity policy identifier so the next timer tick
retires the blocked .23 candidate and advances its preserved version counter.
Every original source inventory, raw Git byte, dependency, authority, CI success
and immutable installer publication check remains active.

Payloads live separately under deployment/capacity-watcher. Only its source
producer and watcher differ from the exact deployed policy-a0339ec2 runtime.
The publisher, historical workflow companion and historical verifier companion
are unchanged. Application, desktop, dependencies and the installed seed stay
unchanged; this policy package is not a desktop installer source.

The root helper uses the existing tested immutable runtime/unit exchange and
rollback mechanism. It accepts only the exact previous runtime, the exact
reviewed capacity seed state, and the exact five new payload blobs. It does not
read credentials or rewrite private configuration, counters, failed candidates,
dependency guards or publication history. The next ordinary timer tick performs
the version advance. A private before-unit and switch audit is retained.

Validation: 34 real filesystem ownership, lock, unit exchange, interrupted reload,
concurrent change and rollback tests, including retained blocked .23 state; real
Git candidate generation retains its gate, parent, 2 GiB native source and retry
identity; an actual full inventory candidate from seed 7523 passed the unchanged
independent capacity validator against the local captured application cohort.
