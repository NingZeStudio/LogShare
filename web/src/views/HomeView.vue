<script setup lang="ts">
import { ref } from 'vue'
import { apiClient } from '@/lib/api'
import { useRouter, RouterLink } from 'vue-router'

const content = ref('')
const loading = ref(false)
const error = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const router = useRouter()

const triggerFileSelect = () => {
  fileInput.value?.click()
}

const onFileSelected = async (event: Event) => {
  const input = event.target as HTMLInputElement
  if (!input.files || input.files.length === 0) return

  const file = input.files[0]
  if (!file) return

  if (file.size > 10 * 1024 * 1024) {
    error.value = "文件过大 (最大 10MB)"
    return
  }

  try {
    const text = await file.text()
    content.value = text
  } catch (e) {
    error.value = "读取文件失败"
  }
}

const save = async () => {
  if (!content.value.trim()) return

  loading.value = true
  error.value = ''

  try {
    const params = new URLSearchParams()
    params.append('content', content.value)

    const response = await apiClient.post('/1/log', params, {
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        }
    })

    if (response.data.success) {
      router.push(`/${response.data.id}`)
    } else {
      error.value = response.data.error || '未知错误'
    }
  } catch (e: any) {
    console.error(e)
    error.value = e.response?.data?.error || e.message || '保存失败'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="container mx-auto px-4 py-12 flex flex-col items-center gap-8">
    <div class="text-center space-y-4">
      <h1 class="text-4xl font-extrabold tracking-tight lg:text-5xl">
        粘贴、分享并分析 <br/>
        <span class="text-primary">您的 Minecraft 日志</span>
      </h1>
      <p class="text-xl text-muted-foreground">
        轻松粘贴您的 Minecraft 日志以进行分享和分析。
      </p>
    </div>

    <!-- Main Paste Area -->
    <div class="w-full max-w-4xl bg-card border rounded-xl shadow-lg overflow-hidden flex flex-col relative group">
      <div class="bg-muted/50 px-4 py-3 border-b flex justify-between items-center">
        <span class="text-sm font-medium text-muted-foreground">在此粘贴您的日志</span>
        <div class="flex gap-2">
            <input type="file" ref="fileInput" class="hidden" @change="onFileSelected" accept=".log,.txt">
            <button 
                @click="triggerFileSelect"
                class="bg-secondary text-secondary-foreground hover:bg-secondary/90 h-9 px-4 py-2 inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none disabled:opacity-50"
            >
                选择文件
            </button>
            <button 
                @click="save" 
                :disabled="loading || !content"
                class="bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2 inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none disabled:opacity-50"
            >
                {{ loading ? '保存中...' : '保存' }}
            </button>
        </div>
      </div>
      
      <div class="relative">
          <textarea 
            v-model="content"
            class="w-full h-[500px] p-4 bg-background font-mono text-sm resize-none focus:outline-none"
            placeholder="[12:00:00] [Server thread/INFO]: Starting minecraft server version 1.20.1..."
          ></textarea>
          
          <div v-if="error" class="absolute bottom-4 left-4 right-4 bg-destructive/10 text-destructive border border-destructive/20 p-3 rounded-md text-sm">
            {{ error }}
          </div>
      </div>
    </div>

    <!-- Info Sections -->
    <div class="w-full max-w-4xl grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- API Info -->
        <div class="bg-card border rounded-xl p-6 shadow-sm flex flex-col justify-between hover:shadow-md transition-shadow">
            <div>
                <h2 class="text-xl font-bold mb-3 text-card-foreground">使用我们的 API</h2>
                <p class="text-muted-foreground text-sm leading-relaxed">
                    将 NingZeLogs 直接集成到您的服务器面板、托管软件或任何其他平台中。此平台专为高性能自动化而构建，可通过我们的 HTTP API 轻松集成到任何现有软件中。
                </p>
            </div>
            <RouterLink to="/api-docs" class="mt-6 inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground h-10 px-4 py-2 hover:bg-primary/90 transition-colors w-fit">
                查看 API 文档
            </RouterLink>
        </div>

        <!-- ZeinkLab Info -->
        <div class="bg-card border rounded-xl p-6 shadow-sm flex flex-col justify-between hover:shadow-md transition-shadow">
            <div>
                <h2 class="text-xl font-bold mb-3 text-card-foreground">查看 ZeinkLab</h2>
                <p class="text-muted-foreground text-sm leading-relaxed">
                    泽客来宾博客网是林梦泽（化名）搭建的一个个人分享日常、技术和游戏的综合内容网站，投身科技，用代码丰富生活，用数据填满热心。
                </p>
            </div>
            <a href="https://zeinklab.com/" target="_blank" class="mt-6 inline-flex items-center justify-center rounded-md text-sm font-medium bg-secondary text-secondary-foreground h-10 px-4 py-2 hover:bg-secondary/80 transition-colors w-fit">
                访问 ZeinkLab
            </a>
        </div>
        <!-- Lemwood Wiki Info -->
        <div class="bg-card border rounded-xl p-6 shadow-sm flex flex-col justify-between hover:shadow-md transition-shadow">
            <div>
                <h2 class="text-xl font-bold mb-3 text-card-foreground">查看 Lemwood Wiki</h2>
                <p class="text-muted-foreground text-sm leading-relaxed">
                    世界源于灵魂的幻想。这里是由柠枺维护的Minecraft插件开发及个人项目文档集的Wiki。
                </p>
            </div>
            <a href="https://wiki.lemwood.cn/" target="_blank" class="mt-6 inline-flex items-center justify-center rounded-md text-sm font-medium bg-secondary text-secondary-foreground h-10 px-4 py-2 hover:bg-secondary/80 transition-colors w-fit">
                访问 Lemwood Wiki
            </a>
        </div>
        <!-- Lemwood Mirror Service Info -->
        <div class="bg-card border rounded-xl p-6 shadow-sm flex flex-col justify-between hover:shadow-md transition-shadow">
            <div>
                <h2 class="text-xl font-bold mb-3 text-card-foreground">查看 Lemwood Mirror Service</h2>
                <p class="text-muted-foreground text-sm leading-relaxed">
                    这是一个提供各类Minecraft启动器下载的高速镜像站，致力于为玩家打造稳定、快速的资源获取体验。基于腾讯云200Mbps高速服务器与自研Go语言Mirror架构，我们确保主流启动器如HMCL、FCL以及其他启动器的分发效率，无论是日常更新还是开发调试，都能享受流畅可靠的下载服务。
                </p>
            </div>
            <a href="https://mirror.lemwood.icu/" target="_blank" class="mt-6 inline-flex items-center justify-center rounded-md text-sm font-medium bg-secondary text-secondary-foreground h-10 px-4 py-2 hover:bg-secondary/80 transition-colors w-fit">
                访问 Lemwood Mirror Service
            </a>
        </div>
    </div>
  </div>
</template>