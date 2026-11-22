<script setup lang="ts">
import { palette, shortDay } from '@/lib/charts';
import { formatUsd } from '@/lib/format';
import type { DailyPoint } from '@/types/generation';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Line } from 'vue-chartjs';

const props = defineProps<{ days: DailyPoint[] }>();

// Chart.js needs numbers to plot. The exact string is kept for the tooltip,
// so the float is only ever used to position the point.
const data = computed<ChartData<'line'>>(() => ({
    labels: props.days.map((d) => shortDay(d.date)),
    datasets: [
        {
            label: 'Spent',
            data: props.days.map((d) => Number(d.spent)),
            borderColor: palette.success,
            backgroundColor: 'rgba(22, 163, 106, 0.08)',
            fill: true,
            cubicInterpolationMode: 'monotone',
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2,
        },
    ],
}));

const options = computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (ctx) => ` ${formatUsd(props.days[ctx.dataIndex]?.spent)}` } },
    },
    scales: {
        x: { grid: { display: false }, border: { display: false } },
        y: { beginAtZero: true, grid: { color: palette.grid }, border: { display: false }, ticks: { callback: (v) => `$${v}` } },
    },
}));
</script>

<template>
    <Line :data="data" :options="options" aria-label="Spending per day" role="img" />
</template>
