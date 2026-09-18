import axios from 'axios'

/**
 * Centralized API client skeleton (FND-004).
 *
 * - Base URL points at the public API prefix `/api/v1` (PROJECT_RULES.md §6),
 *   overridable via VITE_API_BASE_URL.
 * - `withCredentials` is enabled for cookie/session based auth added later.
 * - A response interceptor is scaffolded to normalize the standard API
 *   error envelope. Auth token/refresh handling is added in Module 01.
 */
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    // Placeholder: normalize/handle the standard error envelope here later.
    return Promise.reject(error)
  },
)

export default api
