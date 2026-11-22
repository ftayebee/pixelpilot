import { reactive } from 'vue';

export type ToastKind = 'success' | 'error' | 'info';

export interface Toast {
    id: number;
    kind: ToastKind;
    message: string;
}

const toasts = reactive<Toast[]>([]);
let nextId = 1;

function dismiss(id: number) {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

function push(kind: ToastKind, message: string, timeout = 5000) {
    const id = nextId++;
    toasts.push({ id, kind, message });
    if (toasts.length > 4) toasts.shift();
    if (timeout > 0) window.setTimeout(() => dismiss(id), timeout);
}

export function useToast() {
    return {
        toasts,
        dismiss,
        success: (message: string) => push('success', message),
        error: (message: string) => push('error', message, 8000),
        info: (message: string) => push('info', message),
    };
}
