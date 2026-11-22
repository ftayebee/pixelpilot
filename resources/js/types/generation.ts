export type GenerationStatus = 'submitting' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface GenerationSummary {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: GenerationStatus;
    image_url: string | null;
    estimated_charge_min: string;
    estimated_charge_max: string;
    actual_charge: string | null;
    created_at: string;
    completed_at: string | null;
}

export interface DailyPoint {
    date: string;
    succeeded: number;
    failed: number;
    pending: number;
    spent: string;
}

export interface OverviewStats {
    totals: {
        total: number;
        succeeded: number;
        failed: number;
        pending: number;
        spent: string;
        success_rate: number | null;
    };
    daily: DailyPoint[];
    recent: GenerationSummary[];
}
