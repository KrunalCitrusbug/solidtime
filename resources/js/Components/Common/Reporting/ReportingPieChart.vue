<script setup lang="ts">
import VChart, { THEME_KEY } from 'vue-echarts';
import { computed, provide, inject, type ComputedRef } from 'vue';
import { use } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { PieChart } from 'echarts/charts';
import {
    GridComponent,
    LegendComponent,
    TitleComponent,
    TooltipComponent,
} from 'echarts/components';
import { formatReportingDuration } from '@/packages/ui/src/utils/time';
import { useCssVariable } from '@/packages/ui/src';
import type { Organization } from '@/packages/api/src';

use([CanvasRenderer, PieChart, TitleComponent, GridComponent, TooltipComponent, LegendComponent]);

provide(THEME_KEY, 'dark');

const organization = inject<ComputedRef<Organization>>('organization');

type ReportingChartDataEntry = {
    value: number;
    name: string;
    color: string;
}[];

const props = defineProps<{
    data: ReportingChartDataEntry | null;
}>();
const labelColor = useCssVariable('--color-text-secondary');

const seriesData = computed(() => {
    return props.data?.map((el) => {
        return {
            ...el,
            ...{
                itemStyle: {
                    color: `${el.color}BB`,
                },
                emphasis: {
                    itemStyle: {
                        color: `${el.color}`,
                    },
                },
            },
        };
    });
});
// The donut occupies a fixed band at the top; the legend is laid out below it.
// Grow the canvas with the number of entries so the legend is never clipped.
const DONUT_BAND = 230;
const LEGEND_LINE = 24;
const legendRows = computed(() => Math.ceil((props.data?.length ?? 0) / 1.5));
const chartHeight = computed(() => Math.max(460, DONUT_BAND + legendRows.value * LEGEND_LINE + 20));

const option = computed(() => ({
    tooltip: {
        trigger: 'item',
    },
    legend: {
        show: true,
        type: 'scroll',
        top: `${DONUT_BAND}px`,
        textStyle: {
            color: labelColor.value,
        },
    },
    backgroundColor: 'transparent',
    series: [
        {
            label: {
                show: false,
            },
            tooltip: {
                valueFormatter: (value: number) => {
                    return formatReportingDuration(
                        value,
                        organization?.value?.interval_format,
                        organization?.value?.number_format
                    );
                },
            },
            data: seriesData.value,
            radius: [55, 100],
            center: ['50%', '110px'],
            type: 'pie',
        },
    ],
}));
</script>

<template>
    <v-chart
        class="background-transparent max-w-[300px] mx-auto"
        :style="{ height: chartHeight + 'px' }"
        :autoresize="true"
        :option="option" />
</template>

<style scoped></style>
