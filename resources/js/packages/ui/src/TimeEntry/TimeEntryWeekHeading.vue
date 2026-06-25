<script setup lang="ts">
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import { formatHumanReadableDuration } from '@/packages/ui/src/utils/time';
import { inject, type ComputedRef } from 'vue';
import type { Organization } from '@/packages/api/src';
import { CalendarIcon, ChevronDownIcon, ChevronRightIcon } from '@heroicons/vue/20/solid';

const organization = inject<ComputedRef<Organization>>('organization');

defineProps<{
    weekRangeDisplay: string;
    isCurrentWeek: boolean;
    duration: number;
    collapsed: boolean;
}>();

const emit = defineEmits<{
    toggle: [];
}>();
</script>

<template>
    <button
        type="button"
        class="w-full bg-card-background border-y border-border-primary py-2 text-xs @sm:text-sm sticky top-0 z-[1] hover:bg-card-background/80 transition-colors text-left"
        :aria-expanded="!collapsed"
        @click="emit('toggle')">
        <MainContainer>
            <div class="flex justify-between items-center">
                <div class="flex items-center gap-2 pl-1.5 @lg:pl-0 min-w-0">
                    <component
                        :is="collapsed ? ChevronRightIcon : ChevronDownIcon"
                        class="w-4 h-4 text-icon-default shrink-0" />
                    <CalendarIcon class="w-4 h-4 text-icon-default shrink-0 hidden @sm:block" />
                    <span class="text-text-primary font-medium truncate">
                        <span v-if="isCurrentWeek">This week</span>
                        <span v-else>{{ weekRangeDisplay }}</span>
                    </span>
                </div>
                <div class="text-text-primary pr-2 @lg:pr-[92px] shrink-0">
                    <span class="text-text-tertiary uppercase tracking-wider text-xs mr-2 hidden @sm:inline"
                        >Week total</span
                    >
                    <span class="font-semibold">
                        {{
                            formatHumanReadableDuration(
                                duration,
                                organization?.interval_format,
                                organization?.number_format
                            )
                        }}
                    </span>
                </div>
            </div>
        </MainContainer>
    </button>
</template>
