// Applies the saved appearance before first paint to avoid a light/dark flash.
// Keep in sync with src/stores/theme.js (storage key 'sc-theme').
;(function () {
  try {
    var mode = window.localStorage.getItem('sc-theme')
    var dark =
      mode === 'dark' ||
      (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)
    if (dark) document.documentElement.classList.add('dark')
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light'
  } catch {
    // Ignore: the theme store applies the theme once the app boots.
  }
})()
