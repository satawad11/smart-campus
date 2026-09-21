import api from './api'

/**
 * Student API Service (STD-015).
 *
 * Interacts with backend Student endpoints.
 * Public identifier is strictly the UUID.
 */
export const studentsService = {
  /**
   * List students with server-side pagination, search, and filters.
   * @param {{
   *   page?: number,
   *   per_page?: number,
   *   keyword?: string,
   *   student_code?: string,
   *   academic_status?: string,
   *   education_level?: string,
   *   year_level?: number,
   *   admission_year?: number,
   *   department_uuid?: string,
   *   program_uuid?: string,
   *   classroom_uuid?: string,
   *   sort?: string,
   *   order?: string
   * }} params
   * @returns {Promise<{ data: Array<object>, meta: { pagination: object } }>}
   */
  async list(params = {}) {
    const response = await api.get('/students', { params })
    return {
      data: response.data.data,
      meta: response.data.meta,
    }
  },

  /**
   * Fetch a single student by public UUID.
   * @param {string} uuid
   * @returns {Promise<object>}
   */
  async get(uuid) {
    const response = await api.get(`/students/${uuid}`)
    return response.data.data
  },

  /**
   * Create a new student profile and associated person record.
   * @param {{
   *   person: {
   *     first_name: string,
   *     last_name: string,
   *     display_name?: string,
   *     person_code?: string,
   *     gender?: string
   *   },
   *   student_code: string,
   *   department_uuid: string,
   *   program_uuid?: string,
   *   classroom_uuid?: string,
   *   education_level: string,
   *   year_level: number,
   *   admission_year?: number,
   *   graduation_year?: number
   * }} payload
   * @returns {Promise<object>}
   */
  async create(payload) {
    const response = await api.post('/students', payload)
    return response.data.data
  },

  /**
   * Update mutable attributes of an existing student.
   * @param {string} uuid
   * @param {{
   *   person?: {
   *     first_name?: string,
   *     last_name?: string,
   *     display_name?: string,
   *     gender?: string
   *   },
   *   year_level?: number,
   *   admission_year?: number,
   *   graduation_year?: number
   * }} payload
   * @returns {Promise<object>}
   */
  async update(uuid, payload) {
    const response = await api.put(`/students/${uuid}`, payload)
    return response.data.data
  },

  /**
   * Change a student's academic status (STD-008 / STD-016).
   * @param {string} uuid
   * @param {{ status: string, reason?: string }} payload
   * @returns {Promise<object>}
   */
  async changeStatus(uuid, payload) {
    const response = await api.patch(`/students/${uuid}/status`, payload)
    return response.data.data
  },

  /**
   * Fetch paginated academic status history of a student (STD-008 / STD-016).
   * @param {string} uuid
   * @param {{ page?: number, per_page?: number }} params
   * @returns {Promise<{ data: Array<object>, meta: { pagination: object } }>}
   */
  async getStatusHistory(uuid, params = {}) {
    const response = await api.get(`/students/${uuid}/status-history`, { params })
    return {
      data: response.data.data,
      meta: response.data.meta,
    }
  },
}

/**
 * Student Import API Service (STD-012, STD-013, STD-014, STD-017).
 *
 * Exposes upload, validation progress, preview, paginated errors, and processing.
 * Public identifier is strictly the ImportJob UUID.
 */
export const studentImportService = {
  /**
   * Upload CSV file for student import intake validation (STD-012, STD-017).
   * @param {FormData} formData
   * @returns {Promise<object>}
   */
  async upload(formData) {
    const response = await api.post('/students/import', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data.data
  },

  /**
   * Fetch import job status and counters (STD-012, STD-013, STD-017).
   * @param {string} uuid
   * @returns {Promise<object>}
   */
  async show(uuid) {
    const response = await api.get(`/students/imports/${uuid}`)
    return response.data.data
  },

  /**
   * Fetch preview data and summary for an import job (STD-012, STD-014, STD-017).
   * @param {string} uuid
   * @returns {Promise<{ job: object, summary: object, sample_rows: Array<object>, sample_errors: Array<object> }>}
   */
  async preview(uuid) {
    const response = await api.get(`/students/imports/${uuid}/preview`)
    return response.data
  },

  /**
   * Fetch paginated error records for an import job (STD-012, STD-017).
   * @param {string} uuid
   * @param {{ page?: number, per_page?: number }} params
   * @returns {Promise<{ data: Array<object>, meta: { pagination: object } }>}
   */
  async errors(uuid, params = {}) {
    const response = await api.get(`/students/imports/${uuid}/errors`, { params })
    return {
      data: response.data.data,
      meta: response.data.meta,
    }
  },

  /**
   * Dispatch queued processing for a validated import job (STD-013, STD-014, STD-017).
   * @param {string} uuid
   * @returns {Promise<object>}
   */
  async process(uuid) {
    const response = await api.post(`/students/imports/${uuid}/process`)
    return response.data.data
  },
}

export default studentsService
