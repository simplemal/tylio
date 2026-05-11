import { defineStore } from 'pinia'
import type { User } from '../types'

export const useAuth = defineStore('auth', {
  state: () => ({
    user: null as User | null,
    csrf: null as string | null,
    bootstrapped: false,
  }),
  getters: {
    isLogged: (s) => s.user !== null,
  },
})
