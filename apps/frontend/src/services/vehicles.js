import api from '@/services/api'

/**
 * Vehicle registry API client (VEH-004 / VEH-011, ACR-VEH-01).
 */
export default {
  async listVehicles(params = {}) {
    const response = await api.get('/vehicles', { params })
    return response.data
  },

  async getVehicle(uuid) {
    const response = await api.get(`/vehicles/${uuid}`)
    return response.data
  },

  async createVehicle(payload) {
    const response = await api.post('/vehicles', payload)
    return response.data
  },

  async updateVehicle(uuid, payload) {
    const response = await api.put(`/vehicles/${uuid}`, payload)
    return response.data
  },

  async linkPerson(uuid, payload) {
    const response = await api.post(`/vehicles/${uuid}/links`, payload)
    return response.data
  },

  async endLink(uuid, linkUuid, payload = {}) {
    const response = await api.post(`/vehicles/${uuid}/links/${linkUuid}/end`, payload)
    return response.data
  },

  /**
   * People to link: searches students and staff the user is allowed to see and returns
   * [{ uuid, name, code, group }]. A source the user cannot read is skipped.
   */
  async searchPeople(keyword, { students = true, staff = true } = {}) {
    const calls = []
    if (students) {
      calls.push(
        api
          .get('/students', { params: { keyword, per_page: 10 } })
          .then((r) =>
            (r.data?.data || []).map((s) => ({
              uuid: s.person?.uuid,
              name: personName(s.person),
              code: s.student_code,
              group: 'นักเรียน',
            })),
          )
          .catch(() => []),
      )
    }
    if (staff) {
      calls.push(
        api
          .get('/staff', { params: { keyword, per_page: 10 } })
          .then((r) =>
            (r.data?.data || []).map((s) => ({
              uuid: s.person?.uuid,
              name: personName(s.person),
              code: s.staff_code,
              group: 'บุคลากร',
            })),
          )
          .catch(() => []),
      )
    }
    const results = (await Promise.all(calls)).flat()
    return results.filter((p) => p.uuid)
  },
}

function personName(person) {
  if (!person) return ''
  const display = (person.display_name || '').trim()
  return display || `${person.first_name || ''} ${person.last_name || ''}`.trim()
}
