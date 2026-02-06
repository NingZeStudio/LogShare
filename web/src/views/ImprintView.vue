<script setup lang="ts">
import { ref } from 'vue';

// 固定使用繁體中文（台灣）
const imprintContent = {
  title: "法律聲明 (Imprint)",
  serviceProvider: {
    heading: "服務提供方",
    description: "本服務 (NingZeLogs) 由以下團隊聯合提供：",
    providers: [
      { name: "ZeinkLab", url: "zeinklab.com" },
      { name: "Lemwood", url: "lemwood.cn" }
    ]
  },
  contact: {
    heading: "聯絡方式",
    description: "如有任何疑問或需要支援，請透過以下方式聯絡我們：",
    email: "電子郵件: mengze2@foxmail.com"
  },
  contentDisclaimer: {
    heading: "內容免責聲明",
    text: "我們不對使用者上傳的記錄內容的準確性、完整性或合法性負責。記錄內容由使用者自行上傳並承擔責任。作為服務提供者，根據相關法律法規，我們僅對自己提供的原創內容負責。我們沒有義務監控第三方傳輸或儲存的資訊，也沒有義務調查表明非法活動的情況。"
  },
  linkDisclaimer: {
    heading: "連結免責聲明",
    text: "我們的服務可能包含指向第三方外部網站的連結，我們無法控制這些網站的內容。因此，我們不對這些外部內容承擔任何責任。連結頁面的提供者或營運者始終對內容負責。"
  },
  copyright: {
    heading: "版權說明",
    text: "本網站上的內容和作品受著作權法保護。複製、編輯、分發以及任何超出著作權法限制的使用都需要相關作者或創作者的書面同意。"
  }
};
</script>

<template>
  <div class="container mx-auto px-4 py-12 max-w-4xl">
    <div class="bg-card text-card-foreground border rounded-xl shadow-sm p-6 md:p-10">
      <div class="prose prose-neutral dark:prose-invert max-w-none">
        <h1 class="text-3xl font-bold mb-6 text-foreground">{{ imprintContent.title }}</h1>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ imprintContent.serviceProvider.heading }}</h2>
          <p class="text-foreground/90">{{ imprintContent.serviceProvider.description }}</p>
          <ul class="list-disc pl-6 mt-2 space-y-2 text-foreground/90">
              <li v-for="(provider, index) in imprintContent.serviceProvider.providers" :key="index">
                <strong>{{ provider.name }}</strong> ({{ provider.url }})
              </li>
          </ul>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ imprintContent.contact.heading }}</h2>
          <p class="text-foreground/90">{{ imprintContent.contact.description }}</p>
          <p class="mt-2 text-foreground/90">{{ imprintContent.contact.email }}</p>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ imprintContent.contentDisclaimer.heading }}</h2>
          <p class="text-foreground/90">
              {{ imprintContent.contentDisclaimer.text }}
          </p>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ imprintContent.linkDisclaimer.heading }}</h2>
          <p class="text-foreground/90">
              {{ imprintContent.linkDisclaimer.text }}
          </p>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ imprintContent.copyright.heading }}</h2>
          <p class="text-foreground/90">
              {{ imprintContent.copyright.text }}
          </p>
        </section>
      </div>
    </div>
  </div>
</template>
