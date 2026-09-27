import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full border px-2.5 py-0.5 text-[11px] font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1.5 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/40 outline-none transition-colors",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        destructive:
          "border-destructive/30 bg-destructive/10 text-destructive dark:bg-destructive/25 dark:text-destructive-foreground dark:border-destructive/40 [a&]:hover:bg-destructive/20",
        outline:
          "border-border text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
        success:
          "border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300 dark:border-emerald-500/40 [a&]:hover:bg-emerald-500/20",
        warning:
          "border-amber-500/30 bg-amber-500/10 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300 dark:border-amber-500/40 [a&]:hover:bg-amber-500/20",
        info:
          "border-sky-500/30 bg-sky-500/10 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300 dark:border-sky-500/40 [a&]:hover:bg-sky-500/20",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
