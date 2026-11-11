import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useThemeStore } from './stores/theme'
// Self-hosted fonts (bundled by Vite) so campus LAN deployments work offline.
import '@fontsource-variable/inter'
import '@fontsource-variable/anuphan'
import '@fontsource/prompt/500.css'
import '@fontsource/prompt/600.css'
import '@fontsource/prompt/700.css'
import './assets/main.css'

const app = createApp(App)

const pinia = createPinia()
app.use(pinia)
useThemeStore(pinia).init()
app.use(router)

app.mount('#app')
