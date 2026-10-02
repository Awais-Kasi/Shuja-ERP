import type { Auth } from '@/types/auth';
import type { Flash, Tenant } from '@/types/tenant';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            tenant: Tenant;
            flash: Flash;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
