export interface AuthUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    credit_balance: string;
}

export interface SharedProps {
    app: { name: string };
    auth: { user: AuthUser | null };
    flash: {
        success: string | null;
        error: string | null;
        status: string | null;
    };
    [key: string]: unknown;
}
