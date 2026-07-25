import { getCsrfHeaders } from "@/lib/csrf";

export const UNAUTHORIZED_EVENT = "panel-page-flip:unauthorized";

export class ApiError extends Error {
  constructor(message, { status = 0, data = null, response = null } = {}) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.data = data;
    this.response = response;
  }
}

async function parseResponse(response, responseType) {
  if (responseType === "response") return response;
  if (responseType === "blob") return response.blob();
  if (responseType === "text") return response.text();
  if (response.status === 204) return null;

  const contentType = response.headers.get("content-type") || "";
  if (responseType === "json" || contentType.includes("application/json")) {
    const text = await response.text();
    return text ? JSON.parse(text) : null;
  }

  return response.text();
}

function prepareBody(body, headers) {
  if (body === undefined || body === null || body instanceof FormData || body instanceof Blob) {
    return body;
  }

  if (typeof body === "string" || body instanceof URLSearchParams) return body;

  if (!headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  return JSON.stringify(body);
}

export async function request(path, options = {}) {
  const {
    responseType = "auto",
    notifyUnauthorized = true,
    headers: initialHeaders,
    ...fetchOptions
  } = options;
  const method = (fetchOptions.method || "GET").toUpperCase();
  const headers = new Headers(initialHeaders || {});
  const body = prepareBody(fetchOptions.body, headers);

  if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    Object.entries(getCsrfHeaders()).forEach(([key, value]) => headers.set(key, value));
  }

  let response;
  try {
    response = await fetch(path, {
      ...fetchOptions,
      method,
      body,
      credentials: "include",
      headers,
    });
  } catch (error) {
    if (error.name === "AbortError") throw error;
    throw new ApiError("Unable to reach the server", { data: error });
  }

  let data;
  try {
    data = await parseResponse(response, responseType);
  } catch (error) {
    throw new ApiError("The server returned an invalid response", {
      status: response.status,
      data: error,
      response,
    });
  }

  if (!response.ok) {
    if (response.status === 401 && notifyUnauthorized && typeof window !== "undefined") {
      window.dispatchEvent(new CustomEvent(UNAUTHORIZED_EVENT));
    }

    const message = typeof data === "object" && data !== null
      ? data.message || data.error
      : data;
    throw new ApiError(message || `Request failed (${response.status})`, {
      status: response.status,
      data,
      response,
    });
  }

  return data;
}

export const api = {
  request,
  get: (path, options) => request(path, options),
  post: (path, body, options = {}) => request(path, { ...options, method: "POST", body }),
  put: (path, body, options = {}) => request(path, { ...options, method: "PUT", body }),
  patch: (path, body, options = {}) => request(path, { ...options, method: "PATCH", body }),
  delete: (path, options = {}) => request(path, { ...options, method: "DELETE" }),
  blob: (path, options = {}) => request(path, { ...options, responseType: "blob" }),
};
