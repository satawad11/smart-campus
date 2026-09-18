import { describe, it, expect } from 'vitest'
import router from '@/router'
import api from '@/services/api'

describe('FND-004 frontend bootstrap', () => {
  it('registers the login and dashboard placeholder routes', () => {
    const names = router.getRoutes().map((r) => r.name)
    expect(names).toContain('login')
    expect(names).toContain('dashboard')
  })

  it('exposes an axios API client pointed at the /api/v1 prefix', () => {
    expect(api.defaults.baseURL).toBe('/api/v1')
    expect(typeof api.get).toBe('function')
  })
})
