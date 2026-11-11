<script setup>
import { RouterView } from 'vue-router'
import ThemeToggle from '@/components/common/ThemeToggle.vue'

const year = new Date().getFullYear() + 543

const highlights = [
  {
    title: 'รู้จำใบหน้าแม่นยำ',
    text: 'ตรวจจับ ตรวจสอบความมีชีวิต และจับคู่ใบหน้าแบบเรียลไทม์',
    icon: 'M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z',
  },
  {
    title: 'ควบคุมด้วยนโยบาย',
    text: 'ระบบหลังบ้านตัดสินใจตามนโยบาย พร้อมให้เจ้าหน้าที่ทบทวนกรณียกเว้น',
    icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  },
  {
    title: 'ตรวจสอบย้อนหลังได้ทุกขั้นตอน',
    text: 'บันทึกเหตุการณ์และการเปลี่ยนแปลงทั้งหมดอย่างครบถ้วน',
    icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
  },
]
</script>

<template>
  <!-- Split shell for unauthenticated pages: brand panel + form panel. -->
  <div class="grid min-h-screen bg-canvas lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
    <!-- Brand panel (desktop) -->
    <aside class="auth-brand relative hidden overflow-hidden text-white lg:flex lg:flex-col">
      <div class="auth-grid absolute inset-0" aria-hidden="true"></div>
      <div class="auth-glow auth-glow--a" aria-hidden="true"></div>
      <div class="auth-glow auth-glow--b" aria-hidden="true"></div>

      <div class="relative z-10 flex flex-1 flex-col justify-between p-12 xl:p-16">
        <div class="flex items-center gap-3">
          <div class="auth-mark flex h-11 w-11 items-center justify-center rounded-2xl">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
              />
            </svg>
          </div>
          <div class="leading-tight">
            <p class="font-display text-[17px] font-semibold tracking-tight">สถานศึกษาอัจฉริยะ</p>
            <p class="text-xs text-white/55">ความปลอดภัยและการเข้าออก</p>
          </div>
        </div>

        <div class="max-w-lg">
          <p
            class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-white/75 backdrop-blur"
          >
            <span class="relative flex h-2 w-2">
              <span
                class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/70"
              ></span>
              <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span>
            </span>
            ระบบเฝ้าระวังพร้อมใช้งาน
          </p>
          <h2
            class="font-display mt-6 text-4xl leading-[1.25] font-semibold tracking-tight xl:text-[44px]"
          >
            <span class="whitespace-nowrap">ปกป้องทุกพื้นที่</span><br />
            <span class="whitespace-nowrap">ของสถานศึกษา</span>
            <span class="auth-gradient-text whitespace-nowrap">ด้วยปัญญาประดิษฐ์</span>
          </h2>
          <p class="mt-5 text-base leading-relaxed text-white/65">
            แพลตฟอร์มรักษาความปลอดภัยและควบคุมการเข้าออกแบบครบวงจร
            ช่วยให้เจ้าหน้าที่เห็นภาพรวมได้ทันทีและตัดสินใจได้อย่างมั่นใจ
          </p>

          <ul class="mt-10 space-y-5">
            <li v-for="item in highlights" :key="item.title" class="flex gap-4">
              <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/[0.06] text-sky-300"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="1.8"
                    :d="item.icon"
                  />
                </svg>
              </div>
              <div>
                <p class="text-[15px] font-semibold text-white">{{ item.title }}</p>
                <p class="mt-0.5 text-sm text-white/55">{{ item.text }}</p>
              </div>
            </li>
          </ul>
        </div>

        <p class="text-xs text-white/40">© {{ year }} ระบบสถานศึกษาอัจฉริยะ · สงวนลิขสิทธิ์</p>
      </div>
    </aside>

    <!-- Form panel -->
    <main class="relative flex min-h-screen flex-col overflow-hidden">
      <div class="auth-form-bg pointer-events-none absolute inset-0" aria-hidden="true"></div>
      <div class="relative z-10 flex items-center justify-between p-5 sm:p-6">
        <div class="flex items-center gap-2.5 lg:invisible">
          <div
            class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white shadow-md shadow-blue-600/25"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
              />
            </svg>
          </div>
          <p class="font-display text-[15px] font-semibold tracking-tight text-gray-900">
            สถานศึกษาอัจฉริยะ
          </p>
        </div>
        <ThemeToggle />
      </div>

      <div class="relative z-10 flex flex-1 items-center justify-center px-4 pb-12 sm:px-6">
        <div
          class="w-full max-w-[440px] rounded-3xl border border-gray-200/80 bg-surface/90 p-7 shadow-xl backdrop-blur-xl sm:p-9"
        >
          <RouterView v-slot="{ Component }">
            <Transition name="page" appear>
              <component :is="Component" />
            </Transition>
          </RouterView>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.font-display {
  font-family: var(--font-display);
}

.auth-brand {
  background:
    radial-gradient(120% 80% at 0% 0%, oklch(32% 0.12 268) 0%, transparent 60%),
    radial-gradient(90% 70% at 100% 100%, oklch(30% 0.1 230) 0%, transparent 60%),
    linear-gradient(160deg, oklch(20% 0.05 268), oklch(14% 0.035 270));
}

.auth-grid {
  background-image:
    linear-gradient(to right, rgb(255 255 255 / 0.05) 1px, transparent 1px),
    linear-gradient(to bottom, rgb(255 255 255 / 0.05) 1px, transparent 1px);
  background-size: 44px 44px;
  mask-image: radial-gradient(ellipse 80% 70% at 40% 40%, #000 30%, transparent 85%);
}

.auth-glow {
  position: absolute;
  border-radius: 9999px;
  filter: blur(80px);
  opacity: 0.55;
  animation: auth-float 14s ease-in-out infinite;
}
.auth-glow--a {
  top: -6rem;
  right: -4rem;
  width: 26rem;
  height: 26rem;
  background: oklch(55% 0.22 268);
}
.auth-glow--b {
  bottom: -8rem;
  left: -6rem;
  width: 24rem;
  height: 24rem;
  background: oklch(62% 0.15 210);
  animation-delay: -7s;
}

@keyframes auth-float {
  0%,
  100% {
    transform: translate3d(0, 0, 0) scale(1);
  }
  50% {
    transform: translate3d(1.5rem, 1rem, 0) scale(1.08);
  }
}

.auth-mark {
  background: linear-gradient(135deg, oklch(62% 0.2 262), oklch(52% 0.24 280));
  box-shadow:
    0 0 0 1px rgb(255 255 255 / 0.15) inset,
    0 10px 30px -8px oklch(55% 0.22 268 / 0.8);
}

.auth-gradient-text {
  background: linear-gradient(90deg, oklch(82% 0.11 230), oklch(78% 0.13 280));
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}

.auth-form-bg {
  background:
    radial-gradient(40rem 28rem at 85% -10%, var(--color-blue-100), transparent 70%),
    radial-gradient(30rem 24rem at -10% 110%, var(--color-indigo-50), transparent 70%);
}

@media (prefers-reduced-motion: reduce) {
  .auth-glow {
    animation: none;
  }
}
</style>
