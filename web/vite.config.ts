import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    proxy: {
      '/api': {
        target: 'https://api.mclogs.lemwood.icu',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, ''),
        headers: {
            'Host': 'api.mclogs.lemwood.icu'
        }
      }
    }
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: (id) => {
          // 分离大型库以确保每个chunk不超过200KB
          if (id.includes('node_modules')) {
            if (id.includes('markdown-it')) {
              return 'markdown';
            }
            if (id.includes('vue') || id.includes('vue-router')) {
              return 'vue-core';
            }
            if (id.includes('axios')) {
              return 'axios';
            }
            if (id.includes('radix-vue') || id.includes('lucide-vue-next')) {
              return 'ui-components';
            }
            if (id.includes('class-variance-authority') || id.includes('clsx') || id.includes('tailwind-merge')) {
              return 'utils';
            }
            // 其他node_modules统一打包到vendor
            return 'vendor';
          }

          // 页面级别的代码分割
          if (id.includes('src/views/HomeView')) {
            return 'home';
          }
          if (id.includes('src/views/LogView')) {
            return 'log';
          }
          if (id.includes('src/views/ApiDocsView')) {
            return 'api-docs';
          }
          if (id.includes('src/views/ImprintView')) {
            return 'imprint';
          }
          if (id.includes('src/views/PrivacyPolicyView')) {
            return 'privacy';
          }
        },
      }
    },
    // 控制chunk大小警告阈值（单位KB）
    chunkSizeWarningLimit: 500,
  }
})