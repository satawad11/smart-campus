import { ref } from 'vue'
import { defineStore } from 'pinia'
import api from '@/services/api'

/**
 * Feature flags from GET /api/v1/features (SEC-002). The backend is the authority and
 * answers 403 FEATURE_DISABLED for a disabled module; the UI only hides what is off.
 * Fails closed: until loaded, or if loading fails, every flag reads as off.
 */
export const useFeaturesStore = defineStore('features', () => {
  const flags = ref({})
  const loaded = ref(false)
  let pending = null

  function isOn(name) {
    return flags.value[name] === true
  }

  async function load(force = false) {
    if (loaded.value && !force) return
    if (pending) return pending
    pending = api
      .get('/features')
      .then((res) => {
        flags.value = res.data?.data || {}
        loaded.value = true
      })
      .catch(() => {
        flags.value = {}
      })
      .finally(() => {
        pending = null
      })
    return pending
  }

  return { flags, loaded, isOn, load }
})
