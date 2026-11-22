<script setup lang="ts">
import { palette } from '@/lib/charts';
import type { ChartData, ChartOptions } from 'chart.js';
import { computed } from 'vue';
import { Doughnut } from 'vue-chartjs';

const props = defineProps<{ succeeded: number; failed: number; pending: number }>();

const data = computed<ChartData<'doughnut'>>(() => ({
    labels: ['Succeeded', 'Failed', 'In progress'],
    datasets: [
        {
            data: [props.succeeded, props.failed, props.pending],
            backgroundColor: [palette.brand, palette.danger, palette.brandSoft],
            borderWidth: 0,
            spacing: 2,
        },
    ],
}));

const options: ChartOptions<'doughnut'> = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '72%',
    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 6, padding: 16 } } },
};
</script>

<template>
    <Doughnut :data="data" :options="options" aria-label="Generations by status" role="img" />
</template>
