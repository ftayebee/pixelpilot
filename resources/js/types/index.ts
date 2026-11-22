export interface AuthUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
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
