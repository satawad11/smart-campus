import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useThemeStore, THEME_STORAGE_KEY } from '@/stores/theme'
import ThemeToggle from '@/components/common/ThemeToggle.vue'

describe('Theme (UI-THEME)', () => {
  beforeEach(() => {
    window.localStorage.clear()
    document.documentElement.classList.remove('dark')
    setActivePinia(createPinia())
  })

  it('defaults to system mode and applies the dark class when chosen', () => {
    const theme = useThemeStore()
    theme.init()
    expect(theme.mode).toBe('system')

    theme.setMode('dark')
    expect(theme.isDark).toBe(true)
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark')

    theme.toggle()
    expect(theme.mode).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('restores a saved preference and ignores invalid values', () => {
    window.localStorage.setItem(THEME_STORAGE_KEY, 'dark')
    expect(useThemeStore().mode).toBe('dark')

    setActivePinia(createPinia())
    window.localStorage.setItem(THEME_STORAGE_KEY, 'neon')
    const theme = useThemeStore()
    expect(theme.mode).toBe('system')
    theme.setMode('neon')
    expect(theme.mode).toBe('system')
  })

  it('switcher sets the selected mode', async () => {
    const wrapper = mount(ThemeToggle)
    await wrapper.find('[data-mode="dark"]').trigger('click')
    expect(useThemeStore().mode).toBe('dark')
    expect(wrapper.find('[data-mode="dark"]').attributes('aria-checked')).toBe('true')
  })
})
