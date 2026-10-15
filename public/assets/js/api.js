let csrf = '';
export const setCsrf = t => { csrf = t; };

export class ApiError extends Error {
  constructor(status, body) { super(body?.error?.message || 'Fehler'); this.status = status; this.code = body?.error?.code; this.extra = body?.error || {}; }
}

export async function api(method, path, body) {
  const res = await fetch(path, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let data = null;
  try { data = await res.json(); } catch { /* leer */ }
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}
export const get = p => api('GET', p);
export const post = (p, b = {}) => api('POST', p, b);

export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
}
