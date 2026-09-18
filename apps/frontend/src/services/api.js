import axios from 'axios'

/**
 * Centralized API client (AUTH-015).
 *
 * - Base URL points at the public API prefix `/api/v1` (PROJECT_RULES.md §6).
 * - `withCredentials: true` is enabled for first-party cookie/session authentication.
 * - Standard JSON accept/content-type headers.
 * - Response interceptor normalizes the error envelope and handles global 401 session expiration.
 */
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

// Global callback for handling unauthorized session termination
let onUnauthorizedCallback = null

export function setUnauthorizedHandler(callback) {
  onUnauthorizedCallback = callback
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const data = error.response?.data
    const url = error.config?.url || ''

    // Normalize error envelope
    const normalizedError = {
      status: status || 500,
      code: data?.error?.code || (status === 401 ? 'UNAUTHENTICATED' : 'UNKNOWN_ERROR'),
      message: data?.message || error.message || 'An unexpected error occurred',
      fields: data?.error?.fields || {},
      raw: error,
    }

    // Handle 401 on protected requests (ignore auth endpoints: /auth/login, /auth/mfa/verify, and /auth/me during initial bootstrap)
    if (status === 401 && !url.includes('/auth/login') && !url.includes('/auth/mfa/verify')) {
      if (typeof onUnauthorizedCallback === 'function') {
        onUnauthorizedCallback(normalizedError)
      }
    }

    return Promise.reject(normalizedError)
  },
)

export default api
