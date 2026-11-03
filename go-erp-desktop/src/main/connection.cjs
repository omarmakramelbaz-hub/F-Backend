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

module.exports = { unreachable, GATEWAY };
