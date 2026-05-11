<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from './components/AppShell.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'

const route = useRoute()
const isLogin = computed(() => route.name === 'login')
</script>

<template>
  <component :is="isLogin ? 'div' : AppShell">
    <router-view />
  </component>
  <ConfirmDialog />
</template>
