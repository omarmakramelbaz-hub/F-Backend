'use strict';
const assert=require('node:assert/strict');

module.exports=async function verifyDashboardSoundControl(page){
  const hud=page.locator('#go-hud');
  if(await hud.isVisible()){
    await page.locator('#go-hud.ready').waitFor({state:'visible'});
    await hud.getByRole('button',{name:'GO — الدخول على الرئيسية',exact:true}).click();
    await hud.waitFor({state:'hidden'});
  }
  const button=page.locator('#toggle-sound');
  await button.waitFor({state:'visible'});
  await page.waitForFunction(()=>typeof window.DashboardOrderAlerts?.isReady==='function');
  // Use the captured dashboard's real control and fulfilled local audio promise.
  // Its current UI keeps a persistent button instead of a login modal.
  if(await page.evaluate(()=>window.DashboardOrderAlerts.isReady()))await button.click();
  await button.click();
  await page.waitForFunction(()=>window.DashboardOrderAlerts.isReady()&&localStorage.getItem('soundEnabled')==='true');
  assert.equal(await button.getAttribute('aria-pressed'),'true');
  assert.equal(await page.locator('#sound-icon').evaluate(icon=>icon.classList.contains('fa-volume-up')),true);
  await button.click();
  await page.waitForFunction(()=>{
    const state=window.DashboardOrderAlerts.state();
    return !state.enabled&&!state.playing&&localStorage.getItem('soundEnabled')==='false';
  });
  assert.equal(await button.getAttribute('aria-pressed'),'false');
  assert.equal(await button.getAttribute('data-sound-state'),'muted');
  await button.click();
  await page.waitForFunction(()=>window.DashboardOrderAlerts.isReady()&&localStorage.getItem('soundEnabled')==='true');
  process.stdout.write('PASS current dashboard sound button unlocks local playback, mutes it and restores the saved sound preference with external requests blocked\n');
};
