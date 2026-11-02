'use strict';
// The dashboard's home page while the internet is down. The page itself and its data come from the device's copy
// (kept fresh by the sync while online); only the live parts answer differently: the GO opening's counters are never
// presented as live (the opening shows "آخر أرقام متاحة"), and the overview's saved data comes without them.
const { NEEDS_INTERNET } = require('./pages.cjs');

const OVERVIEW = '/admin/dashboard/overview';

/** The opening's counts as last read online (HomeHologramMetrics::data), the newest of its own reads and the overview's. */
function savedCounters(cache, scope, origin) {
  const read = (copy) => {
    try {
      const value = JSON.parse(copy.body.toString('utf8'));
      return value && value.hologram && Array.isArray(value.hologram.cards) ? { counters: value.hologram, at: Date.parse(copy.storedAt) || 0 } : null;
    } catch {
      return null;
    }
  };
  const own = cache.get(scope, 'data', origin + OVERVIEW + '?section=hologram');
  const overview = cache.get(scope, 'data', origin + OVERVIEW, { pathOnly: true });
  const found = [own && read(own), overview && read(overview)].filter(Boolean).sort((a, b) => b.at - a.at)[0];
  return found ? found.counters : null;
}

class HomeOffline {
  constructor({ cache, origin }) {
    this.cache = cache;
    this.origin = origin;
  }

  match(method, pathname) {
    // keepOnline: the dashboard's own answers are kept online (they are the page's numbers offline).
    if (method === 'GET' && pathname === OVERVIEW) return { keepOnline: true, handle: (request) => this.overview(request) };
    return null;
  }

  async answer(route, request) {
    return route.handle(request);
  }

  overview({ user, url }) {
    if (url.searchParams.get('section') === 'hologram') return { status: 503, json: { success: false, message: NEEDS_INTERNET } };
    const copy = this.cache.get('u:' + user.id, 'data', url.href);
    if (!copy) return null;
    let data;
    try { data = JSON.parse(copy.body.toString('utf8')); } catch { return null; }
    if (!data || typeof data !== 'object') return null;
    delete data.hologram;
    return { status: 200, json: data };
  }

  counters(user) {
    return savedCounters(this.cache, 'u:' + user.id, this.origin);
  }
}

module.exports = { HomeOffline, savedCounters };
