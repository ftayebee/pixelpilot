<script setup lang="ts">
import { palette, shortDay } from '@/lib/charts';
import { formatUsd } from '@/lib/format';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';

interface Day {
    date: string;
    charges: string;
    costs: string;
    profit: string;
}

const props = defineProps<{ days: Day[] }>();

// Numbers only position the bars; tooltips show the exact server strings.
const data = computed<ChartData<'bar'>>(() => ({
    labels: props.days.map((d) => shortDay(d.date)),
    datasets: [
        { label: 'Charged to users', data: props.days.map((d) => Number(d.charges)), backgroundColor: palette.brand, borderRadius: 4, maxBarThickness: 14 },
        { label: 'Pixelforge cost', data: props.days.map((d) => Number(d.costs)), backgroundColor: palette.brandSoft, borderRadius: 4, maxBarThickness: 14 },
        { label: 'Profit', data: props.days.map((d) => Number(d.profit)), backgroundColor: palette.success, borderRadius: 4, maxBarThickness: 14 },
    ],
}));

const keys = ['charges', 'costs', 'profit'] as const;

const options = computed<ChartOptions<'bar'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 6, padding: 16 } },
        tooltip: {
            callbacks: {
                label: (ctx) => ` ${ctx.dataset.label}: ${formatUsd(props.days[ctx.dataIndex]?.[keys[ctx.datasetIndex]])}`,
            },
        },
    },
    scales: {
        x: { grid: { display: false }, border: { display: false } },
        y: { beginAtZero: true, grid: { color: palette.grid }, border: { display: false }, ticks: { callback: (v) => `$${v}` } },
    },
}));
</script>

<template>
    <Bar :data="data" :options="options" aria-label="Charges, costs and profit per day" role="img" />
</template>
