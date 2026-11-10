'use strict';
// Whether the dashboard is really out of reach. The app works from the device only then: one request that failed or took
// too long, a sync cut short or Windows saying the network is gone are not enough on their own.

// Answers of the site's gateway when the dashboard behind it does not answer.
const GATEWAY = new Set([502, 503, 504]);

/**
 * Asks the dashboard (probe() resolves true when it answers) up to `tries` times, `pause` ms apart, and says whether it
 * never answered. It stops as soon as it answers, or when stop() says the question no longer matters.
 */
async function unreachable(probe, { tries = 2, pause = 5000, wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)), stop = () => false } = {}) {
  for (let attempt = 0; attempt < tries; attempt++) {
    if (attempt) await wait(pause);
    if (stop()) return false;
    let answered = false;
    try { answered = await probe(); } catch { answered = false; }
    if (answered) return false;
  }
  return true;
}

/**
 * Online or offline. The app works from the device only while the dashboard cannot be reached: a failed request, a sync
 * cut short or Windows saying the network is gone each make it ask the dashboard twice, `pause` ms apart (suspect), and
 * only when neither answers does it switch. While offline it asks again every `every` ms and, when the dashboard
 * answers, runs a sync, which switches it back (set(false)).
 *   answers():            resolves true when the dashboard answers
 *   changed(value, why):  the switch happened (what the program does online or offline)
 *   stillOnline(why):     a suspicion the dashboard disproved
 *   runSync():            a sync now (after going offline, and when the dashboard answers again)
 */
class ConnectionWatch {
  constructor({ answers, changed = () => {}, stillOnline = () => {}, runSync = () => {}, pause = 5000, every = 10000,
    wait, timers = { setInterval, clearInterval } }) {
    Object.assign(this, { answers, changed, stillOnline, runSync, pause, every, wait, timers });
    this.offline = false;
    this.suspecting = null;
    this.recovering = false;
    this.recoveryTimer = null;
  }

  /** Switches online or offline; false when it already was. */
  set(value, reason = '') {
    if (this.offline === value) return false;
    this.timers.clearInterval(this.recoveryTimer);
    this.recoveryTimer = value ? this.timers.setInterval(() => this.recover(), this.every) : null;
    this.offline = value;
    this.changed(value, reason);
    return true;
  }

  /** Asks the dashboard twice; switches offline only when it never answers. Calls at the same time share one check. */
  suspect(reason) {
    if (this.offline || this.suspecting) return this.suspecting;
    this.suspecting = (async () => {
      try {
        const options = { pause: this.pause, stop: () => this.offline };
        if (this.wait) options.wait = this.wait;
        if (!(await unreachable(this.answers, options))) {
          if (!this.offline) this.stillOnline(reason);
          return;
        }
        this.set(true, reason);
        this.runSync();
      } finally {
        this.suspecting = null;
      }
    })();
    return this.suspecting;
  }

  /** Offline: as soon as the dashboard answers, a sync brings the app back to it. */
  async recover() {
    if (!this.offline || this.recovering) return;
    this.recovering = true;
    try {
      let answered = false;
      try { answered = await this.answers(); } catch { answered = false; }
      if (answered) this.runSync();
    } finally {
      this.recovering = false;
    }
  }
}

module.exports = { unreachable, ConnectionWatch, GATEWAY };
