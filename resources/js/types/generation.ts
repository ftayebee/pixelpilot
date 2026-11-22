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

export interface Estimate {
    tokens: Record<string, { min: number; max: number }>;
    rates: Record<string, string>;
    cost_min: string;
    cost_max: string;
    charge_min: string;
    charge_max: string;
    markup: string;
}

export interface GenerationDetail extends GenerationSummary {
    reference_images: string[];
    provider_id: string | null;
    image_url_expires_at: string | null;
    image_expired: boolean;
    error: { code: string; message: string | null } | null;
    estimate: {
        cost_min: string;
        cost_max: string;
        charge_min: string;
        charge_max: string;
        rates: Record<string, string>;
    };
    usage: Record<string, number> | null;
    rates: Record<string, string> | null;
    actual_cost: string | null;
    started_at: string | null;
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
