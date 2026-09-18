import { ref } from 'vue'
import { defineStore } from 'pinia'

/**
 * Minimal application store placeholder (FND-004) to establish the Pinia
 * store pattern. Real auth/session/feature stores are added in later modules.
 */
export const useAppStore = defineStore('app', () => {
  const appName = ref('Smart Campus')
  return { appName }
})
