'use strict';

// Deadlines are for native/read-only preparation work. Do not use this helper
// to release a database write fence while its underlying write is still active.
function withDeadline(work, timeoutMs, message) {
  return new Promise((resolve, reject) => {
    let settled = false;
    const finish = (result, value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      result(value);
    };
    const timer = setTimeout(() => {
      const error = Error(message);
      error.code = 'NATIVE_OPERATION_TIMEOUT';
      finish(reject, error);
    }, timeoutMs);
    Promise.resolve().then(work).then(value => finish(resolve, value), error => finish(reject, error));
  });
}

function printOnce(contents, options, { timeoutMs = 45000 } = {}) {
  return withDeadline(() => new Promise((resolve, reject) => {
    contents.print(options, (ok, error) => ok === true ? resolve() : reject(Error('فشلت الطباعة: ' + (error || 'لم تؤكد الطابعة الاستلام.'))));
  }), timeoutMs, 'لم تصل نتيجة الطباعة من ويندوز. راجع الطابعة قبل إعادة الطباعة؛ لن تُعاد تلقائيًا.');
}

module.exports = { withDeadline, printOnce };
