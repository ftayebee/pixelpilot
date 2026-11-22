<script setup lang="ts">
import { palette, shortDay } from '@/lib/charts';
import type { DailyPoint } from '@/types/generation';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';

const props = defineProps<{ days: DailyPoint[] }>();

const data = computed<ChartData<'bar'>>(() => ({
    labels: props.days.map((d) => shortDay(d.date)),
    datasets: [
        { label: 'Succeeded', data: props.days.map((d) => d.succeeded), backgroundColor: palette.brand, borderRadius: 6, maxBarThickness: 18 },
        { label: 'Failed', data: props.days.map((d) => d.failed), backgroundColor: palette.danger, borderRadius: 6, maxBarThickness: 18 },
        { label: 'In progress', data: props.days.map((d) => d.pending), backgroundColor: palette.brandSoft, borderRadius: 6, maxBarThickness: 18 },
    ],
}));

const options: ChartOptions<'bar'> = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 6, padding: 16 } } },
    scales: {
        x: { stacked: true, grid: { display: false }, border: { display: false } },
        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: palette.grid }, border: { display: false } },
    },
};
</script>

<template>
    <Bar :data="data" :options="options" aria-label="Generations per day" role="img" />
</template>
