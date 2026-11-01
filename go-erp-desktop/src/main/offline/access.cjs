'use strict';
// A branch on a dashboard page the device answers: the account keeps its dashboard permissions, on the branches this
// device was set up for. A branch the device was not set up for has nothing on it (menu, orders, shift), so the page
// says so plainly instead of "no permission" (the pages show their own "no permission" text for status 403).
function branchProblem(store, user, branch) {
  const device = store.branches();
  if (!device.some((item) => item.value === branch)) {
    const names = device.map((item) => item.name || item.value).join('، ');
    return { status: 422, message: `الفرع ده مش متجهز على الجهاز ده، فمش متاح وقت انقطاع النت. الجهاز متجهز لـ: ${names}. `
      + 'عشان تشتغل على فروع تانية من غير نت من الجهاز ده، أعد تجهيزه بحساب الأونر واختار الفروع (والنت شغال): صفحة «برنامج الكمبيوتر» في الداشبورد '
      + '← «إعادة تجهيز الجهاز بحساب آخر».' };
  }
  if (!user.branches.includes(branch)) return { status: 403, message: 'الفرع غير متاح لهذا الحساب.' };
  return null;
}

module.exports = { branchProblem };
