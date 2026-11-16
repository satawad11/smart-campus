import api from '@/services/api'

/**
 * Biometric Face & AI Services API Client (FACE-016, FACE-017, FACE-018).
 */
export default {
  /**
   * List face profiles with pagination and filters (FACE-017).
   */
  async listProfiles(params = {}) {
    const response = await api.get('/faces', { params })
    return response.data
  },

  /**
   * Get face profile by UUID (FACE-017).
   */
  async getProfile(uuid) {
    const response = await api.get(`/faces/${uuid}`)
    return response.data
  },

  /**
   * Get face profile for a specific person (FACE-017).
   */
  async getPersonFace(personUuid) {
    const response = await api.get(`/persons/${personUuid}/faces`)
    return response.data
  },

  /**
   * Enroll a new biometric face profile (FACE-016).
   */
  async enrollFace(formData, personUuid = null) {
    const url = personUuid ? `/persons/${personUuid}/face/enroll` : '/faces/enroll'
    const response = await api.post(url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data
  },

  /**
   * Verify an enrolled face profile (FACE-010).
   */
  async verifyEnrollment(formData, targetUuid = null, isPerson = false) {
    let url = '/faces/verify'
    if (targetUuid) {
      url = isPerson ? `/persons/${targetUuid}/face/verify` : `/faces/${targetUuid}/verify`
    }
    const response = await api.post(url, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data
  },

  /**
   * Check duplicate review candidates (FACE-011).
   */
  async checkDuplicateReview(targetUuid, isPerson = false) {
    const url = isPerson
      ? `/persons/${targetUuid}/face/duplicate-review`
      : `/faces/${targetUuid}/duplicate-review`
    const response = await api.get(url)
    return response.data
  },

  /**
   * Submit decision for duplicate review (FACE-011).
   */
  async submitDuplicateDecision(reviewUuid, decision, resolutionNotes = null) {
    const response = await api.post(`/faces/duplicate-reviews/${reviewUuid}/decision`, {
      decision,
      resolution_notes: resolutionNotes,
    })
    return response.data
  },

  /**
   * Run restricted recognition diagnostic test (FACE-018).
   */
  async runRecognitionTest(formData) {
    const response = await api.post('/faces/recognition-test', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data
  },

  /**
   * Live capture feedback for one webcam frame: face boxes + quality metrics, no
   * embedding (FACE-022, POST /faces/check-quality).
   */
  async checkQuality(imageBlob) {
    const formData = new FormData()
    formData.append('image', imageBlob, 'frame.jpg')
    const response = await api.post('/faces/check-quality', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },

  /**
   * Identify one webcam frame against enrolled faces: MATCH / REVIEW / UNKNOWN
   * (FACE-022, POST /faces/recognize).
   */
  async recognize(imageBlob) {
    const formData = new FormData()
    formData.append('image', imageBlob, 'scan.jpg')
    const response = await api.post('/faces/recognize', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },

  /**
   * Revoke face profile (FACE-014, FACE-017).
   */
  async revokeProfile(uuid, reason) {
    const response = await api.post(`/faces/${uuid}/revoke`, { reason })
    return response.data
  },

  /**
   * Revoke face profile by Person UUID (FACE-014, FACE-017).
   */
  async revokePersonFace(personUuid, reason) {
    const response = await api.post(`/persons/${personUuid}/face/revoke`, { reason })
    return response.data
  },

  /**
   * Re-enroll face profile (FACE-014, FACE-017).
   */
  async reenrollProfile(uuid, formData) {
    const response = await api.post(`/faces/${uuid}/reenroll`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data
  },

  /**
   * Re-enroll face profile by Person UUID (FACE-014, FACE-017).
   */
  async reenrollPersonFace(personUuid, formData) {
    const response = await api.post(`/persons/${personUuid}/face/reenroll`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return response.data
  },
}
