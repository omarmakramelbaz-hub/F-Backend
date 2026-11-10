'use strict';
// New versions download in the background from GitHub and install themselves, with nobody downloading or running a
// setup file. A downloaded version installs once the device is quiet and nothing waits to reach the dashboard:
//   - nobody is signed in to the program (it was just opened) and the device has been untouched for a minute;
//   - between 4 and 6 in the morning (Cairo), before the operating day, after 3 untouched minutes;
//   - any time after 30 untouched minutes.
// The program closes, installs silently and opens again by itself. It also installs when the program is closed.

const CHECK_EVERY = 60 * 60 * 1000;
const LOOK_EVERY = 30 * 1000;

/** The hour (0-23) in Cairo at `date`. */
function cairoHour(date) {
  return Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Cairo', hour: '2-digit', hourCycle: 'h23' }).format(date));
}

/** Whether the device is quiet enough to close and install: idle seconds, the Cairo hour, and whether someone is signed in. */
function quietEnough({ signedIn, idle, hour }) {
  return (!signedIn && idle >= 60) || (hour >= 4 && hour < 6 && idle >= 180) || idle >= 1800;
}

/**
 * Starts checking for new versions and installing a downloaded one when the device is quiet.
 *   updater:   electron-updater's autoUpdater
 *   signedIn:  () => whether someone is signed in to the program
 *   pending:   () => operations still waiting to reach the dashboard (throws when unknown)
 *   idle:      () => seconds the device has been untouched (throws when unknown)
 *   stopSync:  () => stops the sync before the program closes
 */
function startUpdates({ updater, signedIn, pending, idle, stopSync, now = () => new Date(), log = console.log,
  timers = { setInterval, setTimeout } }) {
  updater.autoDownload = true;
  updater.autoInstallOnAppQuit = true;
  let ready = null;
  let installing = false;
  updater.on('update-downloaded', (info) => {
    ready = info;
    log(`[goerp] version ${info && info.version} downloaded; it installs once the device is quiet`);
  });
  updater.on('error', () => { /* offline or no release yet: tried again later */ });
  const check = () => { if (!installing) updater.checkForUpdates().catch(() => { /* offline or no release yet */ }); };
  const look = () => {
    if (!ready || installing) return;
    let seconds = 0;
    try { seconds = idle(); } catch { return; }
    if (!quietEnough({ signedIn: signedIn(), idle: seconds, hour: cairoHour(now()) })) return;
    let waiting = 0;
    try { waiting = Number(pending() || 0); } catch { return; }
    if (waiting > 0) return; // operations still on their way to the dashboard: install after they arrive
    installing = true;
    log(`[goerp] installing version ${ready.version} now (idle ${seconds}s)`);
    try { stopSync(); } catch { /* stopping anyway */ }
    timers.setTimeout(() => { try { updater.quitAndInstall(true, true); } catch { installing = false; } }, 1500);
  };
  check();
  timers.setInterval(check, CHECK_EVERY);
  timers.setInterval(look, LOOK_EVERY);
  return { check, look };
}

module.exports = { startUpdates, quietEnough, cairoHour, CHECK_EVERY, LOOK_EVERY };
