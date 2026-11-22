export const sizeLabels: Record<string, { label: string; ratio: string }> = {
    '1024x1024': { label: 'Square', ratio: '1:1' },
    '1536x1024': { label: 'Landscape', ratio: '3:2' },
    '1024x1536': { label: 'Portrait', ratio: '2:3' },
};

export const qualityLabels: Record<string, { label: string; hint: string }> = {
    low: { label: 'Low', hint: 'Quick drafts. Cheapest.' },
    medium: { label: 'Medium', hint: 'Good balance for most images.' },
    high: { label: 'High', hint: 'Most detail. Costs the most.' },
};

export const tokenLabels: Record<string, string> = {
    input_text_tokens: 'Prompt text',
    input_image_tokens: 'Reference images',
    output_tokens: 'Image output',
    reasoning_tokens: 'Reasoning',
};

export function sizeLabel(size: string): string {
    return sizeLabels[size] ? `${sizeLabels[size].label} · ${size.replace('x', '×')}` : size;
}

export function qualityLabel(quality: string): string {
    return qualityLabels[quality]?.label ?? quality;
}

/** Unicode characters, matching how Pixelforge and the server count them. */
export function characterCount(text: string): number {
    return [...text.trim()].length;
}

export function isHttpUrl(value: string): boolean {
    try {
        const url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}
