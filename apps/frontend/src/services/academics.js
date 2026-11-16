import api from './api'

/**
 * Academic Master Data API Service (STD-015).
 *
 * Provides lookup APIs for Departments, Programs, and Classrooms.
 */
export const academicsService = {
  /**
   * List departments.
   * @param {{ status?: string, keyword?: string, per_page?: number }} params
   * @returns {Promise<Array<object>>}
   */
  async listDepartments(params = { per_page: 100, status: 'ACTIVE' }) {
    const response = await api.get('/departments', { params })
    return response.data.data || []
  },

  /**
   * List programs, optionally filtered by department_uuid.
   * @param {{ department_uuid?: string, status?: string, per_page?: number }} params
   * @returns {Promise<Array<object>>}
   */
  async listPrograms(params = { per_page: 100, status: 'ACTIVE' }) {
    const response = await api.get('/programs', { params })
    return response.data.data || []
  },

  /**
   * List classrooms, optionally filtered by department_uuid or program_uuid.
   * @param {{ department_uuid?: string, program_uuid?: string, status?: string, per_page?: number }} params
   * @returns {Promise<Array<object>>}
   */
  async listClassrooms(params = { per_page: 100, status: 'ACTIVE' }) {
    const response = await api.get('/classrooms', { params })
    return response.data.data || []
  },

  /**
   * Management page (academic data): full envelope with pagination, any status.
   * @param {'departments'|'programs'|'classrooms'} resource
   */
  async page(resource, params = {}) {
    const response = await api.get(`/${resource}`, { params })
    return response.data
  },

  /** Create (academic.manage). */
  async create(resource, payload) {
    const response = await api.post(`/${resource}`, payload)
    return response.data
  },

  /** Update (academic.manage); codes, year and parent department cannot change. */
  async update(resource, uuid, payload) {
    const response = await api.put(`/${resource}/${uuid}`, payload)
    return response.data
  },
}

export default academicsService
