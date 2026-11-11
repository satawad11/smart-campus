import { ref, computed } from 'vue'
import { defineStore } from 'pinia'

/**
 * Theme store (UI-THEME).
 *
 * Holds the user's appearance preference: 'light', 'dark' or 'system'.
 * Only this non-sensitive UI preference is persisted in localStorage.
 * The resolved theme is applied as the `dark` class on <html>; index.html
 * runs the same resolution inline before first paint to avoid a flash.
 */
export const THEME_STORAGE_KEY = 'sc-theme'
const MODES = ['light', 'dark', 'system']

function readStoredMode() {
  try {
    const value = window.localStorage.getItem(THEME_STORAGE_KEY)
    return MODES.includes(value) ? value : 'system'
  } catch {
    return 'system'
  }
}

function systemQuery() {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null
}

export const useThemeStore = defineStore('theme', () => {
  const mode = ref(readStoredMode())
  const systemDark = ref(!!systemQuery()?.matches)

  const isDark = computed(() =>
    mode.value === 'system' ? systemDark.value : mode.value === 'dark',
  )

  let transitionTimer = null

  function apply({ animate = false } = {}) {
    const root = document.documentElement
    if (animate) {
      root.classList.add('theme-transition')
      clearTimeout(transitionTimer)
      transitionTimer = setTimeout(() => root.classList.remove('theme-transition'), 300)
    }
    root.classList.toggle('dark', isDark.value)
    root.style.colorScheme = isDark.value ? 'dark' : 'light'
  }

  function setMode(next) {
    if (!MODES.includes(next)) return
    mode.value = next
    try {
      window.localStorage.setItem(THEME_STORAGE_KEY, next)
    } catch {
      // Storage may be unavailable (private mode); the choice still applies this session.
    }
    apply({ animate: true })
  }

  /** Quick toggle between light and dark (leaves 'system' once used). */
  function toggle() {
    setMode(isDark.value ? 'light' : 'dark')
  }

  let initialized = false
  function init() {
    if (initialized) return
    initialized = true
    const query = systemQuery()
    if (query) {
      const onChange = (event) => {
        systemDark.value = event.matches
        if (mode.value === 'system') apply({ animate: true })
      }
      if (typeof query.addEventListener === 'function') query.addEventListener('change', onChange)
      else if (typeof query.addListener === 'function') query.addListener(onChange)
    }
    apply()
  }

  return { mode, isDark, setMode, toggle, init }
})
