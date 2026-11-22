import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(ArcElement, BarElement, CategoryScale, Filler, Legend, LinearScale, LineElement, PointElement, Tooltip);

Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.color = '#77788C';
Chart.defaults.plugins.tooltip.backgroundColor = '#17152F';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 10;
Chart.defaults.plugins.tooltip.boxPadding = 4;

export const palette = {
    brand: '#673DE6',
    brandSoft: '#C9A7FF',
    success: '#16A36A',
    danger: '#DC5454',
    warning: '#F59E0B',
    grid: '#E9EAF2',
};

export function shortDay(iso: string): string {
    return new Date(`${iso}T00:00:00Z`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' });
}
