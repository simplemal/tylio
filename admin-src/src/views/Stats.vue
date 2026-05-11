<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '../api'
import type { StatsByBlock, StatsByDay, StatsTotals } from '../types'

const { t } = useI18n()

const totals = ref<StatsTotals | null>(null)
const byDay = ref<StatsByDay[]>([])
const byBlock = ref<StatsByBlock[]>([])

onMounted(async () => {
  const r = await api.stats()
  totals.value = r.totals
  byDay.value = r.by_day
  byBlock.value = r.by_block
})

const max = computed(() => Math.max(1, ...byDay.value.map((d) => d.visits)))
</script>

<template>
  <div class="mb-6">
    <p class="eyebrow">{{ t('stats.eyebrow') }}</p>
    <h1 class="heading">{{ t('stats.title') }}</h1>
  </div>

  <div v-if="totals" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="tile">
      <div class="eyebrow">{{ t('stats.totalVisits') }}</div>
      <div class="font-display text-3xl mt-1">{{ totals.total_visits }}</div>
    </div>
    <div class="tile">
      <div class="eyebrow">{{ t('stats.todayVisits') }}</div>
      <div class="font-display text-3xl mt-1">{{ totals.today_visits }}</div>
    </div>
    <div class="tile">
      <div class="eyebrow">{{ t('stats.uniqueDays') }}</div>
      <div class="font-display text-3xl mt-1">{{ totals.unique_days }}</div>
    </div>
    <div class="tile">
      <div class="eyebrow">{{ t('stats.submissions7d') }}</div>
      <div class="font-display text-3xl mt-1">{{ totals.submissions_unread }}</div>
    </div>
  </div>

  <section class="tile mb-6">
    <h3 class="font-display text-lg mb-4">{{ t('stats.last30Days') }}</h3>
    <div v-if="byDay.length === 0" class="text-ink-300 text-sm">{{ t('stats.noVisits') }}</div>
    <div v-else class="flex items-end gap-1 h-40">
      <div
        v-for="d in byDay"
        :key="d.day"
        class="flex-1 bg-ink-100/30 hover:bg-ink-100 rounded-t-md transition"
        :style="{ height: (d.visits / max) * 100 + '%' }"
        :title="`${d.day}: ${d.visits}`"
      ></div>
    </div>
  </section>

  <section class="tile">
    <h3 class="font-display text-lg mb-4">{{ t('stats.byBlockTitle') }}</h3>
    <ul class="divide-y divide-white/5">
      <li v-for="b in byBlock" :key="b.id" class="py-2 flex items-center justify-between">
        <span
          >#{{ b.id }} <span class="text-ink-300">({{ b.type }})</span></span
        >
        <span class="font-display">{{ b.clicks }}</span>
      </li>
      <li v-if="byBlock.length === 0" class="text-ink-300 text-sm py-2">
        {{ t('stats.noClicks') }}
      </li>
    </ul>
  </section>
</template>
