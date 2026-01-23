<script setup lang="ts">
import { RouterView, RouterLink } from 'vue-router'
</script>

<template>
  <div class="min-h-screen bg-background text-foreground flex flex-col font-sans antialiased">
    <header class="border-b bg-card">
      <div class="container mx-auto px-4 h-16 flex items-center justify-between">
        <RouterLink to="/" class="flex items-center gap-2 font-bold text-xl">
          <img src="/img/logo.png" alt="Logo" class="h-8" /> <!-- Assuming I copy img to public -->
        </RouterLink>
        <nav class="flex gap-4">
          <RouterLink to="/api-docs" class="text-sm font-bold bg-primary text-primary-foreground px-4 py-2 rounded-md hover:bg-primary/90 transition-colors">API 文档</RouterLink>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <RouterView />
    </main>

    <footer class="border-t py-6 bg-muted/20">
      <div class="container mx-auto px-4 text-center text-sm text-muted-foreground">
        &copy; 2026 NingZeLogs - <a href="https://beian.miit.gov.cn/" target="_blank">新ICP备2024015133号-5</a><br/> - Powered by ZeinkLab ＆ Lemwood -
      </div>
    </footer>
  </div>
</template>