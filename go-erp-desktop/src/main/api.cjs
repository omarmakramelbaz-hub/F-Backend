'use strict';
// HTTPS client for the dashboard's desktop endpoints. Errors carry the server's Arabic message.

class ApiError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

class Api {
  constructor({ baseUrl, fetchImpl = globalThis.fetch, timeoutMs = 25000 }) {
    this.baseUrl = String(baseUrl).replace(/\/+$/, '');
    this.fetch = fetchImpl;
    this.timeoutMs = timeoutMs;
  }

  async request(path, body, headers = {}) {
    let response;
    try {
      response = await this.fetch(this.baseUrl + path, {
        method: 'POST', body: JSON.stringify(body),
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...headers },
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (error) {
      throw new ApiError(0, 'لا يوجد اتصال بالسرفر الآن. البرنامج يعمل على بيانات الجهاز.');
    }
    let payload = null;
    try { payload = await response.json(); } catch { payload = null; }
    if (!response.ok) {
      const errors = payload && payload.errors ? Object.values(payload.errors).flat() : [];
      const message = errors[0] || (payload && payload.message) || `تعذر الاتصال بالسرفر (${response.status}).`;
      throw new ApiError(response.status, message);
    }
    if (!payload || typeof payload !== 'object') throw new ApiError(response.status, 'رد غير متوقع من السرفر.');
    return payload;
  }

  enroll(values) {
    return this.request('/api/desktop-sync/v1/enroll', values);
  }

  sync(device, token, body) {
    return this.request('/api/desktop-sync/v1/sync', body, { 'X-GO-ERP-Device': device.id, Authorization: 'Bearer ' + token });
  }
}

module.exports = { Api, ApiError };
