export function getCsrfToken() {
  return document.cookie.split(';').reduce((tokens, cookie) => {
    const [key, value] = cookie.trim().split('=');
    if (key) tokens[key] = value;
    return tokens;
  }, {})['XSRF-TOKEN'] || '';
}

export function getCsrfHeaders() {
  const token = getCsrfToken();
  return token ? { 'X-XSRF-TOKEN': token } : {};
}
