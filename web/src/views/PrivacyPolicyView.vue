<script setup lang="ts">
import { ref } from 'vue';

// 固定使用繁體中文（台灣）
const privacyContent = {
  title: "隱私政策",
  lastUpdated: "最後更新日期：2026年1月23日",
  overview: {
    heading: "概述",
    text: "我們非常重視您的隱私保護。本隱私權政策旨在說明 NingZeLogs 如何收集、使用和保護您的個人資料。使用本服務即表示您同意本政策中所述的資料收集和使用方式。"
  },
  collectedInfo: {
    heading: "我們收集的資訊",
    items: [
      {
        title: "上傳的記錄內容：",
        description: "您主動上傳到我們伺服器的 Minecraft 記錄檔。請注意，記錄可能包含聊天記錄、IP位址或其他遊戲內資訊。在上傳之前，請確保您有權分享這些內容，並建議刪除敏感資訊。"
      },
      {
        title: "存取記錄：",
        description: "出於安全和維護目的，我們的伺服器可能會自動記錄您的 IP 位址、瀏覽器類型、存取時間等技術資訊。"
      }
    ]
  },
  infoUsage: {
    heading: "資訊的使用",
    description: "我們要收集的資訊僅用於以下用途：",
    purposes: [
      "提供記錄分享和分析服務。",
      "維護服務的安全性和穩定性，防止濫用。",
      "改進我們的服務體驗。"
    ]
  },
  cookies: {
    heading: "Cookie 和本機儲存",
    text: "我們可能會使用 Cookie 或本機儲存技術來儲存您的偏好設定（如深色模式狀態），以提升使用者體驗。這些資料通常儲存在您的裝置上。"
  },
  infoSharing: {
    heading: "資訊共用",
    description: "我們不會將您的個人資料出售、出租或透過任何方式交給第三方。除非：",
    exceptions: [
      "獲得您的明確同意。",
      "法律法規要求或回應司法程序。"
    ]
  },
  rights: {
    heading: "您的權利",
    text: "您有權要求刪除您上傳的記錄檔。如果您希望刪除特定內容，請透過我們的支援管道聯絡我們，並提供相應的記錄 ID 或連結。"
  }
};
</script>

<template>
  <div class="container mx-auto px-4 py-12 max-w-4xl">
    <div class="bg-card text-card-foreground border rounded-xl shadow-sm p-6 md:p-10">
      <div class="prose prose-neutral dark:prose-invert max-w-none">
        <h1 class="text-3xl font-bold mb-6 text-foreground">{{ privacyContent.title }}</h1>

        <p class="mb-4 text-muted-foreground">{{ privacyContent.lastUpdated }}</p>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ privacyContent.overview.heading }}</h2>
          <p class="text-foreground/90">
              {{ privacyContent.overview.text }}
          </p>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ privacyContent.collectedInfo.heading }}</h2>
          <ul class="list-disc pl-6 space-y-2 text-foreground/90">
              <li v-for="(item, index) in privacyContent.collectedInfo.items" :key="index">
                <strong>{{ item.title }}</strong> {{ item.description }}
              </li>
          </ul>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ privacyContent.infoUsage.heading }}</h2>
          <p class="text-foreground/90">{{ privacyContent.infoUsage.description }}</p>
          <ul class="list-disc pl-6 mt-2 space-y-2 text-foreground/90">
              <li v-for="(purpose, index) in privacyContent.infoUsage.purposes" :key="index">{{ purpose }}</li>
          </ul>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ privacyContent.cookies.heading }}</h2>
          <p class="text-foreground/90">
              {{ privacyContent.cookies.text }}
          </p>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ privacyContent.infoSharing.heading }}</h2>
          <p class="text-foreground/90">
              {{ privacyContent.infoSharing.description }}
          </p>
          <ul class="list-disc pl-6 mt-2 space-y-2 text-foreground/90">
              <li v-for="(exception, index) in privacyContent.infoSharing.exceptions" :key="index">{{ exception }}</li>
          </ul>
        </section>

        <section class="mb-8">
          <h2 class="text-xl font-semibold mb-4 text-foreground">{{ privacyContent.rights.heading }}</h2>
          <p class="text-foreground/90">
              {{ privacyContent.rights.text }}
          </p>
        </section>
      </div>
    </div>
  </div>
</template>
