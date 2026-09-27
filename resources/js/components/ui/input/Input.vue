<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { useVModel } from "@vueuse/core"
import { cn } from "@/lib/utils"

interface Props {
  defaultValue?: string | number
  modelValue?: string | number
  class?: HTMLAttributes["class"]
  size?: "default" | "sm"
}

const props = withDefaults(defineProps<Props>(), {
  size: "default",
})

const emits = defineEmits<{
  (e: "update:modelValue", payload: string | number): void
}>()

const modelValue = useVModel(props, "modelValue", emits, {
  passive: true,
  defaultValue: props.defaultValue,
})
</script>

<template>
  <input
    v-model="modelValue"
    data-slot="input"
    :data-size="size"
    :class="cn(
      'file:text-foreground placeholder:text-muted-foreground selection:bg-primary/20 selection:text-foreground border-input w-full min-w-0 rounded-lg border bg-background/60 dark:bg-card/40 text-sm shadow-xs transition-colors duration-150 ease-out outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
      'hover:border-slate-400 dark:hover:border-slate-600',
      'focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/25 focus-visible:ring-offset-1',
      'aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive',
      size === 'sm' ? 'h-9 px-3 py-1.5 text-xs md:text-sm' : 'h-10 px-3.5 py-2 text-sm',
      props.class,
    )"
  >
</template>
