<template>
  <div class="language-switcher">
    <button @click="toggleLanguageMenu" class="language-button" aria-label="切换语言">
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"></circle>
        <line x1="2" y1="12" x2="22" y2="12"></line>
        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
      </svg>
    </button>
    
    <div v-if="showMenu" class="language-menu" ref="menuRef">
      <div 
        class="language-option" 
        :class="{ active: currentLang === 'zh-CN' }"
        @click="switchLanguage('zh-CN')"
      >
        简体中文
      </div>
      <div 
        class="language-option" 
        :class="{ active: currentLang === 'zh-TW' }"
        @click="switchLanguage('zh-TW')"
      >
        繁體中文
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { detectSystemLanguage } from '@/lib/i18n';

const showMenu = ref(false);
const currentLang = ref(detectSystemLanguage());
const menuRef = ref<HTMLDivElement | null>(null);

// 切換語言菜單顯示狀態
const toggleLanguageMenu = () => {
  showMenu.value = !showMenu.value;
};

// 切換語言
const switchLanguage = (lang: 'zh-CN' | 'zh-TW') => {
  currentLang.value = lang;
  localStorage.setItem('preferred_language', lang);
  showMenu.value = false;
  
  // 觸發頁面刷新以應用新語言
  window.location.reload();
};

// 點擊外部關閉菜單
const handleClickOutside = (event: MouseEvent) => {
  if (menuRef.value && !menuRef.value.contains(event.target as Node)) {
    showMenu.value = false;
  }
};

onMounted(() => {
  document.addEventListener('mousedown', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('mousedown', handleClickOutside);
});
</script>

<style scoped>
.language-switcher {
  position: relative;
  display: inline-block;
}

.language-button {
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  border-radius: 4px;
  color: inherit;
  display: flex;
  align-items: center;
  justify-content: center;
}

.language-button:hover {
  background-color: rgba(0, 0, 0, 0.1);
}

.language-menu {
  position: absolute;
  top: 100%;
  right: 0;
  background: white;
  border: 1px solid #ddd;
  border-radius: 4px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  z-index: 1000;
  min-width: 120px;
  margin-top: 4px;
}

.dark .language-menu {
  background: #374151;
  border-color: #4b5563;
}

.language-option {
  padding: 8px 12px;
  cursor: pointer;
  border-bottom: 1px solid #eee;
  transition: background-color 0.2s;
}

.dark .language-option {
  border-bottom-color: #4b5563;
}

.language-option:last-child {
  border-bottom: none;
}

.language-option:hover {
  background-color: #f3f4f6;
}

.dark .language-option:hover {
  background-color: #4b5563;
}

.language-option.active {
  background-color: #dbeafe;
  font-weight: 500;
}

.dark .language-option.active {
  background-color: #3b82f6;
  color: white;
}
</style>