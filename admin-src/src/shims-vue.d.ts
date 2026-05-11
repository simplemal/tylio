declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  // For pure `<script setup>` files this shim isn't used (vue-tsc infers directly).
  // It's still required for default imports of .vue from contexts where TS doesn't pick it up.
  const component: DefineComponent<object, object, unknown>
  export default component
}

declare module 'vuedraggable' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<Record<string, unknown>, object, unknown>
  export default component
}

declare namespace JSX {
  interface IntrinsicElements {
    'iconify-icon': Record<string, unknown>
  }
}
