<script setup lang="ts">
defineProps<{ text: string; icon?: string }>()
</script>

<template>
  <span class="tip" tabindex="0" :aria-label="text">
    <iconify-icon :icon="icon || 'lucide:info'" width="14"></iconify-icon>
    <span class="tip__pop" role="tooltip">{{ text }}</span>
  </span>
</template>

<style scoped>
.tip {
  position: relative;
  display: inline-flex;
  align-items: center;
  cursor: help;
  color: rgb(var(--ink-300-rgb));
  transition: color 0.15s ease;
  outline: none;
}
.tip:hover,
.tip:focus {
  color: rgb(var(--accent-rgb));
}
.tip__pop {
  position: absolute;
  bottom: calc(100% + 8px);
  left: 0;
  transform: translateY(4px);
  background: rgb(var(--ink-900-rgb));
  border: 1px solid rgb(var(--ink-300-rgb) / 0.25);
  color: rgb(var(--ink-100-rgb));
  font-size: 12px;
  font-weight: 400;
  letter-spacing: 0;
  text-transform: none;
  line-height: 1.45;
  padding: 9px 12px;
  border-radius: 10px;
  width: max-content;
  max-width: 260px;
  opacity: 0;
  pointer-events: none;
  transition:
    opacity 0.18s ease,
    transform 0.18s ease;
  z-index: 30;
  box-shadow:
    0 12px 28px -10px rgba(0, 0, 0, 0.5),
    0 1px 0 rgba(255, 255, 255, 0.04) inset;
}
.tip__pop::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 14px;
  width: 10px;
  height: 10px;
  background: rgb(var(--ink-900-rgb));
  border-right: 1px solid rgb(var(--ink-300-rgb) / 0.25);
  border-bottom: 1px solid rgb(var(--ink-300-rgb) / 0.25);
  transform: translateY(-50%) rotate(45deg);
}
.tip:hover .tip__pop,
.tip:focus .tip__pop {
  opacity: 1;
  transform: translateY(0);
}
</style>