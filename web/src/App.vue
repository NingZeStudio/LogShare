<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterView, RouterLink, useRoute } from 'vue-router'
import { Sun, Moon, X } from 'lucide-vue-next'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import { setPageTitle, getCurrentPageTemplate } from '@/lib/pageTitle'

const isDark = ref(false)
const toggleCount = ref(0)
const showEasterEgg = ref(false)
const showCookieConsent = ref(false)

const easterEggImages = [
  'https://cdn.zeinklab.com/myfile/images/974d9feef5429ded.jpeg',
  'https://cdn.zeinklab.com/myfile/images/0b9453f27d4823ef.jpg',
  'https://cdn.zeinklab.com/myfile/images/8295488fa57aef04.jpeg'
]

const toggleTheme = () => {
  isDark.value = !isDark.value
  updateTheme()

  toggleCount.value++
  if (toggleCount.value >= 10) {
    showEasterEgg.value = true
    toggleCount.value = 0
  }
}

/**
 * 更新主題顏色
 * 處理深色/淺色模式切換及過渡動畫
 */
const updateTheme = () => {
  document.documentElement.classList.add('transition-colors', 'duration-500')

  if (isDark.value) {
    document.documentElement.classList.add('dark')
    localStorage.setItem('theme', 'dark')
  } else {
    document.documentElement.classList.remove('dark')
    localStorage.setItem('theme', 'light')
  }

  setTimeout(() => {
    document.documentElement.classList.remove('transition-colors', 'duration-500')
  }, 500)
}

const closeEasterEgg = () => {
  showEasterEgg.value = false
}

const acceptCookies = () => {
  localStorage.setItem('cookie_consent', 'true')
  showCookieConsent.value = false
}

onMounted(() => {
  const savedTheme = localStorage.getItem('theme')
  if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    isDark.value = true
  }
  updateTheme()

  if (!localStorage.getItem('cookie_consent')) {
    showCookieConsent.value = true
  }

  const route = useRoute();
  const template = getCurrentPageTemplate(route.name?.toString());
  setPageTitle(template);
})

</script>

<template>
  <div class="min-h-screen bg-background text-foreground flex flex-col font-sans antialiased transition-colors duration-500">
    <header class="border-b bg-card sticky top-0 z-40 w-full backdrop-blur">
      <div class="container mx-auto px-4 h-16 flex items-center justify-between">
        <RouterLink to="/" class="flex items-center gap-2 font-bold text-xl">
          <span class="text-primary">NingZeLogs</span><sup class="text-xs text-muted-foreground">ᴺᵉˣᵀ</sup>
        </RouterLink>
        <nav class="flex items-center gap-4">
          <RouterLink to="/api-docs" class="text-sm font-bold bg-primary text-primary-foreground px-4 py-2 rounded-md hover:bg-primary/90 transition-colors">API 文档</RouterLink>
          <LanguageSwitcher />
          <button @click="toggleTheme" class="p-2 rounded-md hover:bg-accent hover:text-accent-foreground transition-colors" aria-label="Toggle theme">
            <Sun v-if="!isDark" class="h-5 w-5" />
            <Moon v-else class="h-5 w-5" />
          </button>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <RouterView />
    </main>

    <footer class="border-t py-6 bg-muted/20">
      <div class="container mx-auto px-4 text-center text-sm text-muted-foreground">
        &copy; 2026 NingZeLogs - <a href="https://beian.miit.gov.cn/" target="_blank">新ICP备2024015133号-5</a><br/>
        <div class="mt-2 space-x-4">
          <RouterLink to="/imprint" class="hover:underline">法律声明</RouterLink>
          <RouterLink to="/privacy" class="hover:underline">隐私政策</RouterLink>
        </div>
        <div class="mt-2">
          - Powered by ZeinkLab ＆ Lemwood -
        </div>
      </div>
    </footer>

    <!-- Cookie Consent Banner -->
    <div v-if="showCookieConsent" class="fixed bottom-0 left-0 right-0 z-40 p-4 bg-background/80 backdrop-blur-md border-t border-border shadow-lg">
      <div class="container mx-auto flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="text-sm text-muted-foreground text-center md:text-left">
          <p>
            我们使用 Cookie 来提升您的体验。继续浏览即表示您同意我们使用 Cookie。
            <RouterLink to="/privacy" class="underline hover:text-primary transition-colors">了解更多</RouterLink>
          </p>
        </div>
        <div class="flex gap-2">
           <button @click="acceptCookies" class="bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 rounded-md text-sm font-medium transition-colors">
             接受
           </button>
        </div>
      </div>
    </div>

    <!-- Easter Egg Modal -->
    <div v-if="showEasterEgg" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm p-4" @click.self="closeEasterEgg">
      <div class="bg-card border text-card-foreground rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto relative animate-in fade-in zoom-in duration-300">
        <button @click="closeEasterEgg" class="absolute right-4 top-4 p-2 rounded-full bg-secondary/80 hover:bg-secondary transition-colors z-10">
          <X class="h-6 w-6" />
        </button>
        <div class="p-6 grid gap-6">
          <h2 class="text-2xl font-bold text-center">私货</h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div v-for="(img, index) in easterEggImages" :key="index" class="aspect-[3/4] rounded-lg overflow-hidden border bg-muted">
              <img :src="img" class="w-full h-full object-cover hover:scale-105 transition-transform duration-500" alt="Secret Reward" />
            </div>
          </div>
          <p class="text-center text-muted-foreground text-sm">这些是给最细心的探索者的特别奖励~</p>
        </div>
      </div>
    </div>
  </div>
</template>